<?php

namespace Goldnead\Courses\Integrations;

use Goldnead\Courses\CourseProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Throwable;

/**
 * statamic-payments' subscription events, turned into what a course needs:
 * the drip by payments (K2) and the payment-failure rule (K5).
 *
 * Registered only when statamic-payments is installed; composer only
 * suggests it. The events are the provider-neutral ones payments fires for
 * Stripe and Mollie alike, so nothing here knows which provider charged.
 *
 * - `SubscriptionStarted`: enrolls the buyer and records the first payment,
 *   and the trial end when the plan has a trial.
 * - `SubscriptionRenewed`: records the count (first payment + cycles) and
 *   lifts a payment hold.
 * - `SubscriptionCycleFailed`: applies the course's `on_payment_failure`.
 *
 * Which courses: those whose product, or one of whose `bundles`, is the
 * subscription's product or one of the entitlements it grants. Which
 * learner: the Statamic user with the subscription's email address, the same
 * key payments grants entitlements under. No such user yet: nothing to do,
 * logged at debug level; the grant itself is payments' business.
 */
class PaymentsBridge
{
    public const STARTED = 'Goldnead\\StatamicPayments\\Events\\SubscriptionStarted';

    public const RENEWED = 'Goldnead\\StatamicPayments\\Events\\SubscriptionRenewed';

    public const CYCLE_FAILED = 'Goldnead\\StatamicPayments\\Events\\SubscriptionCycleFailed';

    public const CATALOGUE = 'Goldnead\\StatamicPayments\\Support\\Catalogue';

    public const SUBSCRIPTIONS = 'Goldnead\\StatamicPayments\\Support\\Subscriptions';

    public function __construct(protected CourseProgress $courses) {}

    public static function available(): bool
    {
        return class_exists(self::CYCLE_FAILED) && class_exists(self::RENEWED) && class_exists(self::STARTED);
    }

    public function started(object $event): void
    {
        $subscription = $event->subscription ?? null;

        $this->each($subscription, function (StatamicUser $learner, string $slug) use ($subscription): void {
            $this->courses->enroll($learner, $slug);
            $this->courses->recordBilling($learner, $slug, $this->paymentsOf($subscription), $this->trialUntil($subscription));
            // A new purchase is paid: whatever an earlier, failed subscription
            // held back is lifted.
            $this->courses->paymentRecovered($learner, $slug, null, 'new_purchase');
        });
    }

    public function renewed(object $event): void
    {
        $subscription = $event->subscription ?? null;

        $this->each($subscription, function (StatamicUser $learner, string $slug) use ($subscription): void {
            $this->courses->recordBilling($learner, $slug, $this->paymentsOf($subscription));
            $this->courses->paymentRecovered($learner, $slug, $this->idOf($subscription));
        });
    }

    public function cycleFailed(object $event): void
    {
        $subscription = $event->subscription ?? null;

        $this->each($subscription, function (StatamicUser $learner, string $slug) use ($subscription): void {
            $this->courses->paymentFailed($learner, $slug, $this->idOf($subscription), $this->grantRefsOf($subscription));
        });
    }

    /**
     * The provider's subscription number (what support finds at Stripe or
     * Mollie), the local id only when there is none.
     */
    protected function idOf(object $subscription): ?string
    {
        foreach ([$subscription->provider_id ?? null, $subscription->id ?? null] as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * The references payments wrote onto this subscription's grants: the
     * provider ids of its payments (the first one and every cycle), and the
     * subscription's own provider id, which the renewal writes when it finds
     * no grant to extend (EntitlementsBridge::extendFor()).
     *
     * @return list<string>
     */
    protected function grantRefsOf(object $subscription): array
    {
        try {
            $refs = method_exists($subscription, 'payments')
                ? collect($subscription->payments()->pluck('provider_id'))->all()
                : [];
        } catch (Throwable) {
            $refs = [];
        }

        $refs[] = $subscription->provider_id ?? null;

        return array_values(array_unique(array_filter(array_map(
            fn ($ref): string => is_scalar($ref) ? (string) $ref : '',
            $refs,
        ))));
    }

    /**
     * @param  callable(StatamicUser, string): void  $callback
     */
    protected function each(mixed $subscription, callable $callback): void
    {
        if (! is_object($subscription)) {
            return;
        }

        $email = is_string($subscription->email ?? null) ? trim($subscription->email) : '';
        $learner = $email !== '' ? User::findByEmail($email) : null;

        if (! $learner instanceof StatamicUser) {
            Log::debug('statamic-courses: a subscription event for an address without a user account; nothing to update.', [
                'subscription_id' => $subscription->id ?? null,
            ]);

            return;
        }

        $products = $this->productsOf((string) ($subscription->product ?? ''));

        foreach ($this->courses->courses() as $course) {
            $opens = [$course['product'], ...($course['bundles'] ?? [])];

            if (array_intersect($opens, $products) === []) {
                continue;
            }

            try {
                $callback($learner, $course['slug']);
            } catch (Throwable $e) {
                // One course must not keep the others from hearing about the
                // payment; the failure is loud, not swallowed.
                Log::error('statamic-courses: a subscription event could not be applied to a course.', [
                    'course' => $course['slug'],
                    'subscription_id' => $subscription->id ?? null,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The subscription's product handle plus what its catalogue entry grants.
     *
     * @return list<string>
     */
    protected function productsOf(string $handle): array
    {
        if ($handle === '') {
            return [];
        }

        $products = [$handle];

        try {
            $entry = class_exists(self::CATALOGUE) ? app(self::CATALOGUE)->find($handle) : null;
        } catch (Throwable) {
            $entry = null;
        }

        $grants = is_array($entry) ? ($entry['grants'] ?? null) : null;

        foreach (is_array($grants) ? $grants : [$grants] as $grant) {
            if (is_string($grant) && $grant !== '') {
                $products[] = $grant;
            }
        }

        return array_values(array_unique($products));
    }

    /**
     * The first payment plus every cycle charged since.
     */
    protected function paymentsOf(object $subscription): int
    {
        return 1 + max(0, (int) ($subscription->times_charged ?? 0));
    }

    /**
     * When the trial ends: the first charge date, for a plan that has a trial.
     */
    protected function trialUntil(object $subscription): ?Carbon
    {
        try {
            $plan = class_exists(self::SUBSCRIPTIONS) ? app(self::SUBSCRIPTIONS)->planFor((string) ($subscription->product ?? '')) : null;
        } catch (Throwable) {
            $plan = null;
        }

        if (! is_array($plan) || (int) ($plan['trial_days'] ?? 0) <= 0 || empty($subscription->starts_at)) {
            return null;
        }

        return Carbon::parse($subscription->starts_at);
    }
}
