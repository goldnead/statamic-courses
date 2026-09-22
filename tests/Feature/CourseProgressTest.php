<?php

use Goldnead\Courses\Facades\Courses;
use Statamic\Facades\Entry;

/*
 * Ported from adriangoldner.com tests/Unit/CourseProgressServiceTest.php.
 * The source built its course from a Circle export fixture; this one builds
 * the same shape by hand: two sections, three lessons.
 */

beforeEach(function () {
    $this->courseId = $this->makeCourse('choiraccelerator-modules');

    $this->makeLesson($this->courseId, 'willkommen', [
        'section_key' => 's1', 'section_title' => 'Start', 'section_order' => 1, 'sort_order' => 1,
        'video_duration' => '5:00',
    ]);
    $this->makeLesson($this->courseId, 'atmung-aktivieren', [
        'section_key' => 's1', 'section_title' => 'Start', 'section_order' => 1, 'sort_order' => 2,
    ]);
    $this->makeLesson($this->courseId, 'klangfarben-einstieg', [
        'section_key' => 's2', 'section_title' => 'Klang', 'section_order' => 2, 'sort_order' => 1,
    ]);
});

it('unlocks lesson by lesson only after the previous lessons are completed', function () {
    Entry::find($this->courseId)->set('sequencing_mode', 'lesson')->save();

    $before = Courses::outline('user-1', 'choiraccelerator-modules');

    expect($before['sections'][0]['lessons'][0]['is_locked'])->toBeFalse()
        ->and($before['sections'][0]['lessons'][1]['is_locked'])->toBeTrue()
        ->and($before['sections'][1]['lessons'][0]['is_locked'])->toBeTrue();

    Courses::setLessonCompletion('user-1', 'choiraccelerator-modules', 'willkommen', true);

    $after = Courses::outline('user-1', 'choiraccelerator-modules');

    expect($after['sections'][0]['lessons'][1]['is_locked'])->toBeFalse()
        ->and($after['sections'][1]['lessons'][0]['is_locked'])->toBeTrue();
});

it('aggregates completed lessons and the continue target into the summary', function () {
    Courses::updateLessonProgress('user-1', 'choiraccelerator-modules', 'willkommen', [
        'resume_seconds' => 270,
        'watched_seconds' => 270,
        'video_duration_seconds' => 300,
        'playback_state' => 'ended',
    ]);
    Courses::setLessonCompletion('user-1', 'choiraccelerator-modules', 'atmung-aktivieren', true);

    $summary = Courses::summary('user-1', 'choiraccelerator-modules');

    expect($summary['status'])->toBe('in_progress')
        ->and($summary['total_lessons'])->toBe(3)
        ->and($summary['completed_lessons'])->toBe(2)
        ->and($summary['percent'])->toBe(66)
        ->and($summary['continue_lesson']['slug'])->toBe('klangfarben-einstieg');
});

it('keeps progress per learner', function () {
    Courses::setLessonCompletion('user-1', 'choiraccelerator-modules', 'willkommen', true);

    expect(Courses::summary('user-1', 'choiraccelerator-modules')['completed_lessons'])->toBe(1)
        ->and(Courses::summary('user-2', 'choiraccelerator-modules')['completed_lessons'])->toBe(0)
        ->and(Courses::summary('user-2', 'choiraccelerator-modules')['status'])->toBe('not_started');
});

it('answers null for a course or lesson that does not exist', function () {
    expect(Courses::outline('user-1', 'nope'))->toBeNull()
        ->and(Courses::lesson('user-1', 'choiraccelerator-modules', 'nope'))->toBeNull()
        ->and(Courses::setLessonCompletion('user-1', 'choiraccelerator-modules', 'nope', true))->toBeNull();
});

it('orders sections and lessons and links the neighbours', function () {
    $lesson = Courses::lesson('user-1', 'choiraccelerator-modules', 'atmung-aktivieren');

    expect($lesson['previous_lesson']['slug'])->toBe('willkommen')
        ->and($lesson['next_lesson']['slug'])->toBe('klangfarben-einstieg')
        ->and($lesson['video_duration_seconds'])->toBeNull();

    $outline = Courses::outline('user-1', 'choiraccelerator-modules');

    expect(array_column($outline['sections'], 'key'))->toBe(['s1', 's2']);
});
