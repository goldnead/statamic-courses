<?php

use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Facades\Courses;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function dripCourse(object $test, string $mode, array $lessons, array $course = []): void
{
    $id = $test->makeCourse('c', ['drip_mode' => $mode, ...$course]);

    foreach (array_values($lessons) as $i => [$slug, $data]) {
        $test->makeLesson($id, $slug, ['sort_order' => $i + 1, ...$data]);
    }
}

it('opens a lesson a number of days after enrollment', function () {
    dripCourse($this, 'days', [['now', []], ['day3', ['drip_after' => 3]]]);

    // Nothing to count from yet: only what needs no waiting is open.
    expect(Courses::isLessonLocked('u', 'c', 'now'))->toBeFalse()
        ->and(Courses::isLessonLocked('u', 'c', 'day3'))->toBeTrue();

    Courses::enroll('u', 'c');

    Carbon::setTestNow(Carbon::parse('2026-09-04 09:59:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'day3'))->toBeTrue()
        ->and(Carbon::parse(Courses::lesson('u', 'c', 'day3')['available_at'])->utc()->toDateTimeString())->toBe('2026-09-04 10:00:00');

    Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'day3'))->toBeFalse();
});

it('opens a lesson on a fixed calendar date, in the site timezone', function () {
    dripCourse($this, 'date', [['later', ['drip_date' => '2026-09-10']], ['undated', []]]);

    expect(Courses::isLessonLocked('u', 'c', 'later'))->toBeTrue()
        ->and(Courses::isLessonLocked('u', 'c', 'undated'))->toBeFalse()
        ->and(Courses::lesson('u', 'c', 'later')['lock_reason'])->toBe('schedule');

    // Midnight in America/Chicago is 05:00 UTC.
    Carbon::setTestNow(Carbon::parse('2026-09-10 04:59:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'later'))->toBeTrue();

    Carbon::setTestNow(Carbon::parse('2026-09-10 05:00:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'later'))->toBeFalse();
});

it('opens lessons on a day of the month, the nth time it comes round after enrollment', function () {
    dripCourse($this, 'day_of_month', [
        ['first', ['drip_after' => 1]],
        ['second', ['drip_after' => 2]],
    ], ['drip_day_of_month' => 15]);

    Carbon::setTestNow(Carbon::parse('2026-09-20 10:00:00', 'UTC'));
    Courses::enroll('u', 'c');

    $opens = fn (string $slug) => Carbon::parse(Courses::lesson('u', 'c', $slug)['available_at'])->timezone('America/Chicago')->toDateString();

    // Enrolled after the 15th: the first one is October's.
    expect($opens('first'))->toBe('2026-10-15')
        ->and($opens('second'))->toBe('2026-11-15');

    Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'first'))->toBeFalse()
        ->and(Courses::isLessonLocked('u', 'c', 'second'))->toBeTrue();
});

it('counts the enrollment day itself as the first time the day comes round', function () {
    dripCourse($this, 'day_of_month', [['first', ['drip_after' => 1]]], ['drip_day_of_month' => 1]);

    Courses::enroll('u', 'c');

    expect(Courses::isLessonLocked('u', 'c', 'first'))->toBeFalse();
});

it('opens lessons after the nth payment and announces what a payment unlocked', function () {
    dripCourse($this, 'payments', [['one', ['drip_after' => 1]], ['two', ['drip_after' => 2]], ['three', ['drip_after' => 3]]]);
    Event::fake([LessonUnlocked::class]);

    expect(Courses::isLessonLocked('u', 'c', 'one'))->toBeTrue();

    Courses::recordBilling('u', 'c', payments: 1);
    expect(Courses::isLessonLocked('u', 'c', 'one'))->toBeFalse()
        ->and(Courses::isLessonLocked('u', 'c', 'two'))->toBeTrue()
        ->and(Courses::lesson('u', 'c', 'two')['lock_reason'])->toBe('payment');

    Courses::recordBilling('u', 'c', payments: 2);
    expect(Courses::isLessonLocked('u', 'c', 'two'))->toBeFalse()
        ->and(Courses::isLessonLocked('u', 'c', 'three'))->toBeTrue();

    Event::assertDispatched(LessonUnlocked::class, fn (LessonUnlocked $e) => $e->lessonSlug === 'two' && $e->source === 'billing');
    Event::assertNotDispatched(LessonUnlocked::class, fn (LessonUnlocked $e) => $e->lessonSlug === 'three');
});

it('never counts payments backwards', function () {
    dripCourse($this, 'payments', [['two', ['drip_after' => 2]]]);

    Courses::recordBilling('u', 'c', payments: 2);
    Courses::recordBilling('u', 'c', payments: 1);

    expect(Courses::isLessonLocked('u', 'c', 'two'))->toBeFalse();
});

it('holds lessons back until the trial is paid for', function () {
    dripCourse($this, 'after_trial', [['taster', []], ['full', ['drip_after' => 1]]]);

    // Bought without a trial: nothing to wait for.
    expect(Courses::isLessonLocked('u', 'c', 'full'))->toBeFalse();

    Courses::recordBilling('t', 'c', payments: 1, trialUntil: Carbon::parse('2026-09-15'));
    expect(Courses::isLessonLocked('t', 'c', 'taster'))->toBeFalse()
        ->and(Courses::isLessonLocked('t', 'c', 'full'))->toBeTrue();

    // The date alone is not enough: the first charge after it is.
    Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'UTC'));
    expect(Courses::isLessonLocked('t', 'c', 'full'))->toBeTrue();

    Courses::recordBilling('t', 'c', payments: 2);
    expect(Courses::isLessonLocked('t', 'c', 'full'))->toBeFalse();
});

it('keeps the week drip working as before', function () {
    dripCourse($this, 'schedule', [['w2', ['week' => 2]]]);
    Courses::enroll('u', 'c');

    Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'UTC'));

    expect(Courses::isLessonLocked('u', 'c', 'w2'))->toBeFalse();
});

it('reads an unknown drip mode as no drip', function () {
    dripCourse($this, 'lunar', [['x', ['drip_after' => 9]]]);

    expect(Courses::isLessonLocked('u', 'c', 'x'))->toBeFalse();
});
