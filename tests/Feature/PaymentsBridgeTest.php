<?php

use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Events\SubscriptionRenewed;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Statamic\Facades\User;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
    Catalogue::$entries = ['monthly-membership' => ['grants' => ['membership']]];
    Subscriptions::$plans = [];

    $id = $this->makeCourse('club', ['product' => 'membership', 'drip_mode' => 'payments', 'on_payment_failure' => 'revoke']);
    $this->makeLesson($id, 'first', ['sort_order' => 1, 'drip_after' => 1]);
    $this->makeLesson($id, 'second', ['sort_order' => 2, 'drip_after' => 2]);
    $this->makeCourse('unrelated', ['on_payment_failure' => 'revoke']);

    $this->user = tap(User::make()->id('buyer')->email('buyer@example.test'))->save();
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'membership', 'test');
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'unrelated', 'test');
});

afterEach(function () {
    Carbon::setTestNow();
});

function subscription(array $attributes = []): object
{
    return (object) [
        'id' => 1,
        'email' => 'buyer@example.test',
        'product' => 'monthly-membership',
        'times_charged' => 0,
        'starts_at' => Carbon::now()->addMonth(),
        ...$attributes,
    ];
}

it('enrolls the buyer and counts the first payment when the subscription starts', function () {
    event(new SubscriptionStarted(subscription()));

    expect(Courses::isLessonLocked('buyer', 'club', 'first'))->toBeFalse()
        ->and(Courses::isLessonLocked('buyer', 'club', 'second'))->toBeTrue()
        ->and(Courses::enroll('buyer', 'club')->payments_count)->toBe(1);
});

it('opens the next lesson with each renewal and says so', function () {
    Event::fake([LessonUnlocked::class]);
    event(new SubscriptionStarted(subscription()));

    event(new SubscriptionRenewed(subscription(['times_charged' => 1])));

    expect(Courses::isLessonLocked('buyer', 'club', 'second'))->toBeFalse();
    Event::assertDispatched(LessonUnlocked::class, fn ($e) => $e->lessonSlug === 'second' && $e->source === 'billing');
});

it('records the trial end for a plan with a trial', function () {
    Subscriptions::$plans['monthly-membership'] = ['interval' => '1 month', 'times' => null, 'trial_days' => 14, 'trial_amount_cent' => 0];

    event(new SubscriptionStarted(subscription(['starts_at' => Carbon::parse('2026-09-15 10:00:00', 'UTC')])));

    expect(Courses::enroll('buyer', 'club')->trial_until->utc()->toDateTimeString())->toBe('2026-09-15 10:00:00');
});

it('applies the course rule on a failed cycle, only to the courses the subscription pays for', function () {
    event(new SubscriptionStarted(subscription()));

    event(new SubscriptionCycleFailed(subscription()));

    expect(Courses::canAccess('buyer', 'club'))->toBeFalse()
        ->and(Courses::canAccess('buyer', 'unrelated'))->toBeTrue();

    event(new SubscriptionRenewed(subscription(['times_charged' => 1])));

    expect(Courses::canAccess('buyer', 'club'))->toBeTrue();
});

it('matches a course through its bundles as well', function () {
    $this->makeCourse('bonus', ['bundles' => ['membership'], 'on_payment_failure' => 'revoke']);

    event(new SubscriptionCycleFailed(subscription()));

    expect(Courses::canAccess('buyer', 'bonus'))->toBeFalse();
});

it('does nothing for an address without an account', function () {
    event(new SubscriptionCycleFailed(subscription(['email' => 'stranger@example.test'])));

    expect(Courses::canAccess('buyer', 'club'))->toBeTrue();
});
