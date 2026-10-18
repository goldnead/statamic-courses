<?php

namespace Goldnead\Courses\Integrations\Map;

use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Support\CourseRepository;
use Illuminate\Support\Collection;
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
 * Registered only when statamic-brand-context is installed (ServiceProvider::boot).
 */
class CourseMapObjects implements ProvidesMapObjects
{
    public const TYPE = 'course';

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
