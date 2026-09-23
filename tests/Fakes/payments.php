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
