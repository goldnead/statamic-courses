<?php

use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Integrations\CourseMediaAccess;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\PrivateMedia\Contracts\MediaAccess;
use Statamic\Facades\User;

beforeEach(function () {
    // private-media's own answer, as it binds it: by product slug, here a
    // stand-in that knows one resource only.
    $this->app->bind(MediaAccess::class, fn () => new class implements MediaAccess
    {
        public function allows(mixed $user, string $resource, string $path): bool
        {
            return $resource === 'library';
        }
    });

    $this->makeCourse('choir', ['bundles' => ['choir-pack'], 'team_seats' => 2]);
    $this->owner = tap(User::make()->id('owner')->email('owner@example.test'))->save();
    $this->member = tap(User::make()->id('member')->email('member@example.test'))->save();
    $this->bundle = tap(User::make()->id('bundle')->email('bundle@example.test'))->save();
    $this->stranger = tap(User::make()->id('stranger')->email('stranger@example.test'))->save();

    Entitlements::grant(new SubjectReference('user', 'owner'), 'choir', 'test');
    Entitlements::grant(new SubjectReference('user', 'bundle'), 'choir-pack', 'test');
    Courses::addTeamMember($this->owner, 'choir', 'member@example.test');
});

it('answers a course download the way the course answers access: buyer, team member, bundle holder', function () {
    $access = app(MediaAccess::class);
    $resource = CourseMediaAccess::resourceFor('choir');

    expect($access)->toBeInstanceOf(CourseMediaAccess::class)
        ->and($access->allows($this->owner, $resource, 'noten/a.pdf'))->toBeTrue()
        ->and($access->allows($this->member, $resource, 'noten/a.pdf'))->toBeTrue()
        ->and($access->allows($this->bundle, $resource, 'noten/a.pdf'))->toBeTrue()
        ->and($access->allows($this->stranger, $resource, 'noten/a.pdf'))->toBeFalse()
        ->and($access->allows(null, $resource, 'noten/a.pdf'))->toBeFalse();
});

it('shuts a course download while a hold closes the course', function () {
    Courses::suspendAccess($this->owner, 'choir');

    expect(app(MediaAccess::class)->allows($this->owner, 'course:choir', 'a.pdf'))->toBeFalse()
        ->and(app(MediaAccess::class)->allows($this->member, 'course:choir', 'a.pdf'))->toBeFalse();
});

it('leaves every other resource to the access the site had', function () {
    expect(app(MediaAccess::class)->allows($this->stranger, 'library', 'x.pdf'))->toBeTrue()
        ->and(app(MediaAccess::class)->allows($this->owner, 'choir', 'x.pdf'))->toBeFalse();
});
