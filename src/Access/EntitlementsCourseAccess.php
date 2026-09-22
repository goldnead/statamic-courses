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
 * Entitlements addresses a subject as a (type, id) pair, and the type has to
 * match whatever wrote the grants. How a learner becomes that pair:
 *
 * 1. An Eloquent model or a SubjectReference passes straight through.
 * 2. A Statamic eloquent user (`Statamic\Auth\Eloquent\User`, what
 *    `User::current()` returns on such a site) is unwrapped to its model, so
 *    the model's morph class is used, as a checkout that granted to the model did.
 * 3. Anything else (a flat-file user, a bare id) becomes a reference of the
 *    configured type; unconfigured, the auth model's morph class on an eloquent
 *    install, `user` on a flat-file one.
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

        if (is_object($user) && method_exists($user, 'model')) {
            $model = $user->model();

            if ($model instanceof Model) {
                return $model;
            }
        }

        return new SubjectReference($this->subjectType(), LearnerId::of($user));
    }

    protected function subjectType(): string
    {
        $configured = config('courses.entitlements.subject_type');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $class = config('auth.providers.users.model');

        if (config('statamic.users.repository') === 'eloquent'
            && is_string($class) && is_subclass_of($class, Model::class)) {
            return (new $class)->getMorphClass();
        }

        return 'user';
    }
}
