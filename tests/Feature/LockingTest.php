<?php

use Goldnead\Courses\Facades\Courses;
use Statamic\Facades\Entry;

function lockOf(array $outline, string $slug): bool
{
    foreach ($outline['sections'] as $section) {
        foreach ($section['lessons'] as $lesson) {
            if ($lesson['slug'] === $slug) {
                return $lesson['is_locked'];
            }
        }
    }

    throw new RuntimeException("No lesson {$slug}");
}

it('locks a whole section until the previous section is completed', function () {
    $course = $this->makeCourse('c', ['sequencing_mode' => 'section']);
    $this->makeLesson($course, 'a1', ['section_key' => 'a', 'section_order' => 1, 'sort_order' => 1]);
    $this->makeLesson($course, 'a2', ['section_key' => 'a', 'section_order' => 1, 'sort_order' => 2]);
    $this->makeLesson($course, 'b1', ['section_key' => 'b', 'section_order' => 2, 'sort_order' => 1]);

    $outline = Courses::outline('u', 'c');
    expect(lockOf($outline, 'a2'))->toBeFalse()
        ->and(lockOf($outline, 'b1'))->toBeTrue();

    Courses::setLessonCompletion('u', 'c', 'a1', true);
    expect(lockOf(Courses::outline('u', 'c'), 'b1'))->toBeTrue();

    Courses::setLessonCompletion('u', 'c', 'a2', true);
    expect(lockOf(Courses::outline('u', 'c'), 'b1'))->toBeFalse();
});

it('locks a lesson until its prerequisites are completed', function () {
    $course = $this->makeCourse('c');
    $basics = $this->makeLesson($course, 'basics', ['sort_order' => 1]);
    $this->makeLesson($course, 'advanced', ['sort_order' => 2, 'prerequisite_lessons' => [$basics]]);

    expect(Courses::isLessonLocked('u', 'c', 'advanced'))->toBeTrue();

    Courses::setLessonCompletion('u', 'c', 'basics', true);

    expect(Courses::isLessonLocked('u', 'c', 'advanced'))->toBeFalse();
});

it('locks later phases until every earlier phase is completed', function () {
    $course = $this->makeCourse('c');
    $this->makeLesson($course, 'p1a', ['sort_order' => 1, 'phase_key' => 'p1', 'phase_order' => 1]);
    $this->makeLesson($course, 'p2a', ['sort_order' => 2, 'phase_key' => 'p2', 'phase_order' => 2]);

    expect(Courses::isLessonLocked('u', 'c', 'p2a'))->toBeTrue();

    Courses::setLessonCompletion('u', 'c', 'p1a', true);

    expect(Courses::isLessonLocked('u', 'c', 'p2a'))->toBeFalse();
});

it('refuses to record progress on a locked lesson', function () {
    $course = $this->makeCourse('c', ['sequencing_mode' => 'lesson']);
    $this->makeLesson($course, 'first', ['sort_order' => 1]);
    $this->makeLesson($course, 'second', ['sort_order' => 2]);

    expect(Courses::setLessonCompletion('u', 'c', 'second', true))->toBeNull()
        ->and(Courses::summary('u', 'c')['completed_lessons'])->toBe(0);
});

it('never shows a completed lesson as locked', function () {
    $course = $this->makeCourse('c');
    $basics = $this->makeLesson($course, 'basics', ['sort_order' => 1]);
    $this->makeLesson($course, 'advanced', ['sort_order' => 2, 'prerequisite_lessons' => [$basics]]);

    Courses::setLessonCompletion('u', 'c', 'basics', true);
    Courses::setLessonCompletion('u', 'c', 'advanced', true);
    // Reopening the prerequisite would lock `advanced` again, but it is done.
    Courses::setLessonCompletion('u', 'c', 'basics', false);

    expect(Courses::isLessonLocked('u', 'c', 'advanced'))->toBeFalse();
});

it('completes a milestone once every other lesson of its phase is completed', function () {
    $course = $this->makeCourse('c');
    $this->makeLesson($course, 'l1', ['sort_order' => 1, 'phase_key' => 'p1']);
    $this->makeLesson($course, 'l2', ['sort_order' => 2, 'phase_key' => 'p1']);
    $this->makeLesson($course, 'm', ['sort_order' => 3, 'phase_key' => 'p1', 'item_type' => 'milestone']);

    Courses::setLessonCompletion('u', 'c', 'l1', true);
    expect(Courses::lesson('u', 'c', 'm')['progress']['status'])->toBe('not_started');

    Courses::setLessonCompletion('u', 'c', 'l2', true);
    $milestone = Courses::lesson('u', 'c', 'm')['progress'];

    expect($milestone['status'])->toBe('completed')
        ->and($milestone['completion_source'])->toBe('auto')
        ->and(Courses::summary('u', 'c')['status'])->toBe('completed');
});

it('completes a milestone by its named prerequisites when it has them', function () {
    $course = $this->makeCourse('c');
    $l1 = $this->makeLesson($course, 'l1', ['sort_order' => 1, 'phase_key' => 'p1']);
    $this->makeLesson($course, 'l2', ['sort_order' => 2, 'phase_key' => 'p1']);
    $this->makeLesson($course, 'm', ['sort_order' => 3, 'phase_key' => 'p1', 'item_type' => 'milestone', 'prerequisite_lessons' => [$l1]]);

    Courses::setLessonCompletion('u', 'c', 'l1', true);

    expect(Courses::lesson('u', 'c', 'm')['progress']['status'])->toBe('completed');
});

it('ignores prerequisites that belong to another course', function () {
    $other = $this->makeCourse('other');
    $foreign = $this->makeLesson($other, 'foreign');
    $course = $this->makeCourse('c');
    $this->makeLesson($course, 'l', ['prerequisite_lessons' => [$foreign]]);

    expect(Courses::isLessonLocked('u', 'c', 'l'))->toBeFalse()
        ->and(Entry::find($foreign))->not->toBeNull();
});
