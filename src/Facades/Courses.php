<?php

namespace Goldnead\Courses\Facades;

use Goldnead\Courses\CourseProgress;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array<string, mixed>|null course(string $courseSlug)
 * @method static bool canAccess(mixed $user, string $courseSlug)
 * @method static \Goldnead\Courses\Models\Enrollment|null enroll(mixed $user, string $courseSlug)
 * @method static \Goldnead\Courses\Models\Enrollment|null advanceToWeek(mixed $user, string $courseSlug, int $week)
 * @method static array<string, mixed>|null outline(mixed $user, string $courseSlug)
 * @method static array<string, mixed>|null summary(mixed $user, string $courseSlug)
 * @method static array<string, mixed>|null lesson(mixed $user, string $courseSlug, string $lessonSlug)
 * @method static bool isLessonLocked(mixed $user, string $courseSlug, string $lessonSlug)
 * @method static array<string, mixed>|null updateLessonProgress(mixed $user, string $courseSlug, string $lessonSlug, array $payload)
 * @method static array<string, mixed>|null setLessonCompletion(mixed $user, string $courseSlug, string $lessonSlug, bool $completed)
 * @method static array<string, mixed>|null acknowledgeLesson(mixed $user, string $courseSlug, string $lessonSlug, bool $completed = true)
 *
 * @see CourseProgress
 */
class Courses extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CourseProgress::class;
    }
}
