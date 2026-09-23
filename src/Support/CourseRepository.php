<?php

namespace Goldnead\Courses\Support;

use Goldnead\Courses\Progress\DripSchedule;
use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Query\Builder;

/**
 * Reads a course and its lessons out of the two collections and flattens them
 * into plain arrays, the shape every calculation in this package works on.
 *
 * Extracted from adriangoldner.com's StatamicMemberContentService
 * (getCourseByResourceSlug, getCourseLessonsByResourceSlug,
 * normalizeLessonItemFields, parseDurationSeconds). What was left behind is
 * listed in docs/EXTRACTION.md.
 */
class CourseRepository
{
    /**
     * The lesson kinds the shipped blueprint offers, as the source site had
     * them. Not a whitelist: any other type a site adds is kept as it is, and
     * only a missing one reads as `video`, which is what the source did for the
     * lessons it imported before types existed.
     */
    public const ITEM_TYPES = ['video', 'text', 'quiz', 'assignment', 'reflection', 'milestone', 'exercise', 'coaching'];

    public const SEQUENCING_MODES = ['none', 'section', 'lesson'];

    public const DRIP_MODES = DripSchedule::MODES;

    /**
     * What a failed subscription payment does to a course: nothing until the
     * paid period ends (`keep`), stop the drip (`pause_drip`), or shut the
     * course until the money arrives (`revoke`).
     */
    public const PAYMENT_FAILURE_MODES = ['keep', 'pause_drip', 'revoke'];

    public function coursesCollection(): string
    {
        return (string) config('courses.collections.courses', 'courses');
    }

    public function lessonsCollection(): string
    {
        return (string) config('courses.collections.lessons', 'course_lessons');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCourse(string $slug): ?array
    {
        $entry = $this->entries()
            ->where('collection', $this->coursesCollection())
            ->where('slug', $slug)
            ->first();

        return $entry instanceof StatamicEntry ? $this->normalizeCourse($entry) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allCourses(): array
    {
        return $this->entries()
            ->where('collection', $this->coursesCollection())
            ->orderBy('title')
            ->get()
            ->filter(fn ($entry): bool => $entry instanceof StatamicEntry)
            ->map(fn (StatamicEntry $entry): array => $this->normalizeCourse($entry))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeCourse(StatamicEntry $entry): array
    {
        $product = trim((string) ($entry->get('product') ?? ''));

        return [
            'id' => (string) $entry->id(),
            'slug' => (string) $entry->slug(),
            'title' => (string) $entry->get('title'),
            'summary' => (string) ($entry->get('summary') ?? ''),
            // The entitlements product that opens this course. Falls back to the
            // course slug, so a site that names its products after its courses
            // has nothing to fill in.
            'product' => $product !== '' ? $product : (string) $entry->slug(),
            'sequencing_mode' => $this->oneOf($entry->get('sequencing_mode'), self::SEQUENCING_MODES, 'none'),
            'drip_mode' => $this->oneOf($entry->get('drip_mode'), self::DRIP_MODES, 'none'),
            'drip_day_of_month' => max(1, min(31, (int) ($entry->get('drip_day_of_month') ?? 1))),
            'on_payment_failure' => $this->oneOf($entry->get('on_payment_failure'), self::PAYMENT_FAILURE_MODES, 'keep'),
            // Further products that open this course: a bundle sold as one
            // product lists itself on every course it contains.
            'bundles' => array_values(array_diff($this->strings($entry->get('bundles')), [$product !== '' ? $product : (string) $entry->slug()])),
            'team_seats' => max(0, (int) ($entry->get('team_seats') ?? 0)),
            // Multi-brand sites: the `brand` field, else the brand of the entry's site.
            'brand_id' => CourseBrand::ofEntry($entry),
            'section_audiences' => collect(is_array($entry->get('section_audiences')) ? $entry->get('section_audiences') : [])
                ->filter(fn ($row): bool => is_array($row) && trim((string) ($row['section_key'] ?? '')) !== '')
                ->mapWithKeys(fn (array $row): array => [
                    trim((string) $row['section_key']) => $this->audience($row['entitlements'] ?? null, $row['tags'] ?? null, $row['segments'] ?? null, $row['groups'] ?? null),
                ])
                ->all(),
            // Null when the collection has no route, as on the source site.
            'url' => $entry->url(),
        ];
    }

    /**
     * The lessons of a course, in reading order: section, then position.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function lessonsFor(string $courseId): Collection
    {
        // Asked of the index, not filtered in PHP. `course` is stored as a
        // bare id with max_items 1; the whereJsonContains leg catches entries
        // saved as a one-element list, and courseIdOf() keeps both honest.
        $entries = $this->entries()
            ->where('collection', $this->lessonsCollection())
            ->where(fn ($query) => $query
                ->where('course', $courseId)
                ->orWhereJsonContains('course', $courseId))
            ->get()
            ->filter(fn ($entry): bool => $entry instanceof StatamicEntry && $this->courseIdOf($entry) === $courseId)
            ->values();

        $slugById = $entries->mapWithKeys(fn (StatamicEntry $entry): array => [(string) $entry->id() => (string) $entry->slug()]);

        return $entries
            ->map(fn (StatamicEntry $entry): array => $this->normalizeLesson($entry, $slugById))
            ->sortBy([
                ['section_order', 'asc'],
                ['sort_order', 'asc'],
            ])
            ->values();
    }

    /**
     * @param  Collection<string, string>  $slugById
     * @return array<string, mixed>
     */
    protected function normalizeLesson(StatamicEntry $entry, Collection $slugById): array
    {
        $prerequisiteSlugs = collect($this->ids($entry->get('prerequisite_lessons')))
            ->map(fn (string $id): ?string => $slugById->get($id))
            ->filter()
            ->values()
            ->all();

        return [
            'id' => (string) $entry->id(),
            'slug' => (string) $entry->slug(),
            'title' => (string) $entry->get('title'),
            'url' => $entry->url(),
            'section_key' => (string) ($entry->get('section_key') ?? ''),
            'section_title' => (string) ($entry->get('section_title') ?? ''),
            'section_order' => (int) ($entry->get('section_order') ?? 0),
            'sort_order' => (int) ($entry->get('sort_order') ?? 0),
            'item_type' => $this->stringOrNull($entry->get('item_type')) ?? 'video',
            'is_test_out' => (bool) $entry->get('is_test_out'),
            'video_duration_seconds' => self::parseDurationSeconds($entry->get('video_duration')),
            'est_minutes' => $this->intOrNull($entry->get('est_minutes')),
            'phase_key' => $this->stringOrNull($entry->get('phase_key')),
            'phase_title' => $this->stringOrNull($entry->get('phase_title')),
            'phase_order' => $this->intOrNull($entry->get('phase_order')),
            'week' => $this->intOrNull($entry->get('week')),
            'drip_after' => $this->intOrNull($entry->get('drip_after')),
            'drip_date' => $this->dateOrNull($entry->get('drip_date')),
            'prerequisite_slugs' => $prerequisiteSlugs,
            'audience' => $this->audience($entry->get('audience_entitlements'), $entry->get('audience_tags'), $entry->get('audience_segments'), $entry->get('audience_groups')),
            'assessment' => $this->stringOrNull($entry->get('assessment')),
            // Stored under assessment_* handles: adriangoldner.com keeps its own
            // `pass_score` (a percentage, default 70) on the same lessons.
            'pass_score' => $this->intOrNull($entry->get('assessment_min_score')),
            'pass_levels' => $this->strings($entry->get('assessment_pass_levels')),
        ];
    }

    /**
     * Who may see a lesson or a section. Empty lists everywhere: everybody.
     *
     * @return array{entitlements: list<string>, tags: list<string>, segments: list<string>, groups: list<string>}
     */
    protected function audience(mixed $entitlements, mixed $tags, mixed $segments, mixed $groups): array
    {
        return [
            'entitlements' => $this->strings($entitlements),
            'tags' => $this->strings($tags),
            'segments' => $this->strings($segments),
            'groups' => $this->strings($groups),
        ];
    }

    /**
     * A list field (taggable, list, checkboxes) or a comma-separated string,
     * as trimmed, non-empty strings.
     *
     * @return list<string>
     */
    protected function strings(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($item): string => is_scalar($item) ? trim((string) $item) : '', $value),
            fn (string $item): bool => $item !== '',
        )));
    }

    /**
     * A date field's value as `Y-m-d`, whatever shape it was stored in.
     */
    protected function dateOrNull(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            $value = $value['date'] ?? $value['start'] ?? null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}/', trim($value), $m) === 1 ? $m[0] : null;
    }

    /**
     * "mm:ss", "hh:mm:ss" or plain seconds. Anything else is unknown, not zero:
     * a lesson of unknown length cannot complete itself by being watched.
     */
    public static function parseDurationSeconds(mixed $value): ?int
    {
        if (is_int($value)) {
            return max($value, 0);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $parts = array_map('intval', explode(':', $value));

        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * The entry query builder. The facade's contract declares no methods, so
     * the concrete builder is named here for static analysis; at runtime it is
     * whatever driver the site uses (Stache or Eloquent).
     *
     * @return Builder
     */
    protected function entries(): mixed
    {
        /** @var Builder $query */
        $query = Entry::query();

        return $query;
    }

    protected function courseIdOf(StatamicEntry $lesson): ?string
    {
        return $this->ids($lesson->value('course'))[0] ?? null;
    }

    /**
     * An entries field stores one id or a list of them, depending on max_items.
     *
     * @return list<string>
     */
    protected function ids(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($id) => is_scalar($id) ? (string) $id : null,
            $value,
        ), fn (?string $id): bool => $id !== null && $id !== ''));
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function oneOf(mixed $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    protected function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }
}
