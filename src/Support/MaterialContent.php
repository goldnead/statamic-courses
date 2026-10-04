<?php

namespace Goldnead\Courses\Support;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Fields\Value;
use Statamic\Fieldtypes\Bard;
use Statamic\Fieldtypes\Bard\Augmentor;

/**
 * A material (a course entry of kind `material`) as template data: its text,
 * its downloads flat and grouped, and its further pages.
 *
 * Downloads are files on statamic-private-media's container only, each signed
 * for `course:<slug>`, which CourseMediaAccess answers with canAccess(). They
 * are signed only when `$signFor` is set: the caller decides whether the
 * person may have them, this class does not ask.
 */
class MaterialContent
{
    public function __construct(
        protected CourseRepository $courses,
        protected LessonBlocks $blocks,
        protected Audience $audience,
    ) {}

    /**
     * @param  array<string, mixed>  $course  a normalized course of kind material
     * @param  mixed  $signFor  the user the download links are signed for, or null for none
     * @param  mixed  $viewer  the user (or id) whose audience rules pick the pages
     * @return array<string, mixed>
     */
    public function for(array $course, mixed $signFor, mixed $viewer): array
    {
        $entry = Entry::find($course['id']);
        $downloads = $entry instanceof StatamicEntry && $signFor !== null
            ? $this->downloads($entry, $course['slug'], $signFor)
            : [];

        $pages = $this->audience->visibleLessons($viewer, $course, $this->courses->lessonsFor($course['id']))
            ->map(fn (array $lesson): array => [
                'slug' => $lesson['slug'],
                'title' => $lesson['title'],
                'url' => $lesson['url'],
                'sort_order' => $lesson['sort_order'],
            ])
            ->values()
            ->all();

        return [
            ...$course,
            'body' => $entry instanceof StatamicEntry ? $this->body($entry) : '',
            'downloads' => $downloads,
            'download_groups' => $this->groups($downloads),
            'has_downloads' => $downloads !== [],
            'pages' => $pages,
            'has_pages' => $pages !== [],
        ];
    }

    /**
     * Every row of the `downloads` grid that resolves to a private file, in
     * the order entered. `format` is the row's own, else the file extension.
     *
     * @return list<array<string, mixed>>
     */
    protected function downloads(StatamicEntry $entry, string $slug, mixed $user): array
    {
        $rows = $entry->get('downloads');
        $downloads = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row) || ($row['enabled'] ?? true) === false) {
                continue;
            }

            $download = $this->blocks->privateDownload(
                $row['file'] ?? null,
                trim((string) ($row['label'] ?? '')),
                $user,
                LessonBlocks::resourceFor($slug),
            );

            if ($download === null) {
                continue;
            }

            $format = trim((string) ($row['format'] ?? ''));
            unset($download['type'], $download['private']);

            $downloads[] = [
                ...$download,
                'group' => trim((string) ($row['group'] ?? '')),
                'format' => $format !== '' ? $format : strtoupper((string) $download['extension']),
            ];
        }

        return $downloads;
    }

    /**
     * One entry per group, in the order a group first appears; rows without a
     * group form a group with an empty name, in the same place.
     *
     * @param  list<array<string, mixed>>  $downloads
     * @return list<array{group: string, downloads: list<array<string, mixed>>, count: int}>
     */
    protected function groups(array $downloads): array
    {
        /** @var Collection<string, Collection<int, array<string, mixed>>> $grouped */
        $grouped = collect($downloads)->groupBy(fn (array $download): string => $download['group'], preserveKeys: false);

        return $grouped
            ->map(fn (Collection $rows, string $group): array => [
                'group' => $group,
                'downloads' => $rows->values()->all(),
                'count' => $rows->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * The Bard text as HTML. Through the blueprint's field when it has one
     * (asset images become URLs, the site's Bard config applies); raw
     * ProseMirror without one is rendered by a plain Bard.
     */
    protected function body(StatamicEntry $entry): string
    {
        $raw = $entry->get('body');

        if ($raw === null || $raw === '' || $raw === []) {
            return '';
        }

        if ($entry->blueprint()?->hasField('body')) {
            $value = $entry->augmentedValue('body');
            $value = $value instanceof Value ? $value->value() : $value;

            if (is_string($value)) {
                return $value;
            }
        }

        if (is_string($raw)) {
            return $raw;
        }

        return is_array($raw) ? (string) (new Augmentor(new Bard))->augment($raw) : '';
    }
}
