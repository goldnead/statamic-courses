<?php

namespace Goldnead\Courses\Support;

use Goldnead\Courses\Access\EntitlementsCourseAccess;
use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Models\Enrollment;

/**
 * Whether a payment hold (K5, `on_payment_failure: revoke`) keeps a learner
 * out of a course.
 *
 * A hold set by hand (no subscription) shuts the course, full stop. A hold set
 * by a failed subscription only takes away what that subscription paid for:
 * when the bound access is statamic-entitlements, any other live grant for the
 * course's product or bundles (lifetime, bundle, another subscription) keeps
 * it open. With a custom CourseAccess that cannot tell grants apart, the hold
 * shuts the course.
 */
class Holds
{
    public function __construct(protected CourseAccess $access) {}

    public function suspension(mixed $user, string $courseId): ?Enrollment
    {
        return Enrollment::query()
            ->where('user_id', LearnerId::of($user))
            ->where('course_entry_id', $courseId)
            ->whereNotNull('access_suspended_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $course
     */
    public function blocks(mixed $user, array $course): bool
    {
        $hold = $this->suspension($user, $course['id']);

        if ($hold === null) {
            return false;
        }

        if ($hold->suspended_by_subscription_id === null || ! $this->access instanceof EntitlementsCourseAccess) {
            return true;
        }

        return ! $this->access->allowsExcept($user, $course, array_values(array_map('strval', $hold->suspended_grant_refs ?? [])));
    }

    /**
     * Holds the course by its own purchase, a payment hold taken into account.
     *
     * @param  array<string, mixed>  $course
     */
    public function holdsCourse(mixed $user, array $course): bool
    {
        return $user !== null && $this->access->allows($user, $course) && ! $this->blocks($user, $course);
    }
}
