<?php

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
