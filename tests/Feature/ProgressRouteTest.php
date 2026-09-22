<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Tests\Fixtures\Learner;
use Goldnead\Entitlements\Facades\Entitlements;
use Statamic\Auth\Eloquent\User as EloquentUser;
use Statamic\Facades\User;

beforeEach(function () {
    $this->learner = Learner::query()->create(['email' => 'a@example.test']);

    $course = $this->makeCourse('cvt-101', ['sequencing_mode' => 'lesson']);
    $this->makeLesson($course, 'basics', ['sort_order' => 1, 'item_type' => 'text']);
    $this->makeLesson($course, 'video', ['sort_order' => 2, 'video_duration' => '10:00']);
    $this->makeLesson($course, 'quiz', ['sort_order' => 3, 'item_type' => 'quiz']);

    Entitlements::grant($this->learner, 'cvt-101', 'test');

    $this->url = route('statamic.courses.progress');
});

function actAs(?Learner $learner): void
{
    User::shouldReceive('current')->andReturn($learner ? (new EloquentUser)->model($learner) : null);
}

it('is mounted under the action prefix inside the web group', function () {
    $route = app('router')->getRoutes()->getByName('statamic.courses.progress');

    expect($route->uri())->toBe('!/courses/progress')
        ->and($route->methods())->toContain('POST')
        ->and($route->gatherMiddleware())->toContain('web');
});

it('turns a guest away', function () {
    actAs(null);

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'acknowledge'])
        ->assertStatus(401);

    expect(LessonState::query()->count())->toBe(0);
});

it('turns a learner without access away', function () {
    actAs(Learner::query()->create(['email' => 'b@example.test']));

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'acknowledge'])
        ->assertStatus(403);

    expect(LessonState::query()->count())->toBe(0);
});

it('answers 404 for a course that does not exist', function () {
    actAs($this->learner);

    $this->postJson($this->url, ['course' => 'nope', 'lesson' => 'basics', 'action' => 'acknowledge'])->assertStatus(404);
});

it('validates the action', function () {
    actAs($this->learner);

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'delete'])
        ->assertStatus(422)->assertJsonValidationErrors('action');
});

it('acknowledges a lesson and answers with its fresh state', function () {
    actAs($this->learner);

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'acknowledge'])
        ->assertOk()
        ->assertJsonPath('lesson.progress.status', 'completed');
});

it('records playback progress', function () {
    actAs($this->learner);
    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'basics');

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'video', 'action' => 'progress', 'watched_seconds' => 560, 'resume_seconds' => 560])
        ->assertOk()
        ->assertJsonPath('lesson.progress.status', 'completed');
});

it('refuses a locked lesson and a quiz ticked by hand', function () {
    actAs($this->learner);

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'video', 'action' => 'complete'])->assertStatus(422);

    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'basics');
    Courses::setLessonCompletion($this->learner, 'cvt-101', 'video', true);

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'quiz', 'action' => 'complete'])->assertStatus(422);
});

it('redirects a form post to a path on this site, never to another host', function () {
    actAs($this->learner);

    $this->from('/courses/cvt-101')
        ->post($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'acknowledge', '_redirect' => '/done'])
        ->assertRedirect('/done');

    $this->from('/courses/cvt-101')
        ->post($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'acknowledge', '_redirect' => '//evil.test/x'])
        ->assertRedirect('/courses/cvt-101');
});

it('does not register the route at all when switched off at boot', function () {
    config()->set('courses.routes.enabled', false);
    app('router')->name('probe-off.')->group(__DIR__.'/../../routes/actions.php');

    config()->set('courses.routes.enabled', true);
    app('router')->name('probe-on.')->group(__DIR__.'/../../routes/actions.php');
    app('router')->getRoutes()->refreshNameLookups();

    expect(app('router')->getRoutes()->getByName('probe-off.courses.progress'))->toBeNull()
        ->and(app('router')->getRoutes()->getByName('probe-on.courses.progress'))->not->toBeNull();
});

it('answers 404 when the route is switched off', function () {
    actAs($this->learner);
    config()->set('courses.routes.enabled', false);

    $this->postJson($this->url, ['course' => 'cvt-101', 'lesson' => 'basics', 'action' => 'acknowledge'])->assertNotFound();

    expect(LessonState::query()->count())->toBe(0);
});
