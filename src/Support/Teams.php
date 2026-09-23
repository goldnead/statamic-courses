<?php

namespace Goldnead\Courses\Support;

use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Events\TeamMemberRemoved;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\TeamMember;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Team access (K6): one buyer, several learners.
 *
 * The course says how many seats one purchase carries (`team_seats`; 0 means
 * no team). The buyer fills them by email. A member gets in with that address
 * for exactly as long as the buyer can open the course: a refund, an expiry
 * or a suspension of the buyer closes it for the whole team.
 *
 * statamic-entitlements has no seats yet. When it gets them, this is the
 * class to hand the count over to; the tables here stay the record of who
 * sits on which seat.
 */
class Teams
{
    public function __construct(protected CourseAccess $access) {}

    /**
     * @param  array<string, mixed>  $course
     */
    public function grantsAccess(mixed $learner, array $course): bool
    {
        if ((int) ($course['team_seats'] ?? 0) <= 0) {
            return false;
        }

        $email = Learner::email($learner);

        if ($email === null) {
            return false;
        }

        $owners = TeamMember::query()
            ->where('course_entry_id', $course['id'])
            ->where('email', $email)
            ->pluck('owner_id');

        foreach ($owners as $ownerId) {
            if ($this->ownerHolds((string) $ownerId, $course)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $course
     */
    public function add(mixed $owner, array $course, string $email): ?TeamMember
    {
        $email = mb_strtolower(trim($email));
        $seats = (int) ($course['team_seats'] ?? 0);

        if ($seats <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $email === Learner::email($owner)) {
            return null;
        }

        $ownerId = LearnerId::of($owner);
        $existing = $this->members($ownerId, $course)->firstWhere('email', $email);

        if ($existing instanceof TeamMember) {
            return $existing;
        }

        if ($this->members($ownerId, $course)->count() >= $seats) {
            return null;
        }

        $member = TeamMember::query()->createOrFirst([
            'owner_id' => $ownerId,
            'course_entry_id' => $course['id'],
            'email' => $email,
        ]);

        if ($member->wasRecentlyCreated) {
            TeamMemberAdded::dispatch($ownerId, $course['id'], $course['slug'], $email);
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>  $course
     */
    public function remove(mixed $owner, array $course, string $email): bool
    {
        $ownerId = LearnerId::of($owner);
        $email = mb_strtolower(trim($email));

        $deleted = TeamMember::query()
            ->where('owner_id', $ownerId)
            ->where('course_entry_id', $course['id'])
            ->where('email', $email)
            ->delete();

        if ($deleted > 0) {
            TeamMemberRemoved::dispatch($ownerId, $course['id'], $course['slug'], $email);
        }

        return $deleted > 0;
    }

    /**
     * @param  array<string, mixed>  $course
     * @return array{seats: int, used: int, left: int, members: list<array{email: string, added_at: string|null}>}
     */
    public function summary(mixed $owner, array $course): array
    {
        $members = $this->members(LearnerId::of($owner), $course);
        $seats = (int) ($course['team_seats'] ?? 0);

        return [
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
     * @return Collection<int, TeamMember>
     */
    protected function members(string $ownerId, array $course): Collection
    {
        return TeamMember::query()
            ->where('owner_id', $ownerId)
            ->where('course_entry_id', $course['id'])
            ->orderBy('id')
            ->get()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $course
     */
    protected function ownerHolds(string $ownerId, array $course): bool
    {
        $suspended = Enrollment::query()
            ->where('user_id', $ownerId)
            ->where('course_entry_id', $course['id'])
            ->whereNotNull('access_suspended_at')
            ->exists();

        if ($suspended) {
            return false;
        }

        try {
            return $this->access->allows(Learner::user($ownerId) ?? $ownerId, $course);
        } catch (Throwable) {
            return false;
        }
    }
}
