<?php

use Goldnead\Courses\Events\CourseAccessRestored;
use Goldnead\Courses\Events\CourseAccessSuspended;
use Goldnead\Courses\Events\DripPaused;
use Goldnead\Courses\Events\DripResumed;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('keeps everything as it is by default: the paid period runs out on its own', function () {
    $id = $this->makeCourse('c');
    $this->makeLesson($id, 'l');
    Entitlements::grant(new SubjectReference('user', 'u'), 'c', 'test');

    expect(Courses::paymentFailed('u', 'c'))->toBe('keep')
        ->and(Courses::canAccess('u', 'c'))->toBeTrue();
});

it('shuts the course at once when the course says revoke, and opens it when the money arrives', function () {
    $id = $this->makeCourse('c', ['on_payment_failure' => 'revoke']);
    $this->makeLesson($id, 'l');
    Entitlements::grant(new SubjectReference('user', 'u'), 'c', 'test');
    Event::fake([CourseAccessSuspended::class, CourseAccessRestored::class]);

    Courses::paymentFailed('u', 'c');

    expect(Courses::canAccess('u', 'c'))->toBeFalse();
    Event::assertDispatched(CourseAccessSuspended::class, fn ($e) => $e->userId === 'u' && $e->courseSlug === 'c' && $e->reason === 'payment_failed');

    Courses::paymentFailed('u', 'c');
    Event::assertDispatchedTimes(CourseAccessSuspended::class, 1);

    Courses::paymentRecovered('u', 'c');

    expect(Courses::canAccess('u', 'c'))->toBeTrue();
    Event::assertDispatched(CourseAccessRestored::class, fn ($e) => $e->reason === 'payment_recovered');
});

it('pauses the drip and moves later lessons on by the length of the pause', function () {
    $id = $this->makeCourse('c', ['drip_mode' => 'days', 'on_payment_failure' => 'pause_drip']);
    $this->makeLesson($id, 'day3', ['drip_after' => 3]);
    $this->makeLesson($id, 'day10', ['drip_after' => 10]);
    Event::fake([DripPaused::class, DripResumed::class]);

    Courses::enroll('u', 'c');

    // Day 1: a payment fails. Day 5: day 3 would be open, but the clock stood still.
    Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00', 'UTC'));
    Courses::paymentFailed('u', 'c');

    Carbon::setTestNow(Carbon::parse('2026-09-06 10:00:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'day3'))->toBeTrue()
        ->and(Courses::lesson('u', 'c', 'day3')['lock_reason'])->toBe('paused')
        ->and(Courses::lesson('u', 'c', 'day3')['available_at'])->toBeNull()
        ->and(Courses::canAccess('u', 'c'))->toBeFalse(); // no grant in this test; the pause itself shuts nothing

    // The money arrives after four days of pause.
    Courses::paymentRecovered('u', 'c');
    Event::assertDispatched(DripResumed::class, fn ($e) => $e->pausedSeconds === 4 * 86400);

    // day 3 now opens on day 3 + 4 = 2026-09-08, day 10 on 2026-09-15.
    expect(Carbon::parse(Courses::lesson('u', 'c', 'day3')['available_at'])->utc()->toDateTimeString())->toBe('2026-09-08 10:00:00')
        ->and(Carbon::parse(Courses::lesson('u', 'c', 'day10')['available_at'])->utc()->toDateTimeString())->toBe('2026-09-15 10:00:00');

    Carbon::setTestNow(Carbon::parse('2026-09-08 10:00:00', 'UTC'));
    expect(Courses::isLessonLocked('u', 'c', 'day3'))->toBeFalse();

    Event::assertDispatchedTimes(DripPaused::class, 1);
});

it('lets a lesson already open stay open through a pause', function () {
    $id = $this->makeCourse('c', ['drip_mode' => 'days', 'on_payment_failure' => 'pause_drip']);
    $this->makeLesson($id, 'day1', ['drip_after' => 1]);

    Courses::enroll('u', 'c');
    Carbon::setTestNow(Carbon::parse('2026-09-03 10:00:00', 'UTC'));
    Courses::paymentFailed('u', 'c');

    expect(Courses::isLessonLocked('u', 'c', 'day1'))->toBeFalse();
});

it('lifts both holds on recovery, even when the course mode changed in between', function () {
    $id = $this->makeCourse('c', ['on_payment_failure' => 'revoke', 'drip_mode' => 'days']);
    $this->makeLesson($id, 'l');
    Entitlements::grant(new SubjectReference('user', 'u'), 'c', 'test');

    Courses::pauseDrip('u', 'c');
    Courses::paymentFailed('u', 'c');

    Courses::paymentRecovered('u', 'c');

    $enrollment = Courses::enroll('u', 'c');

    expect($enrollment->drip_paused_at)->toBeNull()
        ->and($enrollment->access_suspended_at)->toBeNull()
        ->and(Courses::canAccess('u', 'c'))->toBeTrue();
});

it('answers null for a course that does not exist', function () {
    expect(Courses::paymentFailed('u', 'nope'))->toBeNull();
});
