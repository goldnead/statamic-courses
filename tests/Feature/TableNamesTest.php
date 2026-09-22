<?php

use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonEvent;
use Goldnead\Courses\Models\LessonState;
use Illuminate\Support\Facades\Schema;

/*
 * adriangoldner.com already has `course_lesson_states` and
 * `course_lesson_events` with a different schema. Installing this addon there
 * must not collide with them, so every table carries the addon's prefix.
 */

it('prefixes every table with courses_', function () {
    expect((new LessonState)->getTable())->toBe('courses_lesson_states')
        ->and((new LessonEvent)->getTable())->toBe('courses_lesson_events')
        ->and((new Enrollment)->getTable())->toBe('courses_enrollments')
        ->and(Schema::hasTable('courses_lesson_states'))->toBeTrue()
        ->and(Schema::hasTable('courses_lesson_events'))->toBeTrue()
        ->and(Schema::hasTable('courses_enrollments'))->toBeTrue();
});

it('leaves the host site\'s unprefixed course tables alone', function () {
    expect(Schema::hasTable('course_lesson_states'))->toBeFalse()
        ->and(Schema::hasTable('course_lesson_events'))->toBeFalse()
        ->and(Schema::hasTable('course_enrollments'))->toBeFalse();
});
