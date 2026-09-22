<?php

namespace Goldnead\Courses\Progress;

use Goldnead\Courses\Enums\LessonStatus;
use Goldnead\Courses\Models\LessonState;

/**
 * The arithmetic of one lesson: how far, which status, where it came from.
 *
 * Extracted from adriangoldner.com's CourseProgressService
 * (defaultLessonProgress, lessonProgressPayload, calculateCompletionPercent,
 * deriveStatus, hasStarted, normalizeManualCompletionState).
 */
class LessonProgress
{
    public const MANUAL_NONE = 'none';

    public const MANUAL_COMPLETED = 'completed';

    public const MANUAL_INCOMPLETE = 'incomplete';

    /**
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    public static function empty(array $lesson): array
    {
        return [
            'status' => LessonStatus::NotStarted->value,
            'completion_percent' => 0,
            'resume_seconds' => 0,
            'watched_seconds' => 0,
            'video_duration_seconds' => $lesson['video_duration_seconds'] ?? null,
            'completed_at' => null,
            'last_activity_at' => null,
            'completion_source' => 'none',
            'item_payload' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @return array<string, mixed>
     */
    public static function fromState(array $lesson, ?LessonState $state): array
    {
        if (! $state instanceof LessonState) {
            return self::empty($lesson);
        }

        $manual = self::normalizeManual($state->manual_completion_state);

        return [
            'status' => (string) $state->status,
            'completion_percent' => (int) $state->completion_percent,
            'resume_seconds' => (int) $state->resume_seconds,
            'watched_seconds' => (int) $state->watched_seconds,
            'video_duration_seconds' => $state->video_duration_seconds ?? ($lesson['video_duration_seconds'] ?? null),
            'completed_at' => $state->completed_at?->toIso8601String(),
            'last_activity_at' => $state->last_activity_at?->toIso8601String(),
            'completion_source' => $manual !== self::MANUAL_NONE
                ? 'manual'
                : ($state->status === LessonStatus::Completed->value ? 'auto' : 'none'),
            'item_payload' => is_array($state->item_payload) ? $state->item_payload : null,
        ];
    }

    public static function completionPercent(int $watchedSeconds, ?int $durationSeconds): int
    {
        if ($durationSeconds === null || $durationSeconds <= 0) {
            return 0;
        }

        return min(100, (int) floor(($watchedSeconds / $durationSeconds) * 100));
    }

    /**
     * A manual decision always wins. Without one, a video completes itself at
     * the threshold; everything else is started or not.
     */
    public static function deriveStatus(
        int $resumeSeconds,
        int $watchedSeconds,
        int $completionPercent,
        ?int $durationSeconds,
        string $manual,
        int $threshold,
    ): LessonStatus {
        if ($manual === self::MANUAL_COMPLETED) {
            return LessonStatus::Completed;
        }

        if ($manual !== self::MANUAL_INCOMPLETE
            && $durationSeconds !== null && $durationSeconds > 0
            && $completionPercent >= $threshold) {
            return LessonStatus::Completed;
        }

        return self::hasStarted($resumeSeconds, $watchedSeconds)
            ? LessonStatus::InProgress
            : LessonStatus::NotStarted;
    }

    public static function hasStarted(int $resumeSeconds, int $watchedSeconds): bool
    {
        return $resumeSeconds > 0 || $watchedSeconds > 0;
    }

    public static function normalizeManual(mixed $value): string
    {
        return in_array($value, [self::MANUAL_NONE, self::MANUAL_COMPLETED, self::MANUAL_INCOMPLETE], true)
            ? (string) $value
            : self::MANUAL_NONE;
    }
}
