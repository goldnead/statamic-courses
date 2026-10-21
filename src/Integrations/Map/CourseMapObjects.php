<?php

namespace Goldnead\Courses\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapLookup;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Support\CourseRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Query\Builder;

/**
 * Courses as objects the funnels map can point at (brand-context 1.17).
 *
 * The id is the course slug, the key routes and the rest of the addon use.
 * The card's number is the enrollments created in the period: a learner who
 * started the course, counted once per course and learner.
 *
 * Each course also lists its edge to the product that opens it (brand-context
 * 1.18): product -> unlocks -> course.
 *
 * Registered only when statamic-brand-context is installed (ServiceProvider::boot).
 */
class CourseMapObjects implements ProvidesMapEdges, ProvidesMapObjects
{
    use SimulatesCourse;

    public const TYPE = 'course';

    /** Lessons the detail panel lists; the rest is counted in `more`. */
    public const DETAIL_LESSONS = 12;

    public function __construct(protected CourseRepository $courses) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function typeLabel(): string
    {
        return __('courses::map.type_label');
    }

    /**
     * @return array<int, MapObject>
     */
    public function objects(?string $query = null, int $limit = 50): array
    {
        $needle = mb_strtolower(trim((string) $query));

        return $this->entries()
            ->filter(fn (StatamicEntry $entry): bool => $needle === ''
                || str_contains(mb_strtolower((string) $entry->get('title')), $needle)
                || str_contains(mb_strtolower((string) $entry->slug()), $needle))
            ->take(max(0, $limit))
            ->map(fn (StatamicEntry $entry): MapObject => $this->map($entry))
            ->values()
            ->all();
    }

    public function find(string $id): ?MapObject
    {
        $entry = $this->entry($id);

        return $entry instanceof StatamicEntry ? $this->map($entry) : null;
    }

    /**
     * Enrollments of this course whose `created_at` falls in the period, both
     * ends included. Null when no course has that slug.
     */
    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        $entry = $this->entry($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        $started = Enrollment::query()
            ->where('course_entry_id', (string) $entry->id())
            ->whereBetween('created_at', [$period->from, $period->to])
            ->count();

        return new MapMetric($started, __('courses::map.started'), $period);
    }

    /**
     * The detail panel (brand-context 1.19, found by method_exists): the number
     * of lessons as a fact and the lessons themselves as a list. Read only, one
     * lesson query per call. Material entries are no courses, so: null.
     *
     * @return list<array<string, mixed>>|null
     */
    public function details(string $id, MapPeriod $period): ?array
    {
        $entry = $this->entry($id);

        if (! $entry instanceof StatamicEntry) {
            return null;
        }

        $lessons = $this->publishedLessons($this->courses->lessonsFor((string) $entry->id()));

        $blocks = [[
            'kind' => 'facts',
            'title' => null,
            'rows' => [[
                'label' => __('courses::map.lessons'),
                'value' => (string) $lessons->count(),
                'href' => null,
            ]],
        ]];

        if ($lessons->isEmpty()) {
            return $blocks;
        }

        $block = [
            'kind' => 'list',
            'title' => __('courses::map.lessons'),
            'items' => $lessons
                ->take(self::DETAIL_LESSONS)
                ->map(fn (array $lesson): array => [
                    'title' => Str::limit((string) ($lesson['title'] ?: $lesson['slug']), 120),
                    'meta' => ($lesson['section_title'] ?? '') !== '' ? Str::limit((string) $lesson['section_title'], 120) : null,
                    'image' => null,
                ])
                ->values()
                ->all(),
        ];

        if ($lessons->count() > self::DETAIL_LESSONS) {
            $block['more'] = $lessons->count() - self::DETAIL_LESSONS;
        }

        $blocks[] = $block;

        return $blocks;
    }

    /**
     * Whether this user may see the course's detail panel (brand-context 1.20).
     * The panel shows lesson titles, so it asks for what the Control Panel asks
     * for to show the course: view access to the courses collection's entries.
     * No user, no panel.
     */
    public function detailsAuthorized(string $id, $user): bool
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }

        return (bool) $user->can('view '.$this->courses->coursesCollection().' entries');
    }

    /**
     * Drafts are not part of the course a learner sees, so the panel neither
     * lists nor counts them. Filtered here, one query, so CourseRepository
     * stays as other callers expect it.
     *
     * @param  Collection<int, array<string, mixed>>  $lessons
     * @return Collection<int, array<string, mixed>>
     */
    protected function publishedLessons(Collection $lessons): Collection
    {
        if ($lessons->isEmpty()) {
            return $lessons;
        }

        $published = $this->query()
            ->whereIn('id', $lessons->pluck('id')->all())
            ->where('published', true)
            ->get()
            ->map(fn ($lesson): string => (string) $lesson->id())
            ->flip();

        return $lessons->filter(fn (array $lesson): bool => $published->has($lesson['id']))->values();
    }

    /**
     * One edge per course: the product that opens it, unlocks, the course. A
     * course without a product slug has no edge.
     *
     * @return array<int, MapEdge>
     */
    public function edges(): array
    {
        return $this->entries()
            ->map(fn (StatamicEntry $entry): ?MapEdge => $this->edge($entry))
            ->filter()
            ->values()
            ->all();
    }

    protected function edge(StatamicEntry $entry): ?MapEdge
    {
        // The same product rule as the course's own `product` in CourseRepository.
        $product = (string) ($this->courses->normalizeCourse($entry)['product'] ?? '');
        $slug = (string) $entry->slug();

        if ($product === '' || $slug === '') {
            return null;
        }

        $explicit = trim((string) ($entry->get('product') ?? '')) !== '';

        // A product named in the field is the author's word: a missing one is a
        // broken reference and shows as such. The slug is only a guess ("the
        // product usually has the course's name"): a guess that finds no product
        // draws nothing, because a course can just as well be opened by an
        // entitlement that no product sells (a lead magnet's access, say).
        if (! $explicit && ! $this->productExists($product)) {
            return null;
        }

        return new MapEdge(
            fromType: 'product',
            fromId: $product,
            toType: self::TYPE,
            toId: $slug,
            kind: 'unlocks',
            label: __('courses::map.unlocks'),
            derivedFrom: $explicit ? 'course field `product`' : 'course slug (field `product` is empty)',
        );
    }

    /** Whether the map knows a product with this handle (needs the products addon on the map). */
    protected function productExists(string $handle): bool
    {
        return app(MapObjectRegistry::class)->lookup('product', $handle)->status === MapLookup::FOUND;
    }

    protected function entry(string $slug): ?StatamicEntry
    {
        if ($slug === '') {
            return null;
        }

        $entry = $this->query()
            ->where('collection', $this->courses->coursesCollection())
            ->where('slug', $slug)
            ->first();

        return $entry instanceof StatamicEntry && $entry->get('kind') !== CourseRepository::KIND_MATERIAL ? $entry : null;
    }

    /**
     * @return Collection<int, StatamicEntry>
     */
    protected function entries(): Collection
    {
        return $this->query()
            ->where('collection', $this->courses->coursesCollection())
            ->orderBy('title')
            ->get()
            // Material entries live in the same collection but are not courses: a
            // funnel does not hand people over to a worksheet.
            ->filter(fn ($entry): bool => $entry instanceof StatamicEntry
                && $entry->get('kind') !== CourseRepository::KIND_MATERIAL)
            ->values();
    }

    /**
     * The entry query builder. The facade's contract declares no methods, so
     * the concrete builder is named here, as CourseRepository does.
     */
    protected function query(): Builder
    {
        /** @var Builder $query */
        $query = Entry::query();

        return $query;
    }

    protected function map(StatamicEntry $entry): MapObject
    {
        return new MapObject(
            type: self::TYPE,
            id: (string) $entry->slug(),
            label: (string) ($entry->get('title') ?: $entry->slug()),
            cpUrl: $entry->editUrl(),
            enabled: (bool) $entry->published(),
            detail: null,
        );
    }
}
