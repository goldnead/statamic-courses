<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\LessonState;

/*
 * A mixed course as adriangoldner.com's ChoirAccelerator path has them: a
 * phase of video, text and reflection, a test-out quiz that lets a learner skip
 * that phase, and a phase behind it with an assignment and coaching.
 */

beforeEach(function () {
    $course = $this->makeCourse('lernpfad');
    $this->makeLesson($course, 'video', ['sort_order' => 1, 'phase_key' => 'p1', 'phase_order' => 1, 'video_duration' => '2:00']);
    $this->makeLesson($course, 'reading', ['sort_order' => 2, 'phase_key' => 'p1', 'phase_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($course, 'reflect', ['sort_order' => 3, 'phase_key' => 'p1', 'phase_order' => 1, 'item_type' => 'reflection']);
    $this->makeLesson($course, 'test-out', ['sort_order' => 4, 'phase_key' => 'p2', 'phase_order' => 2, 'item_type' => 'quiz', 'is_test_out' => true]);
    $this->makeLesson($course, 'task', ['sort_order' => 5, 'phase_key' => 'p2', 'phase_order' => 2, 'item_type' => 'assignment']);
    $this->makeLesson($course, 'session', ['sort_order' => 6, 'phase_key' => 'p2', 'phase_order' => 2, 'item_type' => 'coaching']);
});

it('keeps lesson types it does not complete itself instead of turning them into videos', function () {
    expect(Courses::lesson('u', 'lernpfad', 'reflect')['item_type'])->toBe('reflection')
        ->and(Courses::lesson('u', 'lernpfad', 'test-out')['item_type'])->toBe('quiz')
        ->and(Courses::lesson('u', 'lernpfad', 'session')['item_type'])->toBe('coaching');
});

it('keeps a test-out quiz reachable while its phase is locked', function () {
    expect(Courses::isLessonLocked('u', 'lernpfad', 'task'))->toBeTrue()
        ->and(Courses::isLessonLocked('u', 'lernpfad', 'test-out'))->toBeFalse();
});

it('refuses a manual tick on lessons that need their own proof', function () {
    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz'); // opens phase 2

    expect(Courses::setLessonCompletion('u', 'lernpfad', 'task', true))->toBeNull()
        ->and(Courses::setLessonCompletion('u', 'lernpfad', 'test-out', false))->toBeNull()
        ->and(Courses::lesson('u', 'lernpfad', 'task')['progress']['status'])->toBe('not_started');
});

it('treats a reflection as needing its own proof by default', function () {
    expect(Courses::setLessonCompletion('u', 'lernpfad', 'reflect', true))->toBeNull()
        ->and(Courses::completeLesson('u', 'lernpfad', 'reflect', 'reflection', ['text' => 'ok'])['progress']['status'])->toBe('completed');
});

it('marks lessons a test-out skipped, so a template can tell them apart', function () {
    Courses::acknowledgeLesson('u', 'lernpfad', 'reading');
    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz');

    $lessons = collect(Courses::lessons('u', 'lernpfad'))->keyBy('slug');

    expect($lessons['video']['is_skipped'])->toBeTrue()
        ->and($lessons['reading']['is_skipped'])->toBeFalse()
        ->and($lessons['test-out']['is_skipped'])->toBeFalse()
        ->and($lessons['video']['is_completed'])->toBeTrue();
});

it('takes the proof-required list from config', function () {
    config()->set('courses.proof_required_types', []);
    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz');

    expect(Courses::setLessonCompletion('u', 'lernpfad', 'task', true)['progress']['status'])->toBe('completed');
});

it('completes a lesson through the host with a source and a payload', function () {
    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz');
    $task = Courses::completeLesson('u', 'lernpfad', 'task', 'assignment', ['submission' => 'ok']);

    expect($task['progress']['status'])->toBe('completed')
        ->and($task['progress']['item_payload'])->toBe(['submission' => 'ok'])
        ->and(LessonState::query()->where('lesson_slug', 'task')->value('manual_completion_state'))->toBe('completed');
});

it('completes the earlier phases as skipped when a test-out is passed', function () {
    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz');

    $reading = Courses::lesson('u', 'lernpfad', 'reading')['progress'];

    expect($reading['status'])->toBe('completed')
        ->and($reading['item_payload'])->toBe(['skipped' => true])
        ->and(Courses::isLessonLocked('u', 'lernpfad', 'task'))->toBeFalse()
        ->and(Courses::summary('u', 'lernpfad')['completed_lessons'])->toBe(4);
});

it('acknowledges the lesson kinds the source site acknowledged', function (string $slug, bool $accepted) {
    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz');

    expect(Courses::acknowledgeLesson('u', 'lernpfad', $slug) !== null)->toBe($accepted);
})->with([
    ['reading', true],
    ['session', true],
    ['video', false],
    ['reflect', false],
    ['task', false],
]);

it('records unfinished work on a lesson without completing it', function () {
    $attempt = Courses::updateLessonItem('u', 'lernpfad', 'test-out', ['attempts' => 1, 'best_score' => 40]);

    expect($attempt['progress']['status'])->toBe('in_progress')
        ->and($attempt['progress']['item_payload'])->toBe(['attempts' => 1, 'best_score' => 40]);

    Courses::completeLesson('u', 'lernpfad', 'test-out', 'quiz', ['attempts' => 2, 'best_score' => 90]);
    $retake = Courses::updateLessonItem('u', 'lernpfad', 'test-out', ['attempts' => 3]);

    expect($retake['progress']['status'])->toBe('completed')
        ->and($retake['progress']['item_payload'])->toBe(['attempts' => 3, 'best_score' => 90]);
});

it('keeps progress separate across courses', function () {
    $other = $this->makeCourse('cvt-101');
    $this->makeLesson($other, 'reading', ['item_type' => 'text']);

    Courses::acknowledgeLesson('u', 'cvt-101', 'reading');

    expect(Courses::summary('u', 'cvt-101')['completed_lessons'])->toBe(1)
        ->and(Courses::summary('u', 'lernpfad')['completed_lessons'])->toBe(0)
        ->and(Courses::lesson('u', 'lernpfad', 'reading')['progress']['status'])->toBe('not_started');
});
