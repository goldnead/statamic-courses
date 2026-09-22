<?php

use Goldnead\Courses\Facades\Courses;
use Illuminate\Support\Carbon;
use Statamic\Facades\Entry;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));

    $course = $this->makeCourse('c', ['drip_mode' => 'schedule']);
    $this->makeLesson($course, 'w1', ['sort_order' => 1, 'week' => 1]);
    $this->makeLesson($course, 'w2', ['sort_order' => 2, 'week' => 2]);
    $this->makeLesson($course, 'w3', ['sort_order' => 3, 'week' => 3]);
    $this->makeLesson($course, 'any', ['sort_order' => 4]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('opens only week one before a learner has enrolled', function () {
    expect(Courses::isLessonLocked('u', 'c', 'w1'))->toBeFalse()
        ->and(Courses::isLessonLocked('u', 'c', 'w2'))->toBeTrue()
        ->and(Courses::isLessonLocked('u', 'c', 'any'))->toBeFalse();
});

it('opens a week seven days after the previous one, counted from enrollment', function () {
    Courses::enroll('u', 'c');

    Carbon::setTestNow(Carbon::parse('2026-09-07 23:59:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'w2'))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'w2'))->toBeFalse()
        ->and(Courses::isLessonLocked('u', 'c', 'w3'))->toBeTrue();
});

it('tells a learner when a locked week opens', function () {
    Courses::enroll('u', 'c');

    $lesson = Courses::lesson('u', 'c', 'w3');

    expect($lesson['is_locked'])->toBeTrue()
        ->and(Carbon::parse($lesson['available_at'])->utc()->toDateTimeString())->toBe('2026-09-15 10:00:00');
});

it('keeps the first enrollment date when enrolling twice', function () {
    Courses::enroll('u', 'c');
    Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'UTC'));

    $enrollment = Courses::enroll('u', 'c');

    expect($enrollment->started_at->utc()->toDateTimeString())->toBe('2026-09-01 10:00:00');
});

it('lets a learner be moved ahead of the calendar, never back', function () {
    Courses::enroll('u', 'c');
    Courses::advanceToWeek('u', 'c', 3);

    expect(Courses::isLessonLocked('u', 'c', 'w3'))->toBeFalse();

    Courses::advanceToWeek('u', 'c', 1);

    expect(Courses::isLessonLocked('u', 'c', 'w3'))->toBeFalse();
});

it('ignores week numbers when the course does not drip', function () {
    Entry::query()->where('collection', 'courses')->where('slug', 'c')->first()
        ->set('drip_mode', 'none')->save();

    expect(Courses::isLessonLocked('u', 'c', 'w3'))->toBeFalse();
});
