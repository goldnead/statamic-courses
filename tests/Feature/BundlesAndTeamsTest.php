<?php

use Goldnead\Courses\Events\TeamMemberAdded;
use Goldnead\Courses\Events\TeamMemberRemoved;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Models\TeamMember;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

beforeEach(function () {
    $this->makeCourse('basics', ['bundles' => ['choir-pack']]);
    $this->makeCourse('advanced', ['bundles' => ['choir-pack']]);
    $this->makeCourse('solo');
});

it('opens every course that lists a bundle to a holder of that bundle', function () {
    Entitlements::grant(new SubjectReference('user', 'u'), 'choir-pack', 'test');

    expect(Courses::canAccess('u', 'basics'))->toBeTrue()
        ->and(Courses::canAccess('u', 'advanced'))->toBeTrue()
        ->and(Courses::canAccess('u', 'solo'))->toBeFalse();
});

it('still opens a bundled course by its own product', function () {
    Entitlements::grant(new SubjectReference('user', 'u'), 'basics', 'test');

    expect(Courses::canAccess('u', 'basics'))->toBeTrue()
        ->and(Courses::canAccess('u', 'advanced'))->toBeFalse();
});

describe('teams', function () {
    beforeEach(function () {
        $this->makeCourse('team-course', ['team_seats' => 2]);

        $this->owner = tap(User::make()->id('owner')->email('owner@example.test'))->save();
        $this->member = tap(User::make()->id('member')->email('Member@Example.test'))->save();
        $this->other = tap(User::make()->id('other')->email('other@example.test'))->save();

        Entitlements::grant(new SubjectReference('user', 'owner'), 'team-course', 'test');
    });

    it('lets a buyer give a seat to somebody by email, who then gets in', function () {
        Event::fake([TeamMemberAdded::class]);

        expect(Courses::canAccess($this->member, 'team-course'))->toBeFalse();

        expect(Courses::addTeamMember($this->owner, 'team-course', 'member@example.test'))->not->toBeNull();

        expect(Courses::canAccess($this->member, 'team-course'))->toBeTrue()
            ->and(Courses::canAccess($this->other, 'team-course'))->toBeFalse();

        Event::assertDispatched(TeamMemberAdded::class, fn ($e) => $e->ownerId === 'owner' && $e->email === 'member@example.test');
    });

    it('stops at the number of seats the course carries', function () {
        Courses::addTeamMember($this->owner, 'team-course', 'a@example.test');
        Courses::addTeamMember($this->owner, 'team-course', 'b@example.test');

        expect(Courses::addTeamMember($this->owner, 'team-course', 'c@example.test'))->toBeNull()
            ->and(Courses::team($this->owner, 'team-course'))->toMatchArray(['seats' => 2, 'used' => 2, 'left' => 0]);

        // Adding somebody twice takes no second seat.
        expect(Courses::addTeamMember($this->owner, 'team-course', 'a@example.test'))->not->toBeNull();
    });

    it('frees the seat and closes the course when the buyer removes a member', function () {
        Event::fake([TeamMemberRemoved::class]);
        Courses::addTeamMember($this->owner, 'team-course', 'member@example.test');

        expect(Courses::removeTeamMember($this->owner, 'team-course', 'member@example.test'))->toBeTrue()
            ->and(Courses::canAccess($this->member, 'team-course'))->toBeFalse()
            ->and(Courses::team($this->owner, 'team-course')['left'])->toBe(2);

        Event::assertDispatched(TeamMemberRemoved::class);
    });

    it('closes the course for the whole team when the buyer loses it', function () {
        Courses::addTeamMember($this->owner, 'team-course', 'member@example.test');

        Entitlements::revoke(Entitlements::forSubject(new SubjectReference('user', 'owner'))->first(), 'refund');

        expect(Courses::canAccess($this->member, 'team-course'))->toBeFalse();
    });

    it('closes it for the team while the buyer is suspended for a failed payment', function () {
        Courses::addTeamMember($this->owner, 'team-course', 'member@example.test');
        Courses::suspendAccess($this->owner, 'team-course');

        expect(Courses::canAccess($this->member, 'team-course'))->toBeFalse();
    });

    it('never hands out more seats than there are, even when a seat is taken between counting and inserting', function () {
        Courses::addTeamMember($this->owner, 'team-course', 'a@example.test');

        // Another request took seat 2 after this one counted: the unique
        // slot index sends this one on, and there is no seat 3.
        TeamMember::query()->create(['owner_id' => 'owner', 'product' => 'team-course', 'email' => 'racer@example.test', 'slot' => 2]);

        expect(Courses::addTeamMember($this->owner, 'team-course', 'b@example.test'))->toBeNull()
            ->and(TeamMember::query()->where('owner_id', 'owner')->count())->toBe(2);
    });

    it('gives a bundle one team for all its courses, with the seats counted once', function () {
        $this->makeCourse('part-one', ['bundles' => ['team-pack'], 'team_seats' => 2]);
        $this->makeCourse('part-two', ['bundles' => ['team-pack'], 'team_seats' => 2]);
        Entitlements::grant(new SubjectReference('user', 'owner'), 'team-pack', 'test');

        Courses::addTeamMember($this->owner, 'part-one', 'member@example.test');
        Courses::addTeamMember($this->owner, 'part-two', 'x@example.test');

        expect(Courses::canAccess($this->member, 'part-one'))->toBeTrue()
            ->and(Courses::canAccess($this->member, 'part-two'))->toBeTrue()
            ->and(Courses::team($this->owner, 'part-one'))->toMatchArray(['product' => 'team-pack', 'seats' => 2, 'used' => 2, 'left' => 0])
            ->and(Courses::addTeamMember($this->owner, 'part-two', 'y@example.test'))->toBeNull();
    });

    it('refuses seats to somebody who does not hold the course, to a course without seats, and to a team member', function () {
        Courses::addTeamMember($this->owner, 'team-course', 'member@example.test');

        expect(Courses::addTeamMember($this->other, 'team-course', 'x@example.test'))->toBeNull()
            ->and(Courses::addTeamMember($this->member, 'team-course', 'x@example.test'))->toBeNull()
            ->and(Courses::addTeamMember($this->owner, 'solo', 'x@example.test'))->toBeNull()
            ->and(Courses::addTeamMember($this->owner, 'team-course', 'not an address'))->toBeNull()
            ->and(Courses::addTeamMember($this->owner, 'team-course', 'owner@example.test'))->toBeNull()
            ->and(Courses::team($this->other, 'team-course'))->toBeNull();
    });
});
