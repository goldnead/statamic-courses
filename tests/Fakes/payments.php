<?php

/*
 * Stand-ins for the statamic-payments classes the bridge touches: the three
 * subscription events (same constructor shapes as payments 1.x), the
 * catalogue's find() and the plan lookup. The bridge only reads properties,
 * so a plain object stands in for the Subscription model.
 */

namespace Goldnead\StatamicPayments\Events {
    if (! class_exists(SubscriptionStarted::class)) {
        class SubscriptionStarted
        {
            public function __construct(public readonly object $subscription, public readonly ?object $payment = null) {}
        }

        class SubscriptionRenewed
        {
            public function __construct(public readonly object $subscription, public readonly ?object $payment = null) {}
        }

        class SubscriptionCycleFailed
        {
            public function __construct(public readonly object $subscription, public readonly ?object $payment = null, public readonly string $status = 'failed') {}
        }
    }
}

namespace Goldnead\Courses\Tests\Fakes {
    use Illuminate\Support\Collection;

    if (! class_exists(FakeSubscription::class)) {
        /**
         * The Subscription model's shape as the bridge reads it, with
         * payments() answering what the relation would pluck.
         */
        class FakeSubscription
        {
            /** @param list<string> $paymentRefs */
            public function __construct(
                public int|string $id = 1,
                public ?string $email = 'buyer@example.test',
                public string $product = 'monthly-membership',
                public int $times_charged = 0,
                public mixed $starts_at = null,
                public array $paymentRefs = ['tr_first'],
                public ?string $provider_id = 'sub_provider_1',
            ) {}

            public function payments(): Collection
            {
                return collect($this->paymentRefs)->map(fn (string $ref) => ['provider_id' => $ref]);
            }
        }
    }
}

namespace Goldnead\StatamicPayments\Support {
    if (! class_exists(Catalogue::class)) {
        class Catalogue
        {
            /** @var array<string, array<string, mixed>> */
            public static array $entries = [];

            public function find(string $handle): ?array
            {
                return self::$entries[$handle] ?? null;
            }
        }

        class Subscriptions
        {
            /** @var array<string, array<string, mixed>> */
            public static array $plans = [];

            public function planFor(string $handle): ?array
            {
                return self::$plans[$handle] ?? null;
            }
        }
    }
}
