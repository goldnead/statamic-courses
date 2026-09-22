<?php

use Goldnead\Courses\Access\ClosedCourseAccess;
use Goldnead\Courses\Access\EntitlementsCourseAccess;
use Goldnead\Courses\Contracts\CourseAccess;
use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Statamic\Facades\User;

beforeEach(function () {
    $this->makeCourse('cvt-101');
    $this->makeCourse('bundled', ['product' => 'choiraccelerator']);
});

it('asks entitlements when it is installed', function () {
    expect(app(CourseAccess::class))->toBeInstanceOf(EntitlementsCourseAccess::class);
});

it('opens a course to a learner holding its product, named after the course by default', function () {
    expect(Courses::canAccess('user-1', 'cvt-101'))->toBeFalse();

    Entitlements::grant(learner('user-1'), 'cvt-101', 'test');

    expect(Courses::canAccess('user-1', 'cvt-101'))->toBeTrue()
        ->and(Courses::canAccess('user-2', 'cvt-101'))->toBeFalse();
});

it('uses the product field when the course names one', function () {
    Entitlements::grant(learner('user-1'), 'choiraccelerator', 'test');

    expect(Courses::canAccess('user-1', 'bundled'))->toBeTrue();
});

it('stops opening the course once the grant is revoked', function () {
    $grant = Entitlements::grant(learner('user-1'), 'cvt-101', 'test');
    Entitlements::revoke($grant, 'refund');

    expect(Courses::canAccess('user-1', 'cvt-101'))->toBeFalse();
});

it('looks a flat-file Statamic user up under the configured subject type', function () {
    $user = User::make()->id('abc-123')->email('a@example.test');
    Entitlements::grant(new SubjectReference('member', 'abc-123'), 'cvt-101', 'test');

    expect(Courses::canAccess($user, 'cvt-101'))->toBeFalse();

    config()->set('courses.entitlements.subject_type', 'member');

    expect(Courses::canAccess($user, 'cvt-101'))->toBeTrue();
});

function learner(string $id): SubjectReference
{
    return new SubjectReference('user', $id);
}

it('keeps a course closed with no access provider at all', function () {
    app()->instance(CourseAccess::class, new ClosedCourseAccess);
    app()->forgetInstance(CourseProgress::class);
    Courses::clearResolvedInstances();

    expect(Courses::canAccess('user-1', 'cvt-101'))->toBeFalse();
});

it('refuses a guest and an unknown course', function () {
    expect(Courses::canAccess(null, 'cvt-101'))->toBeFalse()
        ->and(Courses::canAccess('user-1', 'nope'))->toBeFalse();
});
