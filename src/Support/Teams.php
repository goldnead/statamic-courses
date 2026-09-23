<?php

namespace Goldnead\Courses\Support;

use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Events\TeamMemberRemoved;
use Goldnead\Courses\Models\TeamMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Team access (K6): one buyer, several learners.
 *
 * A team belongs to a purchase: the buyer and the product that opened the
 * course for them, the course's own product or a bundle. Seats are counted
 * once per purchase, and one team covers every course that product opens,
 * with the same members: a bundle of three courses with five seats is five
 * people in all three courses, not fifteen seats.
 *
 * How many seats a product carries: the highest `team_seats` among the
 * courses it opens. A member gets in with their email address for exactly as
 * long as the buyer holds that product (a refund, an expiry or a payment hold
 * of the buyer closes it for the whole team).
 *
 * statamic-entitlements has no seats yet; when it gets them, seatsFor() is
 * the place to ask it. The table stays the record of who sits on which seat.
 */
class Teams
{
    public function __construct(
        protected CourseAccess $access,
        protected Holds $holds,
        protected CourseRepository $courses,
    ) {}

    /**
     * @param  array<string, mixed>  $course
     */
    public function grantsAccess(mixed $learner, array $course): bool
    {
        $email = Learner::email($learner);
        $products = self::productsOf($course);

        if ($email === null || $products === []) {
            return false;
        }

        $rows = TeamMember::query()
            ->where('email', $email)
            ->whereIn('product', $products)
            ->get(['owner_id', 'product']);

        foreach ($rows as $row) {
            if ($this->ownerHolds((string) $row->owner_id, (string) $row->product, $course)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The product through which this buyer holds the course and may hand out
     * seats, or null.
     *
     * @param  array<string, mixed>  $course
     */
    public function purchaseOf(mixed $owner, array $course): ?string
    {
        if ($owner === null || $this->holds->blocks($owner, $course)) {
            return null;
        }

        foreach (self::productsOf($course) as $product) {
            if ($this->seatsFor($product) > 0 && $this->holdsProduct($owner, $product, $course)) {
                return $product;
            }
        }

        return null;
    }

    public function seatsFor(string $product): int
    {
        return (int) collect($this->courses->allCourses())
            ->filter(fn (array $course): bool => in_array($product, self::productsOf($course), true))
            ->max(fn (array $course): int => (int) ($course['team_seats'] ?? 0));
    }

    /**
     * Takes the lowest free seat. Two requests racing for the last seat both
     * try to insert into the same slot; the unique index lets one through,
     * and the other tries the next slot and runs out.
     *
     * @param  array<string, mixed>  $course
     */
    public function add(mixed $owner, array $course, string $email): ?TeamMember
    {
        $email = mb_strtolower(trim($email));
        $product = $this->purchaseOf($owner, $course);

        if ($product === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $email === Learner::email($owner)) {
            return null;
        }

        $ownerId = LearnerId::of($owner);
        $seats = $this->seatsFor($product);
        $existing = $this->members($ownerId, $product)->firstWhere('email', $email);

        if ($existing instanceof TeamMember) {
            return $existing;
        }

        for ($slot = 1; $slot <= $seats; $slot++) {
            try {
                $member = TeamMember::query()->create([
                    'owner_id' => $ownerId,
                    'product' => $product,
                    'email' => $email,
                    'slot' => $slot,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Either this slot is taken, or the same address won a race
                // on another slot. The second is a success, not a refusal.
                $raced = $this->members($ownerId, $product)->firstWhere('email', $email);

                if ($raced instanceof TeamMember) {
                    return $raced;
                }

                continue;
            }

            TeamMemberAdded::dispatch($ownerId, $course['id'], $course['slug'], $email, $product, CourseBrand::forEvent($course));

            return $member;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $course
     */
    public function remove(mixed $owner, array $course, string $email): bool
    {
        $ownerId = LearnerId::of($owner);
        $email = mb_strtolower(trim($email));
        $product = $this->purchaseOf($owner, $course);

        if ($product === null) {
            return false;
        }

        $deleted = TeamMember::query()
            ->where('owner_id', $ownerId)
            ->where('product', $product)
            ->where('email', $email)
            ->delete();

        if ($deleted > 0) {
            TeamMemberRemoved::dispatch($ownerId, $course['id'], $course['slug'], $email, $product, CourseBrand::forEvent($course));
        }

        return $deleted > 0;
    }

    /**
     * @param  array<string, mixed>  $course
     * @return array{product: string, seats: int, used: int, left: int, members: list<array{email: string, added_at: string|null}>}|null
     */
    public function summary(mixed $owner, array $course): ?array
    {
        $product = $this->purchaseOf($owner, $course);

        if ($product === null) {
            return null;
        }

        $members = $this->members(LearnerId::of($owner), $product);
        $seats = $this->seatsFor($product);

        return [
            'product' => $product,
            'seats' => $seats,
            'used' => $members->count(),
            'left' => max(0, $seats - $members->count()),
            'members' => $members->map(fn (TeamMember $member): array => [
                'email' => $member->email,
                'added_at' => $member->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $course
     * @return list<string>
     */
    public static function productsOf(array $course): array
    {
        return array_values(array_unique(array_filter(
            [$course['product'] ?? null, ...($course['bundles'] ?? [])],
            fn ($product): bool => is_string($product) && $product !== '',
        )));
    }

    /**
     * @return Collection<int, TeamMember>
     */
    protected function members(string $ownerId, string $product): Collection
    {
        return TeamMember::query()
            ->where('owner_id', $ownerId)
            ->where('product', $product)
            ->orderBy('slot')
            ->get()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $course
     */
    protected function ownerHolds(string $ownerId, string $product, array $course): bool
    {
        $owner = Learner::user($ownerId) ?? $ownerId;

        return ! $this->holds->blocks($owner, $course) && $this->holdsProduct($owner, $product, $course);
    }

    /**
     * @param  array<string, mixed>  $course
     */
    protected function holdsProduct(mixed $owner, string $product, array $course): bool
    {
        try {
            return $this->access->allows($owner, [...$course, 'product' => $product, 'bundles' => []]);
        } catch (Throwable) {
            return false;
        }
    }
}
