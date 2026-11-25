<?php

namespace Goldnead\Courses\Access;

use Goldnead\Courses\Contracts\CourseAccess;
use Illuminate\Support\Facades\Log;

/**
 * The answer when nothing is installed that could give a better one: no.
 *
 * Failing closed is the point. A site that forgot to install entitlements
 * should find its courses shut, not handed out for free.
 */
class ClosedCourseAccess implements CourseAccess
{
    private static bool $warned = false;

    /** For tests: allow the one-time warning to fire again. */
    public static function resetWarning(): void
    {
        self::$warned = false;
    }

    public function allows(mixed $user, array $course): bool
    {
        // Closed is right, but a site that sees every course shut should be
        // told why: once per process, not once per check.
        if (! self::$warned) {
            self::$warned = true;
            Log::warning('statamic-courses: no CourseAccess bound and statamic-entitlements is not installed; every course is closed.');
        }

        return false;
    }
}
