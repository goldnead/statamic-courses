<?php

namespace Goldnead\Courses\Access;

use Goldnead\Courses\Contracts\CourseAccess;

/**
 * The answer when nothing is installed that could give a better one: no.
 *
 * Failing closed is the point. A site that forgot to install entitlements
 * should find its courses shut, not handed out for free.
 */
class ClosedCourseAccess implements CourseAccess
{
    public function allows(mixed $user, array $course): bool
    {
        return false;
    }
}
