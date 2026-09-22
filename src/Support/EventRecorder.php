<?php

namespace Goldnead\Courses\Support;

use Goldnead\Courses\Enums\LessonStatus;
use Goldnead\Courses\Models\LessonEvent;
use Goldnead\Courses\Models\LessonState;

/**
 * Writes the lesson event log.
 *
 * Extracted from adriangoldner.com's CourseAnalyticsService
 * (recordProgressUpdate, recordManualCompletion, recordEvent). The reporting
 * half of that class read the site's own product collection and stays there.
 *
 * Starts, quarter marks and automatic completions happen once per learner and
 * lesson, and the dedupe key's unique index is what guarantees it. Manual
 * toggles are logged every time: each one is a decision somebody made.
 */
class EventRecorder
{
    /**
     * @param  array{status: string, completion_percent: int, watched_seconds: int}  $previous
     */
    public function progressUpdated(LessonState $state, array $previous, ?string $playbackState = null): void
    {
        $startedBefore = $previous['status'] !== LessonStatus::NotStarted->value || $previous['watched_seconds'] > 0;
        $startedNow = $state->status !== LessonStatus::NotStarted->value || $state->watched_seconds > 0;

        if (! $startedBefore && $startedNow) {
            $this->record($state, 'lesson.started', ['playback_state' => $playbackState], $this->key($state, 'started'));
        }

        foreach ([25, 50, 75] as $mark) {
            if ($previous['completion_percent'] < $mark && $state->completion_percent >= $mark) {
                $this->record($state, 'lesson.progress_'.$mark, ['milestone' => $mark], $this->key($state, 'progress_'.$mark));
            }
        }

        if ($previous['status'] !== LessonStatus::Completed->value
            && $state->status === LessonStatus::Completed->value
            && $state->manual_completion_state === 'none') {
            $this->record($state, 'lesson.completed_auto', ['playback_state' => $playbackState], $this->key($state, 'completed_auto'));
        }
    }

    public function manualCompletion(LessonState $state, bool $completed): void
    {
        $this->record(
            $state,
            $completed ? 'lesson.completed_manual' : 'lesson.reopened_manual',
            ['manual_completion_state' => $state->manual_completion_state],
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function record(LessonState $state, string $type, ?array $payload = null, ?string $dedupeKey = null): void
    {
        if (! config('courses.record_events', true)) {
            return;
        }

        $attributes = [
            'user_id' => $state->user_id,
            'course_entry_id' => $state->course_entry_id,
            'course_slug' => $state->course_slug,
            'lesson_entry_id' => $state->lesson_entry_id,
            'lesson_slug' => $state->lesson_slug,
            'event_type' => $type,
            'progress_percent' => $state->completion_percent,
            'watched_seconds' => $state->watched_seconds,
            'payload' => $payload,
            'occurred_at' => now(),
        ];

        if ($dedupeKey === null) {
            LessonEvent::query()->create($attributes);

            return;
        }

        // Insert first, read on collision: two requests racing on the same mark
        // must end with one row and no exception, which firstOrCreate cannot
        // promise between its select and its insert.
        LessonEvent::query()->createOrFirst(['dedupe_key' => $dedupeKey], $attributes);
    }

    protected function key(LessonState $state, string $event): string
    {
        return sprintf('user:%s:lesson:%s:event:%s', $state->user_id, $state->lesson_entry_id, $event);
    }
}
