<?php

namespace Goldnead\Courses\Support;

use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Entry;
use Statamic\Facades\Markdown;
use Throwable;

/**
 * A lesson's content as a list of blocks, ready for a template (K1).
 *
 * Reads the raw values of the entry, not the augmented ones, so the shape is
 * the same on every Statamic version and in every context (tag, API, test).
 * The older markdown field `content` comes first, as a text block: a lesson
 * written before blocks existed renders exactly as it did.
 *
 * Every block has `type`, and per type:
 *
 * - `text`: `html`
 * - `callout`: `tone` (info, tip, warning), `heading`, `html`
 * - `columns`: `columns` (list of `html`), `count`
 * - `faq`: `items` (list of `question`, `answer_html`)
 * - `video`: `url`, `embed_url` (YouTube/Vimeo player URL or null), `caption`
 * - `download`: `url`, `label`, `filename`, `extension`, `size`, `private`
 * - `button`: `label`, `url`, `style` (primary, secondary)
 *
 * A block that has nothing to show (a download without a file, a private
 * download without statamic-private-media, a button without a link) is
 * dropped, so a template never prints an empty shell.
 */
class LessonBlocks
{
    public const PRIVATE_MEDIA = 'Goldnead\\PrivateMedia\\PrivateMedia';

    /** What a private download is signed for, and what CourseMediaAccess answers. */
    public const RESOURCE_PREFIX = 'course:';

    /**
     * Here, not on CourseMediaAccess: that class implements private-media's
     * interface, and loading it without private-media is a fatal error.
     */
    public static function resourceFor(string $courseSlug): string
    {
        return self::RESOURCE_PREFIX.$courseSlug;
    }

    /**
     * @param  string|null  $resource  what a private download is signed for: `course:<slug>`, which
     *                                 CourseMediaAccess answers with Courses::canAccess()
     * @return list<array<string, mixed>>
     */
    public function for(StatamicEntry $lesson, mixed $user = null, ?string $resource = null): array
    {
        $blocks = [];
        $content = $lesson->get('content');

        if (is_string($content) && trim($content) !== '') {
            $blocks[] = ['type' => 'text', 'html' => $this->markdown($content)];
        }

        $raw = $lesson->get('blocks');

        foreach (is_array($raw) ? $raw : [] as $block) {
            if (! is_array($block) || ($block['enabled'] ?? true) === false) {
                continue;
            }

            $normalized = $this->block($block, $user, $resource);

            if ($normalized !== null) {
                $blocks[] = $normalized;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function block(array $block, mixed $user, ?string $resource): ?array
    {
        $type = (string) ($block['type'] ?? '');

        return match ($type) {
            'text' => $this->filled($block['text'] ?? null) ? ['type' => 'text', 'html' => $this->markdown($block['text'])] : null,
            'callout' => $this->callout($block),
            'columns' => $this->columns($block),
            'faq' => $this->faq($block),
            'video' => $this->video($block),
            'download' => $this->download($block, $user, $resource),
            'button' => $this->button($block),
            // A set a site added itself: handed over as stored, for its own partial.
            default => $type !== '' ? [...$block, 'type' => $type] : null,
        };
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function callout(array $block): ?array
    {
        $heading = trim((string) ($block['heading'] ?? ''));

        if ($heading === '' && ! $this->filled($block['text'] ?? null)) {
            return null;
        }

        $tone = in_array($block['tone'] ?? null, ['info', 'tip', 'warning'], true) ? $block['tone'] : 'info';

        return ['type' => 'callout', 'tone' => $tone, 'heading' => $heading, 'html' => $this->markdown((string) ($block['text'] ?? ''))];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function columns(array $block): ?array
    {
        $columns = collect(is_array($block['columns'] ?? null) ? $block['columns'] : [])
            ->filter(fn ($column): bool => is_array($column) && ($column['enabled'] ?? true) !== false)
            ->map(fn (array $column): array => ['html' => $this->markdown((string) ($column['text'] ?? ''))])
            ->values()
            ->all();

        return $columns === [] ? null : ['type' => 'columns', 'columns' => $columns, 'count' => count($columns)];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function faq(array $block): ?array
    {
        $items = collect(is_array($block['items'] ?? null) ? $block['items'] : [])
            ->filter(fn ($item): bool => is_array($item) && trim((string) ($item['question'] ?? '')) !== '')
            ->map(fn (array $item): array => [
                'question' => trim((string) $item['question']),
                'answer_html' => $this->markdown((string) ($item['answer'] ?? '')),
            ])
            ->values()
            ->all();

        return $items === [] ? null : ['type' => 'faq', 'items' => $items];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function video(array $block): ?array
    {
        $url = trim((string) ($block['url'] ?? ''));

        if ($url === '') {
            return null;
        }

        $embed = self::embedUrl($url);

        return [
            'type' => 'video',
            'url' => $url,
            'embed_url' => $embed,
            'caption' => trim((string) ($block['caption'] ?? '')),
            // The service statamic-consent must allow before the player loads.
            'consent_service' => $embed === null ? null : (str_contains($embed, 'vimeo') ? 'vimeo' : 'youtube'),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function download(array $block, mixed $user, ?string $resource): ?array
    {
        $private = (bool) ($block['private'] ?? false);
        // A private download keeps its file in its own field; a block saved
        // before that field existed still has it under `file`.
        $asset = $this->asset($private ? ($block['private_file'] ?? $block['file'] ?? null) : ($block['file'] ?? null));

        if (! $asset instanceof AssetContract) {
            return null;
        }

        $container = $asset instanceof \Statamic\Assets\Asset ? $asset->containerHandle() : null;

        if ($private && $container !== config('private-media.source.container')) {
            // private-media serves only its own container: a link to a file
            // elsewhere would be refused, and the file may be public anyway.
            Log::warning('statamic-courses: a private download points at a file outside the private-media container and is left out.', [
                'container' => $container,
                'file' => $asset->basename(),
            ]);

            return null;
        }

        $url = $private ? $this->signedUrl($asset, $user, $resource) : $asset->url();

        if (! is_string($url) || $url === '') {
            return null;
        }

        $label = trim((string) ($block['label'] ?? ''));

        return [
            'type' => 'download',
            'url' => $url,
            'label' => $label !== '' ? $label : $asset->basename(),
            'filename' => $asset->basename(),
            'extension' => strtolower((string) $asset->extension()),
            'size' => $this->size($asset),
            'private' => $private,
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    protected function button(array $block): ?array
    {
        $url = $this->link($block['link'] ?? null);
        $label = trim((string) ($block['label'] ?? ''));

        if ($url === null || $label === '') {
            return null;
        }

        return ['type' => 'button', 'label' => $label, 'url' => $url, 'style' => ($block['style'] ?? 'primary') === 'secondary' ? 'secondary' : 'primary'];
    }

    /**
     * The player URL for YouTube and Vimeo links, null for anything else
     * (a file, another host), which a template shows as a <video> or a link.
     */
    public static function embedUrl(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1];
        }

        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m) === 1) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }

    protected function signedUrl(AssetContract $asset, mixed $user, ?string $resource): ?string
    {
        if ($user === null || $resource === null || $resource === '' || ! class_exists(self::PRIVATE_MEDIA)) {
            return null;
        }

        try {
            $url = app(self::PRIVATE_MEDIA)->url($user, $resource, $asset);
        } catch (Throwable) {
            return null;
        }

        return is_string($url) ? $url : null;
    }

    protected function asset(mixed $value): ?AssetContract
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        if (str_contains($value, '::')) {
            $asset = Asset::find($value);

            return $asset instanceof AssetContract ? $asset : null;
        }

        // A bare path: the field's container stores paths only. The
        // configured one first, then any container that has the file.
        $configured = config('courses.downloads.container');
        $containers = AssetContainer::all()->sortBy(fn ($container): int => $container->handle() === $configured ? 0 : 1);

        foreach ($containers as $container) {
            $asset = $container->asset($value);

            if ($asset instanceof AssetContract) {
                return $asset;
            }
        }

        return null;
    }

    protected function link(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'entry::')) {
            $entry = Entry::find(substr($value, 7));

            return $entry instanceof StatamicEntry ? $entry->url() : null;
        }

        if (str_starts_with($value, 'asset::')) {
            return Asset::find(substr($value, 7))?->url();
        }

        return $value;
    }

    protected function size(AssetContract $asset): ?string
    {
        try {
            $bytes = method_exists($asset, 'size') ? (int) $asset->size() : 0;
        } catch (Throwable) {
            return null;
        }

        if ($bytes <= 0) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $power), $power > 1 ? 1 : 0).' '.$units[$power];
    }

    protected function markdown(string $text): string
    {
        return trim($text) === '' ? '' : Markdown::parse($text);
    }

    protected function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
