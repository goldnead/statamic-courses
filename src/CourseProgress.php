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
        $this->afterWrite($state, $previous['status'], 'auto', $context);

        return $this->lesson($user, $courseSlug, $lessonSlug);
    }

    /**
     * The learner's own "done" or "not done yet". Overrides what watching decided.
     *
     * @return array<string, mixed>|null
     */
    public function setLessonCompletion(mixed $user, string $courseSlug, string $lessonSlug, bool $completed): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        if ($lesson === null) {
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
        $this->afterWrite($state, $previousStatus, 'manual', $context);

        return $this->lesson($user, $courseSlug, $lessonSlug);
    }

    /**
     * "Read it" for lessons without a player: text and milestones. Video lessons
     * are refused, since they complete through {@see updateLessonProgress()} or
     * {@see setLessonCompletion()}.
     *
     * @return array<string, mixed>|null
     */
    public function acknowledgeLesson(mixed $user, string $courseSlug, string $lessonSlug, bool $completed = true): ?array
    {
        [$context, $lesson] = $this->writable($user, $courseSlug, $lessonSlug);

        if ($lesson === null || ! in_array($lesson['item_type'], ['text', 'milestone'], true)) {
            return null;
        }

        $state = $this->stateFor($context, $lesson);
        $previousStatus = (string) ($state->status ?? LessonStatus::NotStarted->value);
        $now = now();

        if ($completed) {
            $status = LessonStatus::Completed;
            $manual = LessonProgress::MANUAL_COMPLETED;
        } elseif ($previousStatus !== LessonStatus::NotStarted->value) {
            $status = LessonStatus::InProgress;
            $manual = LessonProgress::MANUAL_INCOMPLETE;
        } else {
            $status = LessonStatus::InProgress;
            $manual = LessonProgress::MANUAL_NONE;
        }

        $state->fill([
            ...$this->identity($context, $lesson),
            'status' => $status->value,
            'completion_percent' => $completed ? 100 : (int) ($state->completion_percent ?? 0),
            'manual_completion_state' => $manual,
            'first_started_at' => $state->first_started_at ?? $now,
            'last_activity_at' => $now,
            'completed_at' => $completed ? ($state->completed_at ?? $now) : null,
        ]);
        $state->save();

        if ($completed && $previousStatus !== LessonStatus::Completed->value) {
            $this->events->manualCompletion($state, true);
        }

        $this->afterWrite($state, $previousStatus, 'manual', $context);

        return $this->lesson($user, $courseSlug, $lessonSlug);
    }

    // ---------------------------------------------------------------------

    /**
     * Everything a read needs, loaded once: course, lessons, the learner's
     * states, the progress map and the lock map.
     *
     * @return array{user_id: string, course: array<string, mixed>, lessons: Collection<int, array<string, mixed>>, enrollment: Enrollment|null, progress: array<string, array<string, mixed>>, locks: array<string, bool>}|null
     */
    protected function context(mixed $user, string $courseSlug): ?array
    {
        $course = $this->courses->findCourse($courseSlug);

        if ($course === null) {
            return null;
        }

        $userId = LearnerId::of($user);
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

        return [
            'slug' => $lesson['slug'],
            'title' => $lesson['title'],
            'sort_order' => $lesson['sort_order'],
            'url' => $lesson['url'],
            'item_type' => $lesson['item_type'],
            'progress' => $context['progress'][$lesson['slug']] ?? LessonProgress::empty($lesson),
            'is_locked' => $locked,
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
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $lesson
     */
    protected function stateFor(array $context, array $lesson): LessonState
    {
        return LessonState::query()->firstOrNew([
            'user_id' => $context['user_id'],
            'lesson_entry_id' => $lesson['id'],
        ]);
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
     * Fires the completion events on the transition, never on a repeat save.
     *
     * @param  array<string, mixed>  $context
     */
    protected function afterWrite(LessonState $state, string $previousStatus, string $source, array $context): void
    {
        if ($state->status !== LessonStatus::Completed->value || $previousStatus === LessonStatus::Completed->value) {
            return;
        }

        LessonCompleted::dispatch($state, $source);

        $fresh = $this->context($context['user_id'], $context['course']['slug']);

        if ($fresh === null || $this->summaryFromContext($fresh)['status'] !== LessonStatus::Completed->value) {
            return;
        }

        // The enrollment carries the "already announced" flag, so reopening a
        // lesson and completing it again does not complete the course twice.
        $enrollment = Enrollment::query()->createOrFirst(
            ['user_id' => $context['user_id'], 'course_entry_id' => $context['course']['id']],
            ['current_week' => 1, 'started_at' => $state->first_started_at ?? now()],
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
