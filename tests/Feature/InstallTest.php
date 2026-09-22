<?php

use Goldnead\Courses\Facades\Courses;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

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
