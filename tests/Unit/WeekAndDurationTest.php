<?php

use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Progress\LockResolver;
use Goldnead\Courses\Support\CourseRepository;
use Illuminate\Support\Carbon;

/*
 * The week arithmetic follows adriangoldner.com's PlanProgressService
 * (tests/Unit/Training/PlanProgressServiceTest.php): not enrolled means week 1
 * is the open one, and `current_week` is where a learner has been moved to.
 */

it('opens week one for a learner who has not enrolled', function () {
    expect((new LockResolver)->openWeek(null))->toBe(1);
});

it('opens the week a learner has been moved to', function () {
    $enrollment = new Enrollment(['current_week' => 3]);

    expect((new LockResolver)->openWeek($enrollment))->toBe(3);
});

it('opens the week the calendar has reached since enrollment', function () {
    $enrollment = new Enrollment(['current_week' => 1, 'started_at' => Carbon::parse('2026-09-01 10:00', 'UTC')]);
    $resolver = new LockResolver;

    expect($resolver->openWeek($enrollment, Carbon::parse('2026-09-01 10:00', 'UTC')))->toBe(1)
        ->and($resolver->openWeek($enrollment, Carbon::parse('2026-09-08 09:59', 'UTC')))->toBe(1)
        ->and($resolver->openWeek($enrollment, Carbon::parse('2026-09-08 10:00', 'UTC')))->toBe(2)
        ->and($resolver->openWeek($enrollment, Carbon::parse('2026-09-29 10:00', 'UTC')))->toBe(5)
        // A clock that runs behind the enrollment date never closes week one.
        ->and($resolver->openWeek($enrollment, Carbon::parse('2026-08-01 10:00', 'UTC')))->toBe(1);
});

it('reads durations the way the source site stored them', function (mixed $input, ?int $seconds) {
    expect(CourseRepository::parseDurationSeconds($input))->toBe($seconds);
})->with([
    ['5:00', 300],
    ['1:02:03', 3723],
    ['90', 90],
    [45, 45],
    [-5, 0],
    ['', null],
    [null, null],
    ['about ten minutes', null],
]);
