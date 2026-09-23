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
 * @method static array<string, mixed>|null completeLesson(mixed $user, string $courseSlug, string $lessonSlug, string $source, ?array $payload = null)
 * @method static array<string, mixed>|null updateLessonItem(mixed $user, string $courseSlug, string $lessonSlug, array $payload)
 * @method static \Goldnead\Courses\Models\Enrollment|null recordBilling(mixed $user, string $courseSlug, int $payments, ?\Carbon\CarbonInterface $trialUntil = null)
 * @method static \Goldnead\Courses\Models\Enrollment|null pauseDrip(mixed $user, string $courseSlug, string $reason = 'manual')
 * @method static \Goldnead\Courses\Models\Enrollment|null resumeDrip(mixed $user, string $courseSlug, string $reason = 'manual')
 * @method static \Goldnead\Courses\Models\Enrollment|null suspendAccess(mixed $user, string $courseSlug, string $reason = 'manual')
 * @method static \Goldnead\Courses\Models\Enrollment|null restoreAccess(mixed $user, string $courseSlug, string $reason = 'manual')
 * @method static string|null paymentFailed(mixed $user, string $courseSlug)
 * @method static void paymentRecovered(mixed $user, string $courseSlug)
 * @method static \Goldnead\Courses\Models\TeamMember|null addTeamMember(mixed $owner, string $courseSlug, string $email)
 * @method static bool removeTeamMember(mixed $owner, string $courseSlug, string $email)
 * @method static array{seats: int, used: int, left: int, members: list<array{email: string, added_at: string|null}>}|null team(mixed $owner, string $courseSlug)
 * @method static list<array<string, mixed>> courses()
 * @method static list<array<string, mixed>>|null lessons(mixed $user, string $courseSlug)
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
