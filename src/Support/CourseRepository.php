<?php

namespace Goldnead\Courses\Support;

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

    public const DRIP_MODES = ['none', 'schedule'];

    public function coursesCollection(): string
    {
        return (string) config('courses.collections.courses', 'courses');
    }

    public function lessonsCollection(): string
    {
        return (string) config('courses.collections.lessons', 'course_lessons');
    }

    /**
     * @return array{id: string, slug: string, title: string, summary: string, product: string, sequencing_mode: string, drip_mode: string, url: string|null}|null
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
     * @return array{id: string, slug: string, title: string, summary: string, product: string, sequencing_mode: string, drip_mode: string, url: string|null}
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
            'prerequisite_slugs' => $prerequisiteSlugs,
        ];
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
