<?php

use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Events\CourseCompleted;
use Goldnead\Courses\Events\LessonCompleted;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\Enrollment;
use Goldnead\Courses\Models\LessonEvent;
use Goldnead\Courses\Models\LessonState;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    $course = $this->makeCourse('c');
    $this->makeLesson($course, 'video', ['sort_order' => 1, 'video_duration' => '10:00']);
    $this->makeLesson($course, 'reading', ['sort_order' => 2, 'item_type' => 'text']);
});

it('completes a video by itself at the threshold', function () {
    $at89 = Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 534, 'resume_seconds' => 534]);
    expect($at89['progress']['status'])->toBe('in_progress')
        ->and($at89['progress']['completion_percent'])->toBe(89);

    $at90 = Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 540, 'resume_seconds' => 540]);
    expect($at90['progress']['status'])->toBe('completed')
        ->and($at90['progress']['completion_source'])->toBe('auto');
});

it('never lets watched time shrink when the learner seeks back', function () {
    Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 300, 'resume_seconds' => 300]);
    $after = Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 10, 'resume_seconds' => 10]);

    expect($after['progress']['watched_seconds'])->toBe(300)
        ->and($after['progress']['resume_seconds'])->toBe(10);
});

it('lets a manual "not done" override the watch threshold', function () {
    Courses::setLessonCompletion('u', 'c', 'video', false);
    $after = Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 600, 'resume_seconds' => 600]);

    expect($after['progress']['status'])->toBe('in_progress')
        ->and($after['progress']['completion_source'])->toBe('manual');
});

it('acknowledges text lessons but not videos', function () {
    expect(Courses::acknowledgeLesson('u', 'c', 'video'))->toBeNull();

    $reading = Courses::acknowledgeLesson('u', 'c', 'reading');

    expect($reading['progress']['status'])->toBe('completed')
        ->and(LessonState::query()->where('lesson_slug', 'reading')->value('manual_completion_state'))->toBe('completed');
});

it('writes one row per learner and lesson', function () {
    Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 60, 'resume_seconds' => 60]);
    Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => 120, 'resume_seconds' => 120]);
    Courses::setLessonCompletion('u', 'c', 'video', true);

    expect(LessonState::query()->count())->toBe(1);
});

it('logs start, quarter marks and automatic completion once each', function () {
    foreach ([60, 160, 320, 460, 560, 600, 600] as $seconds) {
        Courses::updateLessonProgress('u', 'c', 'video', ['watched_seconds' => $seconds, 'resume_seconds' => $seconds]);
    }

    expect(LessonEvent::query()->orderBy('id')->pluck('event_type')->all())->toBe([
        'lesson.started',
        'lesson.progress_25',
        'lesson.progress_50',
        'lesson.progress_75',
        'lesson.completed_auto',
    ]);
});

it('logs every manual toggle', function () {
    Courses::setLessonCompletion('u', 'c', 'reading', true);
    Courses::setLessonCompletion('u', 'c', 'reading', false);
    Courses::setLessonCompletion('u', 'c', 'reading', true);

    expect(LessonEvent::query()->orderBy('id')->pluck('event_type')->all())->toBe([
        'lesson.completed_manual', 'lesson.reopened_manual', 'lesson.completed_manual',
    ]);
});

it('records no events when the log is switched off', function () {
    config()->set('courses.record_events', false);

    Courses::setLessonCompletion('u', 'c', 'reading', true);

    expect(LessonEvent::query()->count())->toBe(0);
});

it('fires LessonCompleted on the transition and CourseCompleted once', function () {
    Event::fake([LessonCompleted::class, CourseCompleted::class]);

    Courses::setLessonCompletion('u', 'c', 'video', true);
    Courses::setLessonCompletion('u', 'c', 'video', true);
    Event::assertDispatchedTimes(LessonCompleted::class, 1);
    Event::assertNotDispatched(CourseCompleted::class);

    Courses::acknowledgeLesson('u', 'c', 'reading');
    Event::assertDispatchedTimes(CourseCompleted::class, 1);

    // Reopened and completed again: a second lesson completion, not a second course.
    Courses::setLessonCompletion('u', 'c', 'reading', false);
    Courses::setLessonCompletion('u', 'c', 'reading', true);
    Event::assertDispatchedTimes(LessonCompleted::class, 3);
    Event::assertDispatchedTimes(CourseCompleted::class, 1);
});

it('fires CourseCompleted once when a concurrent request completes the course first', function () {
    Event::fake([LessonCompleted::class, CourseCompleted::class]);
    Courses::setLessonCompletion('u', 'c', 'video', true);
    Enrollment::query()->create([
        'user_id' => 'u',
        'course_entry_id' => Entry::query()->where('collection', 'courses')->where('slug', 'c')->first()->id(),
        'current_week' => 1,
        'started_at' => now(),
    ]);

    // The other request slips in between this request's read of the
    // enrollment and its write: it runs the same completion path to the end.
    $raced = false;
    Enrollment::retrieved(function (Enrollment $enrollment) use (&$raced) {
        if ($raced) {
            return;
        }
        $raced = true;

        $progress = app(CourseProgress::class);
        $complete = new ReflectionMethod($progress, 'markCourseCompleted');
        $complete->invoke($progress, [
            'user_id' => $enrollment->user_id,
            'course' => ['id' => $enrollment->course_entry_id, 'slug' => 'c'],
        ]);
    });

    Courses::acknowledgeLesson('u', 'c', 'reading');

    expect($raced)->toBeTrue();
    Event::assertDispatchedTimes(CourseCompleted::class, 1);
});

it('accepts a Statamic user as the learner', function () {
    $user = User::make()->id('abc-123')->email('a@example.test');

    Courses::setLessonCompletion($user, 'c', 'reading', true);

    expect(LessonState::query()->value('user_id'))->toBe('abc-123');
});
