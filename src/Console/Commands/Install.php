<?php

namespace Goldnead\Courses\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

/**
 * Creates the two collections and their blueprints.
 *
 * Idempotent and polite: an existing collection or blueprint is left alone
 * unless --force is given, so running it on a live site cannot overwrite
 * fields somebody added by hand.
 */
class Install extends Command
{
    protected $signature = 'courses:install {--force : Overwrite blueprints that already exist}';

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

        $this->components->info('Courses installed.');

        return self::SUCCESS;
    }

    protected function ensureCollection(string $handle, string $title, string $route): void
    {
        if (Collection::find($handle)) {
            $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'exists, kept');

            return;
        }

        Collection::make($handle)->title($title)->routes($route)->save();

        $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'created');
    }

    protected function ensureBlueprint(string $collection, string $handle, string $courses, string $lessons): void
    {
        $namespace = 'collections.'.$collection;

        if (Blueprint::find($namespace.'.'.$handle) && ! $this->option('force')) {
            $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'exists, kept');

            return;
        }

        $contents = YAML::parse((string) file_get_contents(__DIR__.'/../../../resources/blueprints/'.$handle.'.yaml'));
        $contents = $this->localize($this->pointEntriesFieldsAt($contents, $courses, $lessons));

        Blueprint::make($handle)
            ->setNamespace($namespace)
            ->setContents($contents)
            ->save();

        $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'written');
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
            if (in_array($key, ['title', 'display', 'instructions'], true) && is_string($value)) {
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
