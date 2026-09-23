<?php

use Goldnead\Courses\Events\LessonUnlocked;
use Goldnead\Courses\Facades\Courses;
use Goldnead\Courses\Tests\Fakes\FakeSubscription;
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
    Catalogue::$entries = ['monthly-membership' => ['grants' => ['membership']], 'yearly-membership' => ['grants' => ['membership']]];
    Subscriptions::$plans = [];

    $id = $this->makeCourse('club', ['product' => 'membership', 'drip_mode' => 'payments', 'on_payment_failure' => 'revoke']);
    $this->makeLesson($id, 'first', ['sort_order' => 1, 'drip_after' => 1]);
    $this->makeLesson($id, 'second', ['sort_order' => 2, 'drip_after' => 2]);
    $this->makeCourse('unrelated', ['on_payment_failure' => 'revoke']);

    $this->user = tap(User::make()->id('buyer')->email('buyer@example.test'))->save();
    // What payments writes for a subscription: its source and the first payment's id.
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'membership', 'statamic-payments', 'tr_first');
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'unrelated', 'test');
});

afterEach(function () {
    Carbon::setTestNow();
});

function subscription(array $attributes = []): FakeSubscription
{
    return new FakeSubscription(...[
        'id' => 1,
        'email' => 'buyer@example.test',
        'product' => 'monthly-membership',
        'times_charged' => 0,
        'starts_at' => Carbon::now()->addMonth(),
        'paymentRefs' => ['tr_first'],
        ...$attributes,
    ]);
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
        ->and(Courses::canAccess('buyer', 'unrelated'))->toBeTrue()
        ->and(Courses::hold('buyer', 'club'))->toMatchArray(['subscription_id' => '1', 'blocks' => true]);

    event(new SubscriptionRenewed(subscription(['times_charged' => 1])));

    expect(Courses::canAccess('buyer', 'club'))->toBeTrue()
        ->and(Courses::hold('buyer', 'club'))->toBeNull();
});

it('opens the course again when the learner buys a new subscription after a failed one', function () {
    event(new SubscriptionStarted(subscription()));
    event(new SubscriptionCycleFailed(subscription()));
    expect(Courses::canAccess('buyer', 'club'))->toBeFalse();

    // The old one is given up; a new one is bought and paid.
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'membership', 'statamic-payments', 'tr_new');
    event(new SubscriptionStarted(subscription(['id' => 2, 'product' => 'yearly-membership', 'paymentRefs' => ['tr_new']])));

    expect(Courses::canAccess('buyer', 'club'))->toBeTrue()
        ->and(Courses::hold('buyer', 'club'))->toBeNull();
});

it('keeps a course open that another grant opens, whatever the failed subscription does', function () {
    event(new SubscriptionStarted(subscription()));
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'membership', 'manual', 'lifetime');

    event(new SubscriptionCycleFailed(subscription()));

    expect(Courses::canAccess('buyer', 'club'))->toBeTrue()
        ->and(Courses::hold('buyer', 'club'))->toMatchArray(['subscription_id' => '1', 'blocks' => false]);
});

it('does not let the renewal of one subscription lift the hold another set', function () {
    event(new SubscriptionStarted(subscription()));
    event(new SubscriptionCycleFailed(subscription()));

    event(new SubscriptionRenewed(subscription(['id' => 9, 'times_charged' => 3, 'paymentRefs' => ['tr_other']])));

    expect(Courses::canAccess('buyer', 'club'))->toBeFalse();
});

it('keeps a hold set by hand absolute', function () {
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'membership', 'manual', 'lifetime');

    Courses::suspendAccess('buyer', 'club');

    expect(Courses::canAccess('buyer', 'club'))->toBeFalse();
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
