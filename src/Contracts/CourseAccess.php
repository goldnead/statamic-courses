<?php

namespace Goldnead\Courses\Contracts;

use Goldnead\Courses\Access\ClosedCourseAccess;
use Goldnead\Courses\Access\EntitlementsCourseAccess;

/**
 * Who may open a course at all.
 *
 * This package decides what a learner may open *next*; whether they may be in
 * the course in the first place is somebody else's question. Bound to
 * {@see EntitlementsCourseAccess} when
 * statamic-entitlements is installed and to
 * {@see ClosedCourseAccess} otherwise. Bind your own
 * to answer it differently.
 */
interface CourseAccess
{
    /**
     * @param  mixed  $user  a Statamic user, an Authenticatable, or whatever the bound implementation accepts
     * @param  array{id: string, slug: string, product: string}  $course
     */
    public function allows(mixed $user, array $course): bool;
}
