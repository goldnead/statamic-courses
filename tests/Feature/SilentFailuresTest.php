<?php

use Goldnead\Courses\CourseProgress;
use Goldnead\Courses\Tests\Fakes\FakeSubscription;
use Goldnead\Entitlements\Facades\Entitlements;
use Goldnead\Entitlements\Support\SubjectReference;
use Goldnead\StatamicPayments\Events\SubscriptionCycleFailed;
use Goldnead\StatamicPayments\Events\SubscriptionStarted;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\User;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00', 'UTC'));
    Catalogue::$entries = ['monthly-membership' => ['grants' => ['membership']]];
    Subscriptions::$plans = [];

    $id = $this->makeCourse('club', ['product' => 'membership', 'drip_mode' => 'after_trial', 'on_payment_failure' => 'revoke']);
    $this->makeLesson($id, 'first', ['sort_order' => 1, 'drip_after' => 2]);

    tap(User::make()->id('buyer')->email('buyer@example.test'))->save();
    Entitlements::grant(new SubjectReference('user', 'buyer'), 'membership', 'statamic-payments', 'tr_first');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('logs a failing plan lookup instead of treating the subscription as having no trial', function () {
    app()->bind(Subscriptions::class, fn () => new class extends Subscriptions
    {
        public function planFor(string $handle): ?array
        {
            throw new RuntimeException('plans table unreachable');
        }
    });
    Log::spy();

    event(new SubscriptionStarted(new FakeSubscription(starts_at: Carbon::now()->addMonth())));

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message, $context = []) => str_contains($message, 'trial') && ($context['exception'] ?? null) instanceof RuntimeException
    );
});

it('logs a subscription whose payments cannot be read when a payment failure is applied', function () {
    $subscription = new class(starts_at: Carbon::now()->addMonth()) extends FakeSubscription
    {
        public function payments(): Collection
        {
            throw new RuntimeException('payments table unreachable');
        }
    };
    Log::spy();

    event(new SubscriptionCycleFailed($subscription));

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message, $context = []) => str_contains($message, 'payments') && ($context['exception'] ?? null) instanceof RuntimeException
    );
});

it('logs the exception itself, with its trace, when a subscription event fails for a course', function () {
    Log::spy();
    $progress = Mockery::mock(app(CourseProgress::class));
    $progress->shouldReceive('enroll')->andThrow(new RuntimeException('enroll failed'));
    $this->app->instance(CourseProgress::class, $progress);

    event(new SubscriptionStarted(new FakeSubscription(starts_at: Carbon::now()->addMonth())));

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message, $context = []) => str_contains($message, 'could not be applied') && ($context['exception'] ?? null) instanceof RuntimeException
    );
});

it('logs a catalogue that cannot be read instead of dropping the grants of a product', function () {
    app()->bind(Catalogue::class, fn () => new class extends Catalogue
    {
        public function find(string $handle): ?array
        {
            throw new RuntimeException('catalogue unreachable');
        }
    });
    Log::spy();

    event(new SubscriptionStarted(new FakeSubscription(starts_at: Carbon::now()->addMonth())));

    Log::shouldHaveReceived('error')->withArgs(
        fn ($message, $context = []) => str_contains($message, 'catalogue') && ($context['product'] ?? null) === 'monthly-membership' && ($context['exception'] ?? null) instanceof RuntimeException
    )->once();
});
