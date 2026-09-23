<?php

namespace Goldnead\Courses\Console\Commands;

use Goldnead\Courses\Support\LessonBlocks;
use Illuminate\Console\Command;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

/**
 * Creates the two collections and their blueprints.
 *
 * Idempotent and polite: an existing collection or blueprint is left alone
 * unless --force is given, so running it on a live site cannot overwrite
 * fields somebody added by hand.
 *
 * --merge is the update path for a site that already has the blueprints: it
 * adds the fields a newer version ships and the site lacks, and the options a
 * select of the same handle lacks, and changes nothing else. --dry-run says
 * what it would add without saving.
 */
class Install extends Command
{
    protected $signature = 'courses:install
        {--force : Overwrite blueprints that already exist}
        {--merge : Add missing fields and options to existing blueprints, change nothing else}
        {--dry-run : List what would be created or added, save nothing}';

    protected $description = 'Create the course and lesson collections with their blueprints.';

    public function handle(): int
    {
        // The command object outlives one run (the console kernel keeps it),
        // so the locale is read fresh every time.
        $this->dictionary = null;

        $courses = (string) config('courses.collections.courses', 'courses');
        $lessons = (string) config('courses.collections.lessons', 'course_lessons');

        // A lesson's url nests under its course through `course_slug`, a
        // computed value the service provider registers: route data holds raw
        // field values, so `course:slug` would find an id, not an entry. A site
        // that serves lessons elsewhere (or not at all) changes or clears the
        // route; urls are then null and nothing in this package breaks.
        $this->ensureCollection($courses, $this->translate('Courses'), '/courses/{slug}');
        $this->ensureCollection($lessons, $this->translate('Course Lessons'), '/courses/{course_slug}/{slug}');

        $this->ensureBlueprint($courses, 'course', $courses, $lessons);
        $this->ensureBlueprint($lessons, 'course_lesson', $courses, $lessons);

        $this->components->info($this->option('dry-run') ? 'Dry run: nothing was saved.' : 'Courses installed.');

        return self::SUCCESS;
    }

    protected function ensureCollection(string $handle, string $title, string $route): void
    {
        // An existing collection is never saved again, not even to translate
        // its title: Statamic rewrites the whole YAML file on save and drops
        // every setting that equals a default, which a site may have written
        // out on purpose (found on adriangoldner.com staging, 0.2.0-rc.1).
        if (Collection::find($handle)) {
            $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'exists, kept');

            return;
        }

        if ($this->option('dry-run')) {
            $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'would create');
            $this->wouldWrite(Collection::make($handle)->path());

            return;
        }

        Collection::make($handle)->title($title)->routes($route)->save();

        $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'created');
    }

    protected function ensureBlueprint(string $collection, string $handle, string $courses, string $lessons): void
    {
        $namespace = 'collections.'.$collection;
        $existing = Blueprint::find($namespace.'.'.$handle);

        $contents = YAML::parse((string) file_get_contents(__DIR__.'/../../../resources/blueprints/'.$handle.'.yaml'));
        $contents = $this->localize($this->pointEntriesFieldsAt($contents, $courses, $lessons));
        $contents = $this->pointAssetsFieldsAt($contents);

        if ($existing && $this->option('merge') && ! $this->option('force')) {
            $this->mergeInto($existing, $contents, $handle);

            return;
        }

        if ($existing && ! $this->option('force')) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'exists, kept');

            return;
        }

        if ($this->option('dry-run')) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", $existing ? 'would overwrite' : 'would create');
            $this->wouldWrite(($existing ?? Blueprint::make($handle)->setNamespace($namespace))->path());

            return;
        }

        Blueprint::make($handle)
            ->setNamespace($namespace)
            ->setContents($contents)
            ->save();

        $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'written');
    }

    /**
     * Adds what the shipped blueprint has and the existing one lacks.
     *
     * A missing field goes into the existing section that holds the field it
     * follows in the shipped blueprint; failing that, into an existing section
     * of the same name; failing that, a new section with the shipped name at
     * the end of the first tab. A select the site already has keeps its
     * options and gains only the missing ones. Nothing is removed, reordered
     * or reconfigured.
     *
     * @param  array<string, mixed>  $shipped
     */
    protected function mergeInto(\Statamic\Fields\Blueprint $existing, array $shipped, string $handle): void
    {
        $contents = $existing->contents();
        $contents['tabs'] ??= [];
        $added = [];
        $options = [];

        foreach ($shipped['tabs'] ?? [] as $shippedTab) {
            foreach ($shippedTab['sections'] ?? [] as $shippedSection) {
                $previous = null;

                foreach ($shippedSection['fields'] ?? [] as $field) {
                    $fieldHandle = $field['handle'] ?? null;

                    if (! is_string($fieldHandle)) {
                        continue;
                    }

                    $at = $this->locateField($contents, $fieldHandle);

                    // hasField() also sees fields a fieldset import brings in,
                    // which the raw contents do not list.
                    if ($at !== null || $existing->hasField($fieldHandle)) {
                        if ($at !== null) {
                            $options = [...$options, ...$this->mergeOptions($contents, $at, $field)];
                            $previous = $fieldHandle;
                        }

                        continue;
                    }

                    $contents = $this->insertField($contents, $field, $previous, $shippedSection['display'] ?? null);
                    $added[] = $fieldHandle;
                    $previous = $fieldHandle;
                }
            }
        }

        foreach ($added as $fieldHandle) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", "+ {$fieldHandle}");
        }

        foreach ($options as $option) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", "+ option {$option}");
        }

        if ($added === [] && $options === []) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'up to date');

            return;
        }

        if ($this->option('dry-run')) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'dry run, not saved');
            $this->wouldWrite($existing->path());

            return;
        }

        $existing->setContents($contents)->save();
    }

    /**
     * On a dry run, every file a real run would write, by path.
     */
    protected function wouldWrite(string $path): void
    {
        // A plain line: a two-column row shortens a long path to fit.
        $this->line('  would write '.str_replace(base_path().'/', '', $path));
    }

    /**
     * @param  array<string, mixed>  $contents
     * @return array{0: int|string, 1: int, 2: int}|null tab key, section index, field index
     */
    protected function locateField(array $contents, string $handle): ?array
    {
        foreach ($contents['tabs'] as $tabKey => $tab) {
            foreach ($tab['sections'] ?? [] as $s => $section) {
                foreach ($section['fields'] ?? [] as $f => $field) {
                    if (($field['handle'] ?? null) === $handle) {
                        return [$tabKey, $s, $f];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $contents
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    protected function insertField(array $contents, array $field, ?string $after, mixed $sectionDisplay): array
    {
        $at = $after !== null ? $this->locateField($contents, $after) : null;

        if ($at !== null) {
            [$tabKey, $s, $f] = $at;
            array_splice($contents['tabs'][$tabKey]['sections'][$s]['fields'], $f + 1, 0, [$field]);

            return $contents;
        }

        foreach ($contents['tabs'] as $tabKey => $tab) {
            foreach ($tab['sections'] ?? [] as $s => $section) {
                if ($sectionDisplay !== null && ($section['display'] ?? null) === $sectionDisplay) {
                    $contents['tabs'][$tabKey]['sections'][$s]['fields'][] = $field;

                    return $contents;
                }
            }
        }

        $tabKey = array_key_first($contents['tabs']) ?? 'main';
        $contents['tabs'][$tabKey]['sections'] ??= [];
        $contents['tabs'][$tabKey]['sections'][] = array_filter([
            'display' => $sectionDisplay,
            'fields' => [$field],
        ], fn ($value) => $value !== null);

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $contents
     * @param  array{0: int|string, 1: int, 2: int}  $at
     * @param  array<string, mixed>  $shipped
     * @return list<string> the options added, as handle.key
     */
    protected function mergeOptions(array &$contents, array $at, array $shipped): array
    {
        [$tabKey, $s, $f] = $at;
        $current = &$contents['tabs'][$tabKey]['sections'][$s]['fields'][$f];
        $shippedOptions = $shipped['field']['options'] ?? null;

        if (($current['field']['type'] ?? null) !== 'select' || ($shipped['field']['type'] ?? null) !== 'select'
            || ! is_array($shippedOptions) || ! is_array($current['field']['options'] ?? null)) {
            return [];
        }

        $added = [];

        foreach ($shippedOptions as $key => $label) {
            if (! array_key_exists($key, $current['field']['options'])) {
                $current['field']['options'][$key] = $label;
                $added[] = $shipped['handle'].'.'.$key;
            }
        }

        return $added;
    }

    /**
     * Labels are written in the site's language (`app.locale`), from
     * resources/lang/{locale}.json. They are written, not translated at
     * runtime: registering these generic strings ("Main", "Content", "Type")
     * as JSON translations would re-translate core's own UI as well. A site
     * without a translation file for its language gets English.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    protected function localize(array $contents): array
    {
        foreach ($contents as $key => $value) {
            if (in_array($key, ['title', 'display', 'instructions', 'add_row'], true) && is_string($value)) {
                $contents[$key] = $this->translate($value);
            } elseif ($key === 'options' && is_array($value)) {
                $contents[$key] = array_map(fn ($label) => is_string($label) ? $this->translate($label) : $label, $value);
            } elseif (is_array($value)) {
                $contents[$key] = $this->localize($value);
            }
        }

        return $contents;
    }

    protected function translate(string $english): string
    {
        return $this->dictionary()[$english] ?? $english;
    }

    /** @var array<string, string>|null */
    protected ?array $dictionary = null;

    /**
     * @return array<string, string>
     */
    protected function dictionary(): array
    {
        if ($this->dictionary !== null) {
            return $this->dictionary;
        }

        $locale = (string) config('app.locale', 'en');
        $directory = __DIR__.'/../../../resources/lang/';

        foreach ([$locale, strtok(str_replace('-', '_', $locale), '_')] as $candidate) {
            $file = $directory.$candidate.'.json';

            if (is_string($candidate) && $candidate !== '' && is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);

                return $this->dictionary = is_array($decoded) ? $decoded : [];
            }
        }

        return $this->dictionary = [];
    }

    /**
     * An assets field without a container takes the whole publish form down
     * ("An asset container has not been configured"), so the download block
     * gets one written in: `courses.downloads.container`, or the site's first
     * container. A site without any keeps the field unconfigured and is told.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    protected function pointAssetsFieldsAt(array $contents): array
    {
        // The private download's own field picks from statamic-private-media's
        // container, so a public and a private file can sit side by side and
        // a private one cannot be picked from a public container. Without that
        // addon (or its container) the toggle and the field are left out.
        $privateHandle = config('private-media.source.container');
        $private = class_exists(LessonBlocks::PRIVATE_MEDIA) && is_string($privateHandle) && AssetContainer::find($privateHandle)
            ? $privateHandle
            : null;

        // The public field never defaults to the private container: files
        // there are not reachable by URL.
        $configured = config('courses.downloads.container');
        $container = is_string($configured) && $configured !== '' && AssetContainer::find($configured)
            ? $configured
            : AssetContainer::all()->reject(fn ($c) => $c->handle() === $private)->first()?->handle();

        $walk = function (array $node) use (&$walk, $container, $private): array {
            if (array_is_list($node) && collect($node)->contains(fn ($item) => is_array($item) && ($item['field']['container'] ?? null) === '@private-media')) {
                $node = $private === null
                    ? array_values(array_filter($node, fn ($item) => ! in_array($item['handle'] ?? null, ['private', 'private_file'], true)))
                    : array_map(function ($item) use ($private) {
                        if (is_array($item) && ($item['field']['container'] ?? null) === '@private-media') {
                            $item['field']['container'] = $private;
                        }

                        return $item;
                    }, $node);

                if ($private === null) {
                    // The public field no longer depends on a toggle that is gone.
                    $node = array_map(function ($item) {
                        unset($item['field']['unless']);

                        return $item;
                    }, $node);
                }
            }

            if (($node['type'] ?? null) === 'assets' && ! isset($node['container'])) {
                if ($container !== null) {
                    $node['container'] = $container;
                } else {
                    $this->components->warn('No asset container: the download block needs one. Create a container, then run courses:install again.');
                }
            }

            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };

        return $walk($contents);
    }

    /**
     * The shipped YAML names the default handles; a site that configured others
     * gets entries fields pointing at its own collections.
     *
     * @param  array<string, mixed>  $contents
     * @return array<string, mixed>
     */
    protected function pointEntriesFieldsAt(array $contents, string $courses, string $lessons): array
    {
        array_walk_recursive($contents, function (&$value) use ($courses, $lessons): void {
            if ($value === 'courses') {
                $value = $courses;
            } elseif ($value === 'course_lessons') {
                $value = $lessons;
            }
        });

        return $contents;
    }
}
