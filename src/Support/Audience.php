<?php

namespace Goldnead\Courses\Support;

use Goldnead\Courses\Contracts\CourseAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Who may see a lesson or a section (K3).
 *
 * A rule lists entitlements (product slugs), LeadHub tags, LeadHub segments
 * and Statamic user groups. Empty lists everywhere: everybody. Otherwise one
 * match is enough, across all four lists. A lesson is visible when its own
 * rule and its section's rule (on the course, `section_audiences`) both match.
 *
 * Each kind is optional: entitlements are asked through the bound
 * CourseAccess, tags and segments of statamic-leadhub when it is installed.
 * A rule naming a kind that cannot be asked does not match, so a lesson meant
 * for a segment stays hidden rather than opening to everybody.
 */
class Audience
{
    public const LEADHUB = 'Goldnead\\Leadhub\\LeadHubManager';

    /** @var array<string, array<string, mixed>|false> contacts by email, per request */
    protected array $contacts = [];

    public function __construct(protected CourseAccess $access) {}

    /**
     * @param  array<string, mixed>  $course
     * @param  Collection<int, array<string, mixed>>  $lessons
     * @return Collection<int, array<string, mixed>>
     */
    public function visibleLessons(mixed $learner, array $course, Collection $lessons): Collection
    {
        $sections = is_array($course['section_audiences'] ?? null) ? $course['section_audiences'] : [];

        $restricted = $sections !== [] || $lessons->contains(fn (array $lesson): bool => ! self::isOpen($lesson['audience'] ?? []));

        if (! $restricted) {
            return $lessons;
        }

        // Asked once per read, never kept across reads: a tag added a minute
        // ago must count on the next page load.
        $this->contacts = [];
        $sectionAllowed = [];

        return $lessons
            ->filter(function (array $lesson) use ($learner, $sections, &$sectionAllowed): bool {
                $key = (string) ($lesson['section_key'] ?? '');

                if (isset($sections[$key])) {
                    $sectionAllowed[$key] ??= $this->matches($learner, $sections[$key]);

                    if (! $sectionAllowed[$key]) {
                        return false;
                    }
                }

                return $this->matches($learner, $lesson['audience'] ?? []);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function matches(mixed $learner, array $rule): bool
    {
        if (self::isOpen($rule)) {
            return true;
        }

        foreach ($rule['entitlements'] ?? [] as $product) {
            if ($this->holds($learner, (string) $product)) {
                return true;
            }
        }

        foreach ($rule['groups'] ?? [] as $group) {
            if ($this->inGroup($learner, (string) $group)) {
                return true;
            }
        }

        if (($rule['tags'] ?? []) !== [] || ($rule['segments'] ?? []) !== []) {
            $contact = $this->contact($learner);

            if ($contact !== null) {
                $tags = collect($contact['tags'] ?? [])->flatMap(fn ($tag): array => [mb_strtolower((string) $tag), Str::slug((string) $tag)])->all();

                foreach ($rule['tags'] ?? [] as $tag) {
                    if (in_array(mb_strtolower((string) $tag), $tags, true)) {
                        return true;
                    }
                }

                foreach ($rule['segments'] ?? [] as $segment) {
                    if ($this->inSegment($contact, (string) $segment)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public static function isOpen(array $rule): bool
    {
        foreach (['entitlements', 'tags', 'segments', 'groups'] as $kind) {
            if (($rule[$kind] ?? []) !== []) {
                return false;
            }
        }

        return true;
    }

    protected function holds(mixed $learner, string $product): bool
    {
        try {
            return $learner !== null && $this->access->allows($learner, ['id' => '', 'slug' => $product, 'product' => $product, 'bundles' => []]);
        } catch (Throwable) {
            return false;
        }
    }

    protected function inGroup(mixed $learner, string $group): bool
    {
        $user = Learner::user($learner);

        return $user !== null && $user->isInGroup($group);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function contact(mixed $learner): ?array
    {
        $email = Learner::email($learner);

        if ($email === null || ! class_exists(self::LEADHUB)) {
            return null;
        }

        if (! array_key_exists($email, $this->contacts)) {
            try {
                $found = app(self::LEADHUB)->findByEmail($email);
            } catch (Throwable) {
                $found = null;
            }

            $this->contacts[$email] = is_array($found) ? $found : false;
        }

        return $this->contacts[$email] ?: null;
    }

    /**
     * @param  array<string, mixed>  $contact
     */
    protected function inSegment(array $contact, string $segment): bool
    {
        try {
            return (bool) app(self::LEADHUB)->contactInSegment($contact['uuid'] ?? $contact['id'], $segment);
        } catch (Throwable) {
            return false;
        }
    }
}
