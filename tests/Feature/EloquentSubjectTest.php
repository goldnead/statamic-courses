<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\LessonState;
use Goldnead\Courses\Tests\Fixtures\Learner;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Statamic\Auth\Eloquent\User as EloquentUser;

/*
 * adriangoldner.com runs Statamic's eloquent user repository and grants under
 * the morph class of App\Models\User. `User::current()` there is a
 * Statamic\Auth\Eloquent\User, which wraps the model but is not one.
 */

beforeEach(function () {
    $this->learner = Learner::query()->create(['email' => 'a@example.test']);
    $course = $this->makeCourse('cvt-101');
    $this->makeLesson($course, 'intro', ['item_type' => 'text']);

    Entitlements::grant($this->learner, 'cvt-101', 'test');
});

it('accepts a real Eloquent model as the learner', function () {
    expect(Courses::canAccess($this->learner, 'cvt-101'))->toBeTrue();

    Courses::acknowledgeLesson($this->learner, 'cvt-101', 'intro');

    expect(LessonState::query()->value('user_id'))->toBe((string) $this->learner->id);
});

it('unwraps a Statamic eloquent user to its model', function () {
    $wrapped = (new EloquentUser)->model($this->learner);

    expect(Courses::canAccess($wrapped, 'cvt-101'))->toBeTrue();
});

it('looks a bare id up under the auth model\'s morph class on an eloquent install', function () {
    config()->set('statamic.users.repository', 'eloquent');
    config()->set('auth.providers.users.model', Learner::class);

    expect(Courses::canAccess((string) $this->learner->id, 'cvt-101'))->toBeTrue();
});

it('falls back to the type "user" for flat-file users', function () {
    config()->set('statamic.users.repository', 'file');

    expect(Courses::canAccess((string) $this->learner->id, 'cvt-101'))->toBeFalse();

    Entitlements::grant(new SubjectReference('user', 'file-7'), 'cvt-101', 'test');

    expect(Courses::canAccess('file-7', 'cvt-101'))->toBeTrue();
});

it('lets an explicitly configured type win', function () {
    config()->set('statamic.users.repository', 'eloquent');
    config()->set('auth.providers.users.model', Learner::class);
    config()->set('courses.entitlements.subject_type', 'member');

    expect(Courses::canAccess((string) $this->learner->id, 'cvt-101'))->toBeFalse();
});
