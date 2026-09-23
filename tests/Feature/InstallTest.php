<?php

use Goldnead\Courses\Facades\Courses;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\YAML;

it('creates both collections with their blueprints', function () {
    expect(Collection::find('courses'))->not->toBeNull()
        ->and(Collection::find('course_lessons'))->not->toBeNull();

    $lesson = Blueprint::find('collections.course_lessons.course_lesson');

    expect($lesson->field('course')->config()['collections'])->toBe(['courses'])
        ->and($lesson->field('prerequisite_lessons')->config()['collections'])->toBe(['course_lessons'])
        ->and(Blueprint::find('collections.courses.course')->hasField('drip_mode'))->toBeTrue();
});

it('routes both collections so courses and lessons have a url', function () {
    $course = $this->makeCourse('cvt-101');
    $this->makeLesson($course, 'intro');

    expect(Courses::course('cvt-101')['url'])->toBe('/courses/cvt-101')
        ->and(Courses::lesson('u', 'cvt-101', 'intro')['url'])->toBe('/courses/cvt-101/intro');
});

it('answers a null url, not an exception, for collections without a route', function () {
    Collection::find('courses')->routes(null)->save();
    Collection::find('course_lessons')->routes(null)->save();
    $course = $this->makeCourse('cvt-101');
    $this->makeLesson($course, 'intro');

    expect(Courses::course('cvt-101')['url'])->toBeNull()
        ->and(Courses::lesson('u', 'cvt-101', 'intro')['url'])->toBeNull()
        ->and(Courses::summary('u', 'cvt-101')['continue_lesson']['url'])->toBeNull();
});

it('writes collection titles and blueprint labels in the site language', function () {
    config()->set('app.locale', 'de');
    config()->set('courses.collections', ['courses' => 'kurse', 'lessons' => 'lektionen']);

    $this->artisan('courses:install')->assertSuccessful();

    $lesson = Blueprint::find('collections.lektionen.course_lesson');

    expect(Collection::find('kurse')->title())->toBe('Kurse')
        ->and(Collection::find('lektionen')->title())->toBe('Kurslektionen')
        ->and($lesson->title())->toBe('Kurslektion')
        ->and($lesson->field('course')->display())->toBe('Kurs')
        ->and($lesson->field('video_duration')->instructions())->toStartWith('mm:ss, hh:mm:ss oder Sekunden')
        ->and($lesson->field('item_type')->config()['options']['assignment'])->toBe('Aufgabe')
        ->and($lesson->field('course')->config()['collections'])->toBe(['kurse']);
});

it('leaves the labels English for a language it has no translation for', function () {
    config()->set('app.locale', 'fr');
    config()->set('courses.collections', ['courses' => 'cours', 'lessons' => 'lecons']);

    $this->artisan('courses:install')->assertSuccessful();

    expect(Collection::find('cours')->title())->toBe('Courses')
        ->and(Blueprint::find('collections.lecons.course_lesson')->field('course')->display())->toBe('Course');
});

it('keeps field instructions out of the narrow structure columns', function () {
    $lesson = Blueprint::find('collections.course_lessons.course_lesson');

    $narrow = $lesson->fields()->all()
        ->filter(fn ($field) => ($field->config()['width'] ?? 100) < 100 && filled($field->instructions()));

    expect($narrow->keys()->all())->toBe([]);
});

it('keeps a blueprint somebody edited unless forced', function () {
    $blueprint = Blueprint::find('collections.courses.course');
    $blueprint->ensureField('own_field', ['type' => 'text'])->save();

    $this->artisan('courses:install')->assertSuccessful();
    expect(Blueprint::find('collections.courses.course')->hasField('own_field'))->toBeTrue();

    $this->artisan('courses:install', ['--force' => true])->assertSuccessful();
    expect(Blueprint::find('collections.courses.course')->hasField('own_field'))->toBeFalse();
});

it('points the entries fields at configured collection handles', function () {
    config()->set('courses.collections', ['courses' => 'trainings', 'lessons' => 'training_units']);

    $this->artisan('courses:install')->assertSuccessful();

    $lesson = Blueprint::find('collections.training_units.course_lesson');

    expect(Collection::find('trainings'))->not->toBeNull()
        ->and($lesson->field('course')->config()['collections'])->toBe(['trainings'])
        ->and($lesson->field('prerequisite_lessons')->config()['collections'])->toBe(['training_units']);
});

it('has a German label for every label, instruction and option the blueprints carry', function () {
    $strings = [];
    $walk = function (array $node) use (&$walk, &$strings): void {
        foreach ($node as $key => $value) {
            if (in_array($key, ['title', 'display', 'instructions', 'add_row'], true) && is_string($value)) {
                $strings[] = $value;
            } elseif ($key === 'options' && is_array($value)) {
                array_push($strings, ...array_values($value));
            } elseif (is_array($value)) {
                $walk($value);
            }
        }
    };

    foreach (['course', 'course_lesson'] as $blueprint) {
        $walk(YAML::parse((string) file_get_contents(__DIR__.'/../../resources/blueprints/'.$blueprint.'.yaml')));
    }

    $german = json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/de.json'), true);

    expect(array_values(array_diff(array_unique($strings), array_keys($german))))->toBe([]);
});

describe('--merge', function () {
    beforeEach(function () {
        // A site's own lesson blueprint as an older version left it, edited
        // by hand: a field of its own, a pass_score of its own, a drip select
        // with its own label and only two options, and no blocks.
        Blueprint::make('course_lesson')->setNamespace('collections.course_lessons')->setContents([
            'tabs' => ['main' => ['display' => 'Main', 'sections' => [[
                'fields' => [
                    ['handle' => 'title', 'field' => ['type' => 'text']],
                    ['handle' => 'course', 'field' => ['type' => 'entries', 'collections' => ['courses'], 'max_items' => 1]],
                    ['handle' => 'content', 'field' => ['type' => 'markdown', 'toolbar_mode' => 'floating']],
                    ['handle' => 'pass_score', 'field' => ['type' => 'integer', 'default' => 70]],
                    ['handle' => 'own_field', 'field' => ['type' => 'text']],
                ],
            ]]]],
        ])->save();

        Blueprint::make('course')->setNamespace('collections.courses')->setContents([
            'tabs' => ['main' => ['sections' => [['fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
                ['handle' => 'drip_mode', 'field' => ['type' => 'select', 'options' => ['none' => 'Keine', 'schedule' => 'Nach Wochen']]],
            ]]]]],
        ])->save();

        Collection::find('courses')->title('Courses')->save();
    });

    it('adds only what is missing and changes nothing the site has', function () {
        config()->set('app.locale', 'de');

        $this->artisan('courses:install', ['--merge' => true])->assertSuccessful();

        $lesson = Blueprint::find('collections.course_lessons.course_lesson');
        $course = Blueprint::find('collections.courses.course');

        expect($lesson->hasField('blocks'))->toBeTrue()
            ->and($lesson->hasField('assessment_min_score'))->toBeTrue()
            ->and($lesson->hasField('own_field'))->toBeTrue()
            ->and($lesson->field('pass_score')->config()['default'])->toBe(70)
            ->and($lesson->field('content')->config()['toolbar_mode'])->toBe('floating')
            ->and($course->field('drip_mode')->config()['options'])->toMatchArray(['none' => 'Keine', 'schedule' => 'Nach Wochen', 'days' => 'Nach Tagen ab Einschreibung'])
            ->and($course->hasField('on_payment_failure'))->toBeTrue()
            ->and(Collection::find('courses')->title())->toBe('Kurse');

        // The new block field sits right after the field it follows in the shipped blueprint.
        $handles = collect($lesson->contents()['tabs']['main']['sections'][0]['fields'])->pluck('handle')->all();
        expect(array_search('blocks', $handles))->toBe(array_search('content', $handles) + 1);
    });

    it('says what it would add and saves nothing on a dry run', function () {
        $this->artisan('courses:install', ['--merge' => true, '--dry-run' => true])
            ->expectsOutputToContain('+ blocks')
            ->expectsOutputToContain('+ option drip_mode.days')
            ->assertSuccessful();

        expect(Blueprint::find('collections.course_lessons.course_lesson')->hasField('blocks'))->toBeFalse();
    });

    it('is a no-op the second time', function () {
        $this->artisan('courses:install', ['--merge' => true])->assertSuccessful();
        $before = Blueprint::find('collections.course_lessons.course_lesson')->contents();

        $this->artisan('courses:install', ['--merge' => true])->expectsOutputToContain('up to date')->assertSuccessful();

        expect(Blueprint::find('collections.course_lessons.course_lesson')->contents())->toBe($before);
    });
});

it('gives the download block an asset container, the configured one or the first', function () {
    $download = fn () => Blueprint::find('collections.course_lessons.course_lesson')
        ->field('blocks')->config()['sets']['media']['sets']['download']['fields'][0]['field'];

    AssetContainer::make('media')->disk('local')->save();
    AssetContainer::make('paid')->disk('local')->save();

    $this->artisan('courses:install', ['--force' => true])->assertSuccessful();
    expect($download()['container'])->toBe('media');

    config()->set('courses.downloads.container', 'paid');
    $this->artisan('courses:install', ['--force' => true])->assertSuccessful();
    expect($download()['container'])->toBe('paid');
});

it('gives the private download its own field on the private-media container, and drops it without one', function () {
    $downloadFields = fn () => collect(Blueprint::find('collections.course_lessons.course_lesson')
        ->field('blocks')->config()['sets']['media']['sets']['download']['fields'])->keyBy('handle');

    AssetContainer::make('media')->disk('local')->save();

    $this->artisan('courses:install', ['--force' => true])->assertSuccessful();
    expect($downloadFields()->keys()->all())->toBe(['file', 'label'])
        ->and($downloadFields()['file']['field'])->not->toHaveKey('unless');

    AssetContainer::make('private')->disk('local')->save();
    config()->set('private-media.source.container', 'private');

    $this->artisan('courses:install', ['--force' => true])->assertSuccessful();
    expect($downloadFields()->keys()->all())->toBe(['private', 'file', 'private_file', 'label'])
        ->and($downloadFields()['private_file']['field']['container'])->toBe('private')
        ->and($downloadFields()['file']['field']['container'])->toBe('media');
});

it('localizes the lesson building blocks and the new course settings', function () {
    config()->set('app.locale', 'de');
    $this->artisan('courses:install', ['--force' => true])->assertSuccessful();

    $lesson = Blueprint::find('collections.course_lessons.course_lesson');
    $course = Blueprint::find('collections.courses.course');

    expect($lesson->field('blocks')->display())->toBe('Bausteine')
        ->and($lesson->field('blocks')->config()['sets']['media']['sets']['download']['display'])->toBe('Download')
        ->and($lesson->field('blocks')->config()['sets']['content']['sets']['columns']['fields'][0]['field']['add_row'])->toBe('Spalte hinzufügen')
        ->and($course->field('on_payment_failure')->config()['options']['pause_drip'])->toBe('Freischaltung pausieren');
});
