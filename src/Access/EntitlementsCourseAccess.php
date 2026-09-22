<?php

namespace Goldnead\Courses\Access;

use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Entitlements\EntitlementManager;

/**
 * Asks statamic-entitlements whether the learner holds the course's product.
 *
 * Bundles are entitlements' business: a package that includes this product is
 * resolved there by its PackageResolver, not here.
 */
class EntitlementsCourseAccess implements CourseAccess
{
    public function __construct(private readonly EntitlementManager $entitlements) {}

    public function allows(mixed $user, array $course): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->entitlements->allows($user, $course['product']);
    }
}
