<?php

namespace Goldnead\Courses\Support;

use Statamic\Entries\Entry as StatamicEntry;
use Throwable;

/**
 * Which brand a course belongs to, on a multi-brand site (statamic-brand-context).
 *
 * A course is a Statamic entry and carries no brand column. Its brand is, in
 * this order: the course's own `brand` field (a brand handle or id), the brand
 * brand-context maps the entry's site to (`brand-context.sites`), or none.
 *
 * Events use it so that a listener started from a webhook or the console,
 * where no brand is current, still acts in the course's brand. A course
 * without a brand falls back to the brand current when the event fires.
 * Without brand-context everything here is null.
 */
final class CourseBrand
{
    public const BRAND_MODEL = 'Goldnead\\BrandContext\\Models\\Brand';

    public static function available(): bool
    {
        return app()->bound('brand-context') && class_exists(self::BRAND_MODEL);
    }

    public static function ofEntry(StatamicEntry $entry): ?int
    {
        if (! self::available()) {
            return null;
        }

        $own = $entry->get('brand');

        if (is_scalar($own) && (string) $own !== '') {
            return self::idOf((string) $own);
        }

        $sites = config('brand-context.sites', []);
        $site = $entry->locale();

        return is_array($sites) && is_string($sites[$site] ?? null) ? self::idOf($sites[$site]) : null;
    }

    /**
     * The brand an event carries: the course's, else the one current now.
     *
     * @param  array<string, mixed>|null  $course
     */
    public static function forEvent(?array $course): ?int
    {
        $own = $course['brand_id'] ?? null;

        return is_int($own) ? $own : self::current();
    }

    public static function current(): ?int
    {
        if (! self::available()) {
            return null;
        }

        try {
            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected static function idOf(string $handleOrId): ?int
    {
        try {
            $model = self::BRAND_MODEL;
            $id = $model::query()
                ->where('handle', $handleOrId)
                ->when(ctype_digit($handleOrId), fn ($query) => $query->orWhere('id', (int) $handleOrId))
                ->value('id');
        } catch (Throwable) {
            return null;
        }

        return $id === null ? null : (int) $id;
    }
}
