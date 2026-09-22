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
        $courses = (string) config('courses.collections.courses', 'courses');
        $lessons = (string) config('courses.collections.lessons', 'course_lessons');

        $this->ensureCollection($courses, 'Courses');
        $this->ensureCollection($lessons, 'Course Lessons');

        $this->ensureBlueprint($courses, 'course', $courses, $lessons);
        $this->ensureBlueprint($lessons, 'course_lesson', $courses, $lessons);

        $this->components->info('Courses installed.');

        return self::SUCCESS;
    }

    protected function ensureCollection(string $handle, string $title): void
    {
        if (Collection::find($handle)) {
            $this->components->twoColumnDetail("Collection <comment>{$handle}</comment>", 'exists, kept');

            return;
        }

        Collection::make($handle)->title($title)->save();

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
        $contents = $this->pointEntriesFieldsAt($contents, $courses, $lessons);

        Blueprint::make($handle)
            ->setNamespace($namespace)
            ->setContents($contents)
            ->save();

        $this->components->twoColumnDetail("Blueprint <comment>{$handle}</comment>", 'written');
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
