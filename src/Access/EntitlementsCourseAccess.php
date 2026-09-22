<?php

namespace Goldnead\Courses\Access;

use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Support\LearnerId;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Database\Eloquent\Model;

/**
 * Asks statamic-entitlements whether the learner holds the course's product.
 *
 * Bundles are entitlements' business: a package that includes this product is
 * resolved there by its PackageResolver, not here.
 *
 * Entitlements addresses a subject as a (type, id) pair. An Eloquent user and a
 * ready SubjectReference pass straight through, so a morph alias the host
 * registered keeps working. Everything else (a flat-file Statamic user, a bare
 * id) becomes a reference of the configured type, `user` unless set otherwise.
 * That type has to match whatever wrote the grants, which is why it is config.
 */
class EntitlementsCourseAccess implements CourseAccess
{
    public function __construct(private readonly EntitlementManager $entitlements) {}

    public function allows(mixed $user, array $course): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->entitlements->allows($this->subject($user), $course['product']);
    }

    protected function subject(mixed $user): Model|SubjectReference
    {
        if ($user instanceof Model || $user instanceof SubjectReference) {
            return $user;
        }

        return new SubjectReference(
            (string) config('courses.entitlements.subject_type', 'user'),
            LearnerId::of($user),
        );
    }
}
