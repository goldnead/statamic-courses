<?php

namespace Goldnead\Courses\Access;

use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Support\Learner;
use Goldnead\Courses\Support\LearnerId;
use Goldnead\Entitlements\EntitlementManager;
use Goldnead\Entitlements\Support\StateResolver;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Database\Eloquent\Model;

/**
 * Asks statamic-entitlements whether the learner holds the course's product.
 *
 * Two ways to sell a bundle. On the course: list the bundle product under
 * `bundles`, and a grant for it opens every course that lists it. Or in
 * entitlements: a PackageResolver there expands the bundle, and this class
 * never notices. Both may be used side by side.
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

        foreach ($this->subjects($user) as $subject) {
            // The course's own product, then every bundle that lists the course.
            foreach ([$course['product'], ...($course['bundles'] ?? [])] as $product) {
                if (is_string($product) && $product !== '' && $this->entitlements->allows($subject, $product)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every subject a grant for this learner may sit under: the user (model,
     * reference by id), and the user's email address as statamic-payments
     * writes it when the site has no SubjectResolver that knows the address
     * (`email`, lowercased). Without the second, a buyer on such a site paid
     * and could not open the course.
     *
     * @return list<Model|SubjectReference>
     */
    protected function subjects(mixed $user): array
    {
        $subjects = [$this->subject($user)];
        $email = Learner::email($user);

        if ($email !== null && ! ($subjects[0] instanceof SubjectReference && $subjects[0]->type === self::EMAIL_TYPE)) {
            $subjects[] = new SubjectReference(self::EMAIL_TYPE, $email);
        }

        return $subjects;
    }

    /** The subject type statamic-payments grants under when it only has an address. */
    public const EMAIL_TYPE = 'email';

    /**
     * allows(), leaving out the grants a failed subscription paid for.
     *
     * A grant payments wrote carries source `statamic-payments` and the
     * payment's provider id as its reference. `$excludedRefs` are the
     * references of the subscription whose payment failed; any other live
     * grant for the course's product or bundles (a lifetime grant, a bundle, a
     * second subscription, a grant made by hand) still opens the course.
     *
     * @param  array<string, mixed>  $course
     * @param  list<string>  $excludedRefs
     */
    public function allowsExcept(mixed $user, array $course, array $excludedRefs): bool
    {
        if ($user === null) {
            return false;
        }

        $products = array_values(array_filter(
            [$course['product'] ?? null, ...($course['bundles'] ?? [])],
            fn ($product): bool => is_string($product) && $product !== '',
        ));

        if ($products === []) {
            return false;
        }

        foreach ($this->subjects($user) as $subject) {
            $query = StateResolver::constrainToAccess(
                $this->entitlements->forSubject($subject)->whereIn('product_slug', $products)
            );

            if ($excludedRefs !== []) {
                $query->where(fn ($grant) => $grant
                    ->where('source', '!=', self::PAYMENTS_SOURCE)
                    ->orWhereNotIn('source_ref', $excludedRefs));
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /** What statamic-payments writes as a grant's source. */
    public const PAYMENTS_SOURCE = 'statamic-payments';

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
