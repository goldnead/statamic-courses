<?php

namespace Goldnead\Courses;

use Carbon\CarbonInterface;
use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Enums\LessonStatus;
use Goldnead\Courses\Events\CourseAccessRestored;
use Goldnead\Courses\Events\CourseAccessSuspended;
use Goldnead\Courses\Events\CourseCompleted;
use Goldnead\Courses\Events\DripPaused;
use Goldnead\Courses\Events\DripResumed;
use Goldnead\Courses\Events\LearnerEnrolled;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Models\TeamMember;
use Goldnead\Courses\Progress\LessonProgress;
use Goldnead\Courses\Progress\LockResolver;
use Goldnead\Courses\Support\Audience;
use Goldnead\Courses\Support\CourseRepository;
use Goldnead\Courses\Support\EventRecorder;
use Goldnead\Courses\Support\Holds;
use Goldnead\Courses\Support\LearnerId;
use Goldnead\Courses\Support\Teams;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The one entry point: read where a learner stands, record what they did.
 *
 * Extracted from adriangoldner.com's CourseProgressService. Every read method
 * returns null for a course or lesson that does not exist; every write returns
 * null for a lesson that is locked, so a learner cannot complete their way past
 * a gate by calling the endpoint directly.
 *
 * Whether the learner may be in the course at all is *not* checked here, just
 * as the source service did not: that is {@see canAccess()}, and the caller
 * (controller, tag) asks it first.
 */
class CourseProgress
{
    /**
     * Lessons completed by "I've done it": no player, nothing to grade. The
     * list the source site's acknowledgeLesson() accepted.
     */
    public const ACKNOWLEDGEABLE_TYPES = ['text', 'milestone', 'coaching', 'exercise'];

    /** The source of a payment hold whose subscription the caller did not name. */
    public const UNNAMED_PAYMENT = 'payment';

    public function __construct(
        protected CourseRepository $courses,
        protected LockResolver $locks,
        protected EventRecorder $events,
        protected CourseAccess $access,
        protected Audience $audience,
        protected Teams $teams,
        protected Holds $holds,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function course(string $courseSlug): ?array
    {
        return $this->courses->findCourse($courseSlug);
    }

    /**
     * Every course, in title order.
     *
     * @return list<array<string, mixed>>
     */
    public function courses(): array
    {
        return $this->courses->allCourses();
    }

    /**
     * Whether the learner may be in the course at all.
     *
     * Yes when the bound CourseAccess says so for the course's product or any
     * of its `bundles`, or when a buyer put the learner on their team (K6).
     * No, whatever those say, while the course is suspended for this learner
     * after a failed payment (K5, `on_payment_failure: revoke`).
     */
    public function canAccess(mixed $user, string $courseSlug): bool
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null || $user === null) {
            return false;
        }

        // A hold set by hand is absolute: no grant and no team seat gets past it.
        if ($this->holds->isManual($user, $course['id'])) {
            return false;
        }

        return $this->holds->holdsCourse($user, $course)
            || $this->teams->grantsAccess($user, $course);
    }

    /**
     * The learner's payment hold on a course, or null: when it started, which
     * subscription set it, and whether it shuts the course right now (another
     * grant may keep it open).
     *
     * @return array{since: string|null, subscription_id: string|null, blocks: bool}|null
     */
    public function hold(mixed $user, string $courseSlug): ?array
    {
        $course = $this->courses->findCourse($courseSlug);
        $hold = $course === null || $user === null ? null : $this->holds->suspension($user, $course['id']);

        return $hold === null ? null : [
            'since' => $hold->access_suspended_at?->toIso8601String(),
            'subscription_id' => $hold->suspended_by_subscription_id,
            'blocks' => $this->holds->blocks($user, $course),
        ];
    }

    /**
     * Starts the clock for the drip. Enrolling twice keeps the first date.
     * LearnerEnrolled fires once, for the call that created the row.
     */
    public function enroll(mixed $user, string $courseSlug): ?Enrollment
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null) {
            return null;
        }

        $enrollment = Enrollment::query()->createOrFirst(
            ['user_id' => LearnerId::of($user), 'course_entry_id' => $course['id']],
            ['current_week' => 1, 'started_at' => now()],
        );

        if ($enrollment->wasRecentlyCreated) {
            LearnerEnrolled::dispatch($enrollment->user_id, $course['id'], $course['slug']);
        }

        return $enrollment;
    }

    /**
     * What the drip by payments counts (K2): how many payments went through,
     * the first included, and when a trial ends. Written by the payments
     * bridge on every start and renewal; callable by a site that bills
     * elsewhere. The count never goes down.
     */
    public function recordBilling(mixed $user, string $courseSlug, int $payments, ?CarbonInterface $trialUntil = null): ?Enrollment
    {
        return $this->writeEnrollment($user, $courseSlug, 'billing', function (Enrollment $enrollment) use ($payments, $trialUntil): void {
            $enrollment->payments_count = max((int) $enrollment->payments_count, $payments);

            if ($trialUntil !== null) {
                $enrollment->trial_until = Carbon::instance($trialUntil);
            }
        });
    }

    /**
     * Stops the drip clock (K5, `pause_drip`). Pausing a paused drip keeps the
     * first moment.
     */
    public function pauseDrip(mixed $user, string $courseSlug, string $reason = 'manual'): ?Enrollment
    {
        $paused = false;

        $enrollment = $this->writeEnrollment($user, $courseSlug, 'drip_paused', function (Enrollment $enrollment) use (&$paused): void {
            if ($enrollment->drip_paused_at === null) {
                $enrollment->drip_paused_at = now();
                $paused = true;
            }
        });

        if ($paused && $enrollment !== null) {
            DripPaused::dispatch($enrollment->user_id, $enrollment->course_entry_id, $courseSlug, $reason);
        }

        return $enrollment;
    }

    /**
     * Starts the drip clock again. Every lesson relative to the enrollment
     * opens later by as long as the pause lasted.
     */
    public function resumeDrip(mixed $user, string $courseSlug, string $reason = 'manual'): ?Enrollment
    {
        $seconds = null;

        $enrollment = $this->writeEnrollment($user, $courseSlug, 'drip_resumed', function (Enrollment $enrollment) use (&$seconds): void {
            if ($enrollment->drip_paused_at !== null) {
                $seconds = max(0, (int) $enrollment->drip_paused_at->diffInSeconds(now(), true));
                $enrollment->drip_paused_seconds = (int) $enrollment->drip_paused_seconds + $seconds;
                $enrollment->drip_paused_at = null;
            }
        });

        if ($seconds !== null && $enrollment !== null) {
            DripResumed::dispatch($enrollment->user_id, $enrollment->course_entry_id, $courseSlug, $seconds, $reason);
        }

        return $enrollment;
    }

    /**
     * Shuts the course for this learner, whatever their entitlement says
     * (K5, `revoke`). Undone by restoreAccess().
     */
    /**
     * `$subscriptionId` and `$grantRefs` name the subscription whose failed
     * payment set the hold and the payment references of its grants. Given,
     * the hold only takes away what that subscription paid for; without them
     * (a hold set by hand) it shuts the course.
     *
     * @param  list<string>  $grantRefs
     */
    public function suspendAccess(mixed $user, string $courseSlug, string $reason = 'manual', ?string $subscriptionId = null, array $grantRefs = []): ?Enrollment
    {
        $suspended = false;

        $enrollment = $this->writeEnrollment($user, $courseSlug, 'access_suspended', function (Enrollment $enrollment) use (&$suspended, $subscriptionId, $grantRefs): void {
            if ($enrollment->access_suspended_at === null) {
                $enrollment->access_suspended_at = now();
                $enrollment->suspended_by_subscription_id = $subscriptionId;
                $enrollment->suspended_grant_refs = $subscriptionId !== null ? array_values(array_unique($grantRefs)) : null;
                $suspended = true;
            }
        });

        if ($suspended && $enrollment !== null) {
            CourseAccessSuspended::dispatch($enrollment->user_id, $enrollment->course_entry_id, $courseSlug, $reason);
        }

        return $enrollment;
    }

    public function restoreAccess(mixed $user, string $courseSlug, string $reason = 'manual'): ?Enrollment
    {
        $restored = false;

        $enrollment = $this->writeEnrollment($user, $courseSlug, 'access_restored', function (Enrollment $enrollment) use (&$restored): void {
            if ($enrollment->access_suspended_at !== null) {
                $enrollment->access_suspended_at = null;
                $enrollment->suspended_by_subscription_id = null;
                $enrollment->suspended_grant_refs = null;
                $restored = true;
            }
        });

        if ($restored && $enrollment !== null) {
            CourseAccessRestored::dispatch($enrollment->user_id, $enrollment->course_entry_id, $courseSlug, $reason);
        }

        return $enrollment;
    }

    /**
     * A subscription payment for this course failed. Does what the course's
     * `on_payment_failure` says: `keep` nothing (the paid period runs out on
     * its own), `pause_drip` stops the drip, `revoke` shuts the course.
     * Answers the mode that was applied.
     *
     * @param  list<string>  $grantRefs  payment references of the subscription's grants, see suspendAccess()
     */
    public function paymentFailed(mixed $user, string $courseSlug, ?string $subscriptionId = null, array $grantRefs = []): ?string
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null) {
            return null;
        }

        match ($course['on_payment_failure']) {
            'pause_drip' => $this->pauseDrip($user, $courseSlug, 'payment_failed'),
            // Without a subscription id (a site that bills elsewhere) the hold
            // is still a payment hold, so paymentRecovered() can lift it.
            'revoke' => $this->suspendAccess($user, $courseSlug, 'payment_failed', $subscriptionId ?? self::UNNAMED_PAYMENT, $grantRefs),
            default => null,
        };

        return $course['on_payment_failure'];
    }

    /**
     * The money arrived after all: lift whatever paymentFailed() put in place.
     * Both holds are lifted, not only the course's current mode, so a mode
     * changed in between cannot strand a learner.
     *
     * With `$subscriptionId` (a renewal), a hold another subscription set
     * stays: that one has still not been paid. Without it (a new purchase),
     * every payment hold goes. A hold set by hand stays either way.
     */
    public function paymentRecovered(mixed $user, string $courseSlug, ?string $subscriptionId = null, string $reason = 'payment_recovered'): void
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null) {
            return;
        }

        $enrollment = Enrollment::query()
            ->where('user_id', LearnerId::of($user))
            ->where('course_entry_id', $course['id'])
            ->first();

        if ($enrollment?->drip_paused_at !== null) {
            $this->resumeDrip($user, $courseSlug, $reason);
        }

        // A hold set by hand is not a payment matter: only restoreAccess()
        // (or the Control Panel) lifts it, never a renewal or a purchase.
        $source = $enrollment?->suspended_by_subscription_id;
        $lift = $source !== null && ($subscriptionId === null || $source === $subscriptionId);

        if ($enrollment?->access_suspended_at !== null && $lift) {
            $this->restoreAccess($user, $courseSlug, $reason);
        }
    }

    /**
     * Every learner with a payment hold or a paused drip, per course, for the
     * Control Panel.
     *
     * @return list<array{enrollment_id: int, user_id: string, email: string|null, course: string, course_title: string, kind: string, since: string|null, subscription_id: string|null, manual: bool, blocks: bool}>
     */
    public function holds(): array
    {
        $courses = collect($this->courses->allCourses())->keyBy('id');

        return Enrollment::query()
            ->where(fn ($query) => $query->whereNotNull('access_suspended_at')->orWhereNotNull('drip_paused_at'))
            ->orderBy('id')
            ->get()
            ->filter(fn (Enrollment $enrollment): bool => $courses->has($enrollment->course_entry_id))
            ->map(fn (Enrollment $enrollment): array => [
                'enrollment_id' => (int) $enrollment->getKey(),
                'user_id' => $enrollment->user_id,
                'email' => Support\Learner::email($enrollment->user_id),
                'course' => $courses[$enrollment->course_entry_id]['slug'],
                'course_title' => $courses[$enrollment->course_entry_id]['title'],
                'kind' => $enrollment->access_suspended_at !== null ? 'suspended' : 'paused',
                'since' => ($enrollment->access_suspended_at ?? $enrollment->drip_paused_at)?->toIso8601String(),
                'subscription_id' => $enrollment->suspended_by_subscription_id === self::UNNAMED_PAYMENT ? null : $enrollment->suspended_by_subscription_id,
                // Set by hand rather than by a failed payment.
                'manual' => $enrollment->access_suspended_at !== null && $enrollment->suspended_by_subscription_id === null,
                // Whether it shuts the course right now: another purchase may keep it open.
                'blocks' => $enrollment->access_suspended_at !== null
                    && $this->holds->blocks($enrollment->user_id, $courses[$enrollment->course_entry_id]),
            ])
            ->values()
            ->all();
    }

    /**
     * Team access (K6). The owner is the buyer; members are kept by email.
     * Refused (null) when the owner does not hold the course by a purchase
     * with seats, or every seat is taken. One team per purchase: a bundle's
     * team covers every course of the bundle.
     */
    public function addTeamMember(mixed $owner, string $courseSlug, string $email): ?TeamMember
    {
        $course = $this->courses->findCourse($courseSlug);

        return $course === null ? null : $this->teams->add($owner, $course, $email);
    }

    public function removeTeamMember(mixed $owner, string $courseSlug, string $email): bool
    {
        $course = $this->courses->findCourse($courseSlug);

        return $course !== null && $this->teams->remove($owner, $course, $email);
    }

    /**
     * @return array{product: string, seats: int, used: int, left: int, members: list<array{email: string, added_at: string|null}>}|null
     */
    public function team(mixed $owner, string $courseSlug): ?array
    {
        $course = $this->courses->findCourse($courseSlug);

        return $course === null || $owner === null ? null : $this->teams->summary($owner, $course);
    }

    /**
     * Moves a learner on to a week by hand, ahead of the calendar. Never back:
     * a week that was open stays open.
     */
    public function advanceToWeek(mixed $user, string $courseSlug, int $week): ?Enrollment
    {
        $enrollment = $this->enroll($user, $courseSlug);

        if ($enrollment === null) {
            return null;
        }

        if ($week > $enrollment->current_week) {
            $enrollment->current_week = $week;
            $enrollment->save();
        }

        return $enrollment;
    }

    /**
     * The course with its sections, each lesson's progress and lock, and the rollup.
     *
     * @return array<string, mixed>|null
     */
    public function outline(mixed $user, string $courseSlug): ?array
    {
        $context = $this->context($user, $courseSlug);

        if ($context === null) {
            return null;
        }

        return [
            ...$context['course'],
            'progress' => $this->summaryFromContext($context),
            'sections' => $this->sections($context),
        ];
    }

    /**
     * The rollup for many learners of one course, reading the course and its
     * lessons once. Keyed by learner id. For reports, not for requests.
     *
     * @param  iterable<string>  $userIds
     * @return array<string, array<string, mixed>>
     */
    public function summaries(string $courseSlug, iterable $userIds): array
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null) {
            return [];
        }

        $lessons = $this->courses->lessonsFor($course['id']);
        $userIds = collect($userIds)->map(fn ($id): string => (string) $id)->unique()->values();

        // One query for every learner's states and one for their enrollments,
        // however many learners there are. Chunked so a large course stays
        // under the database's bound-parameter limit.
        $states = collect();
        $enrollments = collect();

        foreach ($userIds->chunk(500) as $chunk) {
            if ($lessons->isNotEmpty()) {
                $states = $states->concat(LessonState::query()
                    ->whereIn('user_id', $chunk->all())
                    ->whereIn('lesson_entry_id', $lessons->pluck('id')->all())
                    ->get());
            }

            $enrollments = $enrollments->concat(Enrollment::query()
                ->where('course_entry_id', $course['id'])
                ->whereIn('user_id', $chunk->all())
                ->get());
        }

        $statesByUser = $states->groupBy('user_id');
        $enrollmentByUser = $enrollments->keyBy('user_id');
        $summaries = [];

        foreach ($userIds as $userId) {
            $summaries[$userId] = $this->summaryFromContext($this->contextFor($userId, $course, $lessons, [
                'states' => collect($statesByUser->get($userId, []))->keyBy('lesson_entry_id'),
                'enrollment' => $enrollmentByUser->get($userId),
            ]));
        }

        return $summaries;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(mixed $user, string $courseSlug): ?array
    {
        $context = $this->context($user, $courseSlug);

        return $context === null ? null : $this->summaryFromContext($context);
    }

    /**
     * Every lesson in reading order, flat, each with its progress and lock.
     *
     * @return list<array<string, mixed>>|null
     */
    public function lessons(mixed $user, string $courseSlug): ?array
    {
        $context = $this->context($user, $courseSlug);

        if ($context === null) {
            return null;
        }

        return $context['lessons']
            ->map(fn (array $lesson): array => [...$lesson, ...$this->lessonRow($context, $lesson)])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lesson(mixed $user, string $courseSlug, string $lessonSlug): ?array
    {
        $context = $this->context($user, $courseSlug);

        return $context === null ? null : $this->lessonFromContext($context, $lessonSlug);
    }

    public function isLessonLocked(mixed $user, string $courseSlug, string $lessonSlug): bool
    {
        $context = $this->context($user, $courseSlug);

        return $context !== null && ($context['locks'][$lessonSlug] ?? false);
    }

    /**
     * Playback progress from the player: resume position, seconds watched, and
     * the duration if the player knows it better than the entry does.
     *
     * @param  array{resume_seconds?: int, watched_seconds?: int, video_duration_seconds?: int, playback_state?: string}  $payload
     * @return array<string, mixed>|null
     */
    public function updateLessonProgress(mixed $user, string $courseSlug, string $lessonSlug, array $payload): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        // Only a video has playback. Anything else, and above all a quiz or an
        // assignment, would otherwise complete itself from two client numbers.
        if ($lesson === null || $lesson['item_type'] !== 'video' || $this->needsProof($lesson)) {
            return null;
        }

        $state = $this->stateFor($context, $lesson);

        $previous = [
            'status' => (string) ($state->status ?? LessonStatus::NotStarted->value),
            'completion_percent' => (int) ($state->completion_percent ?? 0),
            'watched_seconds' => (int) ($state->watched_seconds ?? 0),
        ];

        $now = now();
        $resume = max(0, (int) ($payload['resume_seconds'] ?? 0));
        // Watched time only grows: seeking back must not undo what was seen.
        $watched = max($previous['watched_seconds'], max(0, (int) ($payload['watched_seconds'] ?? 0)));
        $duration = $this->durationFor($lesson, $state, $payload);
        $percent = LessonProgress::completionPercent($watched, $duration);
        $manual = LessonProgress::normalizeManual($state->manual_completion_state);
        $status = LessonProgress::deriveStatus($resume, $watched, $percent, $duration, $manual, $this->threshold());
        $started = LessonProgress::hasStarted($resume, $watched);

        $state->fill([
            ...$this->identity($context, $lesson),
            'status' => $status->value,
            'completion_percent' => $percent,
            'resume_seconds' => $resume,
            'watched_seconds' => $watched,
            'video_duration_seconds' => $duration,
            'manual_completion_state' => $manual,
            'first_started_at' => $state->first_started_at ?? ($started ? $now : null),
            'last_activity_at' => $started ? $now : $state->last_activity_at,
            'completed_at' => $status === LessonStatus::Completed ? ($state->completed_at ?? $now) : null,
        ]);
        $state->save();

        $this->events->progressUpdated($state, $previous, isset($payload['playback_state']) ? (string) $payload['playback_state'] : null);

        return $this->afterWrite([[$state, $previous['status']]], 'auto', $context, $lessonSlug);
    }

    /**
     * The learner's own "done" or "not done yet". Overrides what watching decided.
     *
     * Refused for the lesson types in `courses.proof_required_types` (quiz and
     * assignment by default): those complete through {@see completeLesson()},
     * which the code that graded the proof calls.
     *
     * @return array<string, mixed>|null
     */
    public function setLessonCompletion(mixed $user, string $courseSlug, string $lessonSlug, bool $completed): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        if ($lesson === null || $this->needsProof($lesson)) {
            return null;
        }

        $state = $this->stateFor($context, $lesson);
        $previousStatus = (string) ($state->status ?? LessonStatus::NotStarted->value);

        $now = now();
        $manual = $completed ? LessonProgress::MANUAL_COMPLETED : LessonProgress::MANUAL_INCOMPLETE;
        $resume = max(0, (int) ($state->resume_seconds ?? 0));
        $watched = max(0, (int) ($state->watched_seconds ?? 0));
        $duration = $this->durationFor($lesson, $state, []);
        $percent = $completed ? 100 : LessonProgress::completionPercent($watched, $duration);
        $status = LessonProgress::deriveStatus($resume, $watched, $percent, $duration, $manual, $this->threshold());
        $started = $completed || LessonProgress::hasStarted($resume, $watched);

        $state->fill([
            ...$this->identity($context, $lesson),
            'status' => $status->value,
            'completion_percent' => $percent,
            'resume_seconds' => $resume,
            'watched_seconds' => $watched,
            'video_duration_seconds' => $duration,
            'manual_completion_state' => $manual,
            'first_started_at' => $state->first_started_at ?? ($started ? $now : null),
            'last_activity_at' => $now,
            'completed_at' => $completed ? ($state->completed_at ?? $now) : null,
        ]);
        $state->save();

        $this->events->manualCompletion($state, $completed);

        return $this->afterWrite([[$state, $previousStatus]], 'manual', $context, $lessonSlug);
    }

    /**
     * "Done" for lessons without a player or a grade: text, milestones,
     * coaching, exercises. Other types are refused.
     *
     * @return array<string, mixed>|null
     */
    public function acknowledgeLesson(mixed $user, string $courseSlug, string $lessonSlug, bool $completed = true): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        if ($lesson === null || ! in_array($lesson['item_type'], self::ACKNOWLEDGEABLE_TYPES, true)) {
            return null;
        }

        return $this->afterWrite([$this->writeItemState($context, $lesson, $completed, null)], 'manual', $context, $lessonSlug);
    }

    /**
     * Completes a lesson on the word of the code that checked it: a graded quiz,
     * an accepted assignment, a held coaching session. `$source` names that
     * code and travels on the LessonCompleted event; `$payload` is merged into
     * the lesson's item_payload (score, submission, note).
     *
     * A passed test-out lesson also completes every lesson of the earlier
     * phases, marked `skipped`, so the learner lands behind them.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function completeLesson(mixed $user, string $courseSlug, string $lessonSlug, string $source, ?array $payload = null): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        if ($lesson === null) {
            return null;
        }

        $written = [$this->writeItemState($context, $lesson, true, $payload)];

        if ($lesson['is_test_out'] ?? false) {
            array_push($written, ...$this->skipEarlierPhases($context, $lesson));
        }

        return $this->afterWrite($written, $source, $context, $lessonSlug);
    }

    /**
     * Records work on a lesson that is not done yet: a failed quiz attempt, a
     * reflection draft, an exercise run. Merges `$payload` into item_payload
     * and marks the lesson started. The source's writeItemState() with
     * markInProgress.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function updateLessonItem(mixed $user, string $courseSlug, string $lessonSlug, array $payload): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        if ($lesson === null) {
            return null;
        }

        $written = $this->writeItemState($context, $lesson, false, $payload, markInProgress: true);

        return $this->afterWrite([$written], 'item', $context, $lessonSlug);
    }

    /**
     * Why a write would be refused, as a stable code, or null when it would
     * not be. For callers that got null back from a write and owe somebody
     * an explanation (the POST route answers with it).
     *
     * `$write` is `complete`/`incomplete` (setLessonCompletion), `acknowledge`,
     * `progress` (updateLessonProgress) or `item` (completeLesson,
     * updateLessonItem). Codes: `unknown_course`, `unknown_lesson`, `locked`,
     * `proof_required`, `not_video`, `not_acknowledgeable`.
     */
    public function refusalReason(mixed $user, string $courseSlug, string $lessonSlug, string $write): ?string
    {
        $context = $this->context($user, $courseSlug);

        if ($context === null) {
            return 'unknown_course';
        }

        $lesson = $context['lessons']->firstWhere('slug', $lessonSlug);

        return match (true) {
            ! is_array($lesson) => 'unknown_lesson',
            $context['locks'][$lessonSlug] ?? false => 'locked',
            in_array($write, ['complete', 'incomplete', 'progress'], true) && $this->needsProof($lesson) => 'proof_required',
            $write === 'progress' && $lesson['item_type'] !== 'video' => 'not_video',
            $write === 'acknowledge' && ! in_array($lesson['item_type'], self::ACKNOWLEDGEABLE_TYPES, true) => 'not_acknowledgeable',
            default => null,
        };
    }

    // ---------------------------------------------------------------------

    /**
     * Persists a completion or a "started" for a lesson without a player.
     * From the source's writeItemState().
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $lesson
     * @param  array<string, mixed>|null  $payloadPatch
     * @return array{0: LessonState, 1: string}
     */
    protected function writeItemState(array $context, array $lesson, bool $completed, ?array $payloadPatch, bool $markInProgress = false): array
    {
        $state = $this->stateFor($context, $lesson);
        $previousStatus = (string) ($state->status ?? LessonStatus::NotStarted->value);
        $now = now();

        if ($completed) {
            $status = LessonStatus::Completed;
            $manual = LessonProgress::MANUAL_COMPLETED;
        } elseif ($markInProgress && $previousStatus === LessonStatus::Completed->value) {
            // More work on a finished lesson (a retake) does not reopen it.
            $status = LessonStatus::Completed;
            $manual = LessonProgress::normalizeManual($state->manual_completion_state);
        } elseif ($markInProgress || $previousStatus !== LessonStatus::NotStarted->value) {
            $status = LessonStatus::InProgress;
            $manual = LessonProgress::MANUAL_INCOMPLETE;
        } else {
            $status = LessonStatus::InProgress;
            $manual = LessonProgress::MANUAL_NONE;
        }

        $state->fill([
            ...$this->identity($context, $lesson),
            'status' => $status->value,
            'completion_percent' => $status === LessonStatus::Completed ? 100 : (int) ($state->completion_percent ?? 0),
            'manual_completion_state' => $manual,
            'item_payload' => $payloadPatch !== null
                ? array_merge(is_array($state->item_payload) ? $state->item_payload : [], $payloadPatch)
                : $state->item_payload,
            'first_started_at' => $state->first_started_at ?? $now,
            'last_activity_at' => $now,
            'completed_at' => $status === LessonStatus::Completed ? ($state->completed_at ?? $now) : null,
        ]);
        $state->save();

        if ($completed && $previousStatus !== LessonStatus::Completed->value) {
            $this->events->manualCompletion($state, true);
        }

        return [$state, $previousStatus];
    }

    /**
     * From the source's skipCompleteEarlierPhases().
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $testOut
     * @return list<array{0: LessonState, 1: string}>
     */
    protected function skipEarlierPhases(array $context, array $testOut): array
    {
        $order = $testOut['phase_order'] ?? null;

        if ($order === null) {
            return [];
        }

        $written = [];

        foreach ($context['lessons'] as $lesson) {
            $lessonOrder = $lesson['phase_order'] ?? null;

            if ($lessonOrder === null || $lessonOrder >= $order || ($lesson['is_test_out'] ?? false)) {
                continue;
            }

            if (($context['progress'][$lesson['slug']]['status'] ?? null) === LessonStatus::Completed->value) {
                continue;
            }

            $written[] = $this->writeItemState($context, $lesson, true, ['skipped' => true]);
        }

        return $written;
    }

    /**
     * Everything a read needs, loaded once: course, lessons, the learner's
     * states, the progress map and the lock map.
     *
     * @return array{user_id: string, learner: mixed, course: array<string, mixed>, lessons: Collection<int, array<string, mixed>>, enrollment: Enrollment|null, progress: array<string, array<string, mixed>>, locks: array<string, bool>}|null
     */
    protected function context(mixed $user, string $courseSlug): ?array
    {
        $course = $this->courses->findCourse($courseSlug);

        return $course === null ? null : $this->contextFor(LearnerId::of($user), $course, learner: $user);
    }

    /**
     * Lessons the learner may not see (K3: audience rules on the lesson or its
     * section) are dropped here, before anything is counted: for this learner
     * they are not part of the course, so they neither lock the path nor hold
     * back the completion.
     *
     * @param  array<string, mixed>  $course
     * @param  Collection<int, array<string, mixed>>|null  $lessons  every lesson of the course, before the audience filter
     * @param  array{states: Collection<string, LessonState>, enrollment: Enrollment|null}|null  $preloaded  what a report already loaded for many learners at once
     * @param  mixed  $learner  the user object when the caller has one; the id otherwise
     * @return array{user_id: string, learner: mixed, course: array<string, mixed>, lessons: Collection<int, array<string, mixed>>, enrollment: Enrollment|null, progress: array<string, array<string, mixed>>, locks: array<string, bool>}
     */
    protected function contextFor(string $userId, array $course, ?Collection $lessons = null, ?array $preloaded = null, mixed $learner = null): array
    {
        $lessons ??= $this->courses->lessonsFor($course['id']);
        $lessons = $this->audience->visibleLessons($learner ?? $userId, $course, $lessons);

        $states = $preloaded !== null
            ? $preloaded['states']
            : ($lessons->isEmpty() ? collect() : LessonState::query()
                ->where('user_id', $userId)
                ->whereIn('lesson_entry_id', $lessons->pluck('id')->all())
                ->get()
                ->keyBy('lesson_entry_id'));

        $progress = $this->locks->applyMilestoneAutoCompletion(
            $lessons,
            $lessons->mapWithKeys(fn (array $lesson): array => [
                $lesson['slug'] => LessonProgress::fromState($lesson, $states->get($lesson['id'])),
            ])->all(),
        );

        $enrollment = $preloaded !== null
            ? $preloaded['enrollment']
            : Enrollment::query()->where('user_id', $userId)->where('course_entry_id', $course['id'])->first();

        return [
            'user_id' => $userId,
            'learner' => $learner ?? $userId,
            'course' => $course,
            'lessons' => $lessons,
            'enrollment' => $enrollment,
            'progress' => $progress,
            'locks' => $this->locks->lockMap($lessons, $progress, $course['sequencing_mode'], $course['drip_mode'], $enrollment, null, $course),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function summaryFromContext(array $context): array
    {
        $lessons = $context['lessons'];
        $progress = $context['progress'];
        $locks = $context['locks'];

        $completed = collect($progress)->where('status', LessonStatus::Completed->value)->count();
        $started = collect($progress)->where('status', '!=', LessonStatus::NotStarted->value)->count();
        $total = $lessons->count();

        $continue = $lessons->first(fn (array $lesson): bool => ! ($locks[$lesson['slug']] ?? false)
            && ($progress[$lesson['slug']]['status'] ?? null) !== LessonStatus::Completed->value);

        return [
            'status' => match (true) {
                $total > 0 && $completed >= $total => LessonStatus::Completed->value,
                $started > 0 => LessonStatus::InProgress->value,
                default => LessonStatus::NotStarted->value,
            },
            'total_lessons' => $total,
            'completed_lessons' => $completed,
            'percent' => $total > 0 ? (int) floor(($completed / $total) * 100) : 0,
            'continue_lesson' => is_array($continue) ? [
                'slug' => $continue['slug'],
                'title' => $continue['title'],
                'url' => $continue['url'],
            ] : null,
            'last_activity_at' => collect($progress)->pluck('last_activity_at')->filter()->sort()->last(),
            'sequencing_mode' => $context['course']['sequencing_mode'],
            'drip_mode' => $context['course']['drip_mode'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    protected function sections(array $context): array
    {
        return $context['lessons']
            ->groupBy('section_key')
            ->map(function (Collection $lessons) use ($context): array {
                $first = $lessons->first();

                return [
                    'key' => (string) $first['section_key'],
                    'title' => (string) $first['section_title'],
                    'sort_order' => (int) $first['section_order'],
                    'lessons' => $lessons->sortBy('sort_order')->values()
                        ->map(fn (array $lesson): array => $this->lessonRow($context, $lesson))
                        ->all(),
                ];
            })
            ->sortBy('sort_order')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    protected function lessonFromContext(array $context, string $lessonSlug): ?array
    {
        $lessons = $context['lessons'];
        $index = $lessons->search(fn (array $lesson): bool => $lesson['slug'] === $lessonSlug);

        if ($index === false) {
            return null;
        }

        $neighbour = fn (?array $lesson): ?array => $lesson === null ? null : ['slug' => $lesson['slug'], 'title' => $lesson['title']];

        return [
            ...$lessons[$index],
            ...$this->lessonRow($context, $lessons[$index]),
            'course_slug' => $context['course']['slug'],
            'sequencing_mode' => $context['course']['sequencing_mode'],
            'previous_lesson' => $neighbour($index > 0 ? $lessons[$index - 1] : null),
            'next_lesson' => $neighbour($lessons->get($index + 1)),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    protected function lessonRow(array $context, array $lesson): array
    {
        $locked = $context['locks'][$lesson['slug']] ?? false;
        $drip = $locked && $context['course']['drip_mode'] !== 'none'
            ? $this->locks->dripGate($context['course'], $lesson, $context['enrollment'])
            : ['locked' => false, 'opens_at' => null, 'reason' => null];
        $opensAt = $drip['opens_at'];
        $progress = $context['progress'][$lesson['slug']] ?? LessonProgress::empty($lesson);

        return [
            'slug' => $lesson['slug'],
            'title' => $lesson['title'],
            'sort_order' => $lesson['sort_order'],
            'url' => $lesson['url'],
            'item_type' => $lesson['item_type'],
            'progress' => $progress,
            'is_locked' => $locked,
            'is_completed' => $progress['status'] === LessonStatus::Completed->value,
            // Completed by passing a test-out rather than by doing the lesson.
            'is_skipped' => $progress['status'] === LessonStatus::Completed->value
                && (bool) ($progress['item_payload']['skipped'] ?? false),
            'lock_reason' => $locked ? $this->locks->reason($context['lessons'], $context['progress'], $lesson, $context['course']['sequencing_mode'], $opensAt, $drip['locked'] ? $drip['reason'] : null) : null,
            'available_at' => $opensAt?->toIso8601String(),
        ];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    protected function writable(mixed $user, string $courseSlug, string $lessonSlug): array
    {
        $context = $this->context($user, $courseSlug);

        if ($context === null) {
            return [null, null];
        }

        $lesson = $context['lessons']->firstWhere('slug', $lessonSlug);

        if (! is_array($lesson) || ($context['locks'][$lessonSlug] ?? false)) {
            return [$context, null];
        }

        return [$context, $lesson];
    }

    /**
     * @param  array<string, mixed>  $lesson
     */
    protected function needsProof(array $lesson): bool
    {
        $types = config('courses.proof_required_types', ['quiz', 'assignment', 'reflection']);

        return is_array($types) && in_array($lesson['item_type'], $types, true);
    }

    /**
     * The learner's row for a lesson, created on first touch. Insert first and
     * read on collision, so two first writes racing each other end on one row
     * instead of a unique-index exception.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $lesson
     */
    protected function stateFor(array $context, array $lesson): LessonState
    {
        return LessonState::query()->createOrFirst(
            ['user_id' => $context['user_id'], 'lesson_entry_id' => $lesson['id']],
            [...$this->identity($context, $lesson), 'status' => LessonStatus::NotStarted->value],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    protected function identity(array $context, array $lesson): array
    {
        return [
            'course_entry_id' => $context['course']['id'],
            'course_slug' => $context['course']['slug'],
            'lesson_slug' => $lesson['slug'],
            'section_key' => $lesson['section_key'] ?: null,
            'section_title' => $lesson['section_title'] ?: null,
        ];
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @param  array<string, mixed>  $payload
     */
    protected function durationFor(array $lesson, LessonState $state, array $payload): ?int
    {
        // The entry's duration wins: a client that claims a ten-minute video is
        // ten seconds long must not complete it in ten seconds. The player's
        // number only counts when the entry does not know.
        $duration = ($lesson['video_duration_seconds'] ?? null)
            ?? $payload['video_duration_seconds']
            ?? $state->video_duration_seconds;

        return is_numeric($duration) ? max(1, (int) $duration) : null;
    }

    /**
     * Rebuilds the context once after a write, fires the completion events for
     * every lesson that just turned completed, and answers with the lesson.
     *
     * @param  list<array{0: LessonState, 1: string}>  $written  state and its status before the write
     * @param  array<string, mixed>  $context  the context before the write
     * @return array<string, mixed>|null
     */
    protected function afterWrite(array $written, string $source, array $context, string $lessonSlug): ?array
    {
        $fresh = $this->contextFor($context['user_id'], $context['course'], learner: $context['learner']);
        $anyCompleted = false;

        foreach ($written as [$state, $previousStatus]) {
            if ($state->status === LessonStatus::Completed->value && $previousStatus !== LessonStatus::Completed->value) {
                LessonCompleted::dispatch($state, $source);
                $anyCompleted = true;
            }
        }

        $this->announceUnlocks($context, $fresh, $source);

        if ($anyCompleted && $this->summaryFromContext($fresh)['status'] === LessonStatus::Completed->value) {
            $this->markCourseCompleted($fresh);
        }

        return $this->lessonFromContext($fresh, $lessonSlug);
    }

    /**
     * LessonUnlocked for every lesson this write opened: locked before, open
     * after. Only what a write causes is announced; a lesson the calendar
     * opens overnight has no write to hang on and is not.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function announceUnlocks(array $before, array $after, string $source): void
    {
        foreach ($after['lessons'] as $lesson) {
            $slug = $lesson['slug'];

            if (($before['locks'][$slug] ?? false) && ! ($after['locks'][$slug] ?? false)) {
                LessonUnlocked::dispatch($after['user_id'], $after['course']['id'], $after['course']['slug'], $slug, $source);
            }
        }
    }

    /**
     * Runs a write against the learner's enrollment and announces whatever it
     * unlocked. For the writes that change no lesson, only the clock or the
     * billing (payments, pauses).
     *
     * @param  callable(Enrollment): void  $write
     */
    protected function writeEnrollment(mixed $user, string $courseSlug, string $source, callable $write): ?Enrollment
    {
        $before = $this->context($user, $courseSlug);

        if ($before === null) {
            return null;
        }

        $enrollment = $before['enrollment'] ?? $this->enroll($user, $courseSlug);

        if ($enrollment === null) {
            return null;
        }

        $write($enrollment);

        if ($enrollment->isDirty()) {
            $enrollment->save();
        }

        $this->announceUnlocks($before, $this->contextFor($before['user_id'], $before['course'], learner: $before['learner']), $source);

        return $enrollment;
    }

    /**
     * The enrollment carries the "already announced" flag, so reopening a
     * lesson and completing it again does not complete the course twice.
     * The flag is claimed with one conditional UPDATE, so of two concurrent
     * requests only the one that flips it announces the course.
     *
     * @param  array<string, mixed>  $context
     */
    protected function markCourseCompleted(array $context): void
    {
        $enrollment = Enrollment::query()->createOrFirst(
            ['user_id' => $context['user_id'], 'course_entry_id' => $context['course']['id']],
            ['current_week' => 1, 'started_at' => now()],
        );

        $claimed = Enrollment::query()
            ->whereKey($enrollment->getKey())
            ->whereNull('completed_at')
            ->update(['completed_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        CourseCompleted::dispatch($context['user_id'], $context['course']['id'], $context['course']['slug']);
    }

    protected function threshold(): int
    {
        return (int) config('courses.auto_completion_threshold', 90);
    }
}
