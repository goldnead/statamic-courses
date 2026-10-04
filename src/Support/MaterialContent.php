<?php

namespace Goldnead\Courses\Support;

use Illuminate\Support\Collection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
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
            'body' => $entry instanceof StatamicEntry ? $this->body($entry, $course['slug'], $signFor) : '',
            'downloads' => $downloads,
            'download_groups' => $this->groups($downloads),
            'has_downloads' => $downloads !== [],
            'pages' => $pages,
            'has_pages' => $pages !== [],
        ];
    }

    /**
     * Every file of the `downloads` grid that resolves to a private file, in
     * the order entered. A row is a group with its `files`; a row saved one
     * file per row (`file`, `group`) is read as well. `format` is the file
     * extension in capitals, unless a row names one.
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

            $group = trim((string) ($row['group'] ?? ''));
            $files = array_key_exists('files', $row) ? (is_array($row['files']) ? $row['files'] : []) : [$row];

            foreach ($files as $file) {
                if (! is_array($file) || ($file['enabled'] ?? true) === false) {
                    continue;
                }

                $download = $this->blocks->privateDownload(
                    $file['file'] ?? null,
                    trim((string) ($file['label'] ?? '')),
                    $user,
                    LessonBlocks::resourceFor($slug),
                );

                if ($download === null) {
                    continue;
                }

                $format = trim((string) ($file['format'] ?? ''));
                unset($download['type'], $download['private']);

                $downloads[] = [
                    ...$download,
                    'group' => $group,
                    'format' => $format !== '' ? $format : strtoupper((string) $download['extension']),
                ];
            }
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
     * The Bard text as HTML. An image on private-media's container is a
     * link signed for the material when `$signFor` may have it, and left out
     * otherwise: for a stranger, a guest, or when it cannot be signed. Any
     * other image keeps its public URL.
     */
    protected function body(StatamicEntry $entry, string $slug, mixed $signFor): string
    {
        $raw = $entry->get('body');

        if (! is_array($raw) || $raw === []) {
            // Saved as HTML (save_html): nothing to protect node by node.
            return is_string($raw) ? $raw : '';
        }

        $nodes = $this->protectImages($raw, $signFor, LessonBlocks::resourceFor($slug));
        $html = (new Augmentor($entry->blueprint()?->field('body')?->fieldtype() ?? new Bard))->augment($nodes);

        return is_string($html) ? $html : '';
    }

    /**
     * @param  array<int|string, mixed>  $nodes  ProseMirror nodes
     * @return list<mixed>
     */
    protected function protectImages(array $nodes, mixed $signFor, string $resource): array
    {
        $private = config('private-media.source.container');
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'image') {
                $src = (string) ($node['attrs']['src'] ?? '');
                $id = str_starts_with($src, 'asset::') ? substr($src, 7) : null;

                if ($id !== null && is_string($private) && $private !== '' && str_starts_with($id, $private.'::')) {
                    $url = $signFor !== null ? $this->blocks->signedAssetUrl($id, $signFor, $resource) : null;

                    if ($url === null) {
                        continue;
                    }

                    $node['attrs']['src'] = $url;
                }
            }

            if (is_array($node) && is_array($node['content'] ?? null)) {
                $node['content'] = $this->protectImages($node['content'], $signFor, $resource);
            }

            $out[] = $node;
        }

        return $out;
    }
}
