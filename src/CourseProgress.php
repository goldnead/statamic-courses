<?php

namespace Goldnead\Courses;

use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Enums\LessonStatus;
use Goldnead\Courses\Events\CourseCompleted;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Progress\LessonProgress;
use Goldnead\Courses\Progress\LockResolver;
use Goldnead\Courses\Support\CourseRepository;
use Goldnead\Courses\Support\EventRecorder;
use Goldnead\Courses\Support\LearnerId;
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

    public function __construct(
        protected CourseRepository $courses,
        protected LockResolver $locks,
        protected EventRecorder $events,
        protected CourseAccess $access,
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

    public function canAccess(mixed $user, string $courseSlug): bool
    {
        $course = $this->courses->findCourse($courseSlug);

        return $course !== null && $user !== null && $this->access->allows($user, $course);
    }

    /**
     * Starts the clock for the schedule drip. Enrolling twice keeps the first date.
     */
    public function enroll(mixed $user, string $courseSlug): ?Enrollment
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null) {
            return null;
        }

        return Enrollment::query()->createOrFirst(
            ['user_id' => LearnerId::of($user), 'course_entry_id' => $course['id']],
            ['current_week' => 1, 'started_at' => now()],
        );
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

        if ($lesson === null) {
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
     * @return array{user_id: string, course: array<string, mixed>, lessons: Collection<int, array<string, mixed>>, enrollment: Enrollment|null, progress: array<string, array<string, mixed>>, locks: array<string, bool>}|null
     */
    protected function context(mixed $user, string $courseSlug): ?array
    {
        $course = $this->courses->findCourse($courseSlug);

        return $course === null ? null : $this->contextFor(LearnerId::of($user), $course);
    }

    /**
     * @param  array<string, mixed>  $course
     * @return array{user_id: string, course: array<string, mixed>, lessons: Collection<int, array<string, mixed>>, enrollment: Enrollment|null, progress: array<string, array<string, mixed>>, locks: array<string, bool>}
     */
    protected function contextFor(string $userId, array $course): array
    {
        $lessons = $this->courses->lessonsFor($course['id']);

        $states = $lessons->isEmpty() ? collect() : LessonState::query()
            ->where('user_id', $userId)
            ->whereIn('lesson_entry_id', $lessons->pluck('id')->all())
            ->get()
            ->keyBy('lesson_entry_id');

        $progress = $this->locks->applyMilestoneAutoCompletion(
            $lessons,
            $lessons->mapWithKeys(fn (array $lesson): array => [
                $lesson['slug'] => LessonProgress::fromState($lesson, $states->get($lesson['id'])),
            ])->all(),
        );

        $enrollment = $course['drip_mode'] === 'schedule'
            ? Enrollment::query()->where('user_id', $userId)->where('course_entry_id', $course['id'])->first()
            : null;

        return [
            'user_id' => $userId,
            'course' => $course,
            'lessons' => $lessons,
            'enrollment' => $enrollment,
            'progress' => $progress,
            'locks' => $this->locks->lockMap($lessons, $progress, $course['sequencing_mode'], $course['drip_mode'], $enrollment),
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
        $opensAt = $locked && $context['course']['drip_mode'] === 'schedule'
            ? $this->locks->weekOpensAt($context['enrollment'], $lesson['week'])
            : null;
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
            'lock_reason' => $locked ? $this->locks->reason($context['lessons'], $context['progress'], $lesson, $context['course']['sequencing_mode'], $opensAt) : null,
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
        $types = config('courses.proof_required_types', ['quiz', 'assignment']);

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
        $duration = $payload['video_duration_seconds']
            ?? $state->video_duration_seconds
            ?? ($lesson['video_duration_seconds'] ?? null);

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
        $fresh = $this->contextFor($context['user_id'], $context['course']);
        $anyCompleted = false;

        foreach ($written as [$state, $previousStatus]) {
            if ($state->status === LessonStatus::Completed->value && $previousStatus !== LessonStatus::Completed->value) {
                LessonCompleted::dispatch($state, $source);
                $anyCompleted = true;
            }
        }

        if ($anyCompleted && $this->summaryFromContext($fresh)['status'] === LessonStatus::Completed->value) {
            $this->markCourseCompleted($fresh);
        }

        return $this->lessonFromContext($fresh, $lessonSlug);
    }

    /**
     * The enrollment carries the "already announced" flag, so reopening a
     * lesson and completing it again does not complete the course twice.
     *
     * @param  array<string, mixed>  $context
     */
    protected function markCourseCompleted(array $context): void
    {
        $enrollment = Enrollment::query()->createOrFirst(
            ['user_id' => $context['user_id'], 'course_entry_id' => $context['course']['id']],
            ['current_week' => 1, 'started_at' => now()],
        );

        if ($enrollment->completed_at !== null) {
            return;
        }

        $enrollment->completed_at = now();
        $enrollment->save();

        CourseCompleted::dispatch($context['user_id'], $context['course']['id'], $context['course']['slug']);
    }

    protected function threshold(): int
    {
        return (int) config('courses.auto_completion_threshold', 90);
    }
}
