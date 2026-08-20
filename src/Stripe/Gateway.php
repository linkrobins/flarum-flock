<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Stripe;

use LinkRobins\Flock\Plan;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;

/**
 * Everything this extension asks of Stripe about a member, behind one seam.
 *
 * Narrow on purpose. Fulfilment deals in arrays from here rather than SDK
 * objects, so the part that decides who gets access can be tested without a
 * network, and so there is one place that knows what a Stripe subscription
 * looks like.
 */
class Gateway
{
    public function __construct(
        protected Stripe $stripe,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Start a hosted Checkout for one member and one plan.
     *
     * The member is written into the session's metadata, and that is the copy
     * that counts later: a return URL is just a URL, and whoever opens it is
     * not necessarily who paid.
     *
     * @return string|null the URL to send them to
     */
    public function checkout(Plan $plan, int $userId, ?string $email, string $successUrl, string $cancelUrl): ?string
    {
        $client = $this->stripe->client();

        if ($client === null || $plan->stripe_price_id === null) {
            return null;
        }

        try {
            $session = $client->checkout->sessions->create([
                'mode' => 'subscription',
                'line_items' => [['price' => $plan->stripe_price_id, 'quantity' => 1]],
                // The owner's coupons work from day one, which is also why the
                // fulfilment path cannot assume money changed hands.
                'allow_promotion_codes' => true,
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'customer_email' => $email ?: null,
                'metadata' => $this->metadata($plan, $userId),
                // Repeated on the subscription so a webhook that never saw the
                // session still knows who and what it is for.
                'subscription_data' => ['metadata' => $this->metadata($plan, $userId)],
            ]);

            return $session->url;
        } catch (ApiErrorException $e) {
            $this->log->error('[Flock] Stripe refused a checkout: '.$e->getMessage());

            return null;
        }
    }

    /**
     * A link to Stripe's own billing portal for one customer.
     *
     * This is how a member cancels, and it is deliberately not something this
     * extension reimplements: cancelling, changing a card and downloading an
     * invoice all happen on Stripe's pages, under the owner's account, and come
     * back to us as the webhooks we already handle. Nothing here writes to the
     * subscription, so a member who opens the portal and changes their mind has
     * changed nothing.
     *
     * @return string|null the URL to send them to
     */
    public function portal(string $customerId, string $returnUrl): ?string
    {
        $client = $this->stripe->client();

        if ($client === null) {
            return null;
        }

        try {
            $session = $client->billingPortal->sessions->create([
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]);

            return $session->url;
        } catch (ApiErrorException $e) {
            // Most likely the owner has not configured the portal in their
            // Stripe dashboard, or minted a key without access to it. Either
            // way the member is told we could not open it, not shown a 500.
            $this->log->error('[Flock] Stripe refused a billing portal session: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array{subscription: ?string, status: ?string, user_id: ?int, plan_id: ?int}|null
     */
    public function session(string $sessionId): ?array
    {
        $client = $this->stripe->client();

        if ($client === null) {
            return null;
        }

        try {
            $session = $client->checkout->sessions->retrieve($sessionId);
        } catch (ApiErrorException $e) {
            $this->log->error('[Flock] Could not read a checkout session: '.$e->getMessage());

            return null;
        }

        $subscription = $session->subscription;

        return [
            'subscription' => is_string($subscription) ? $subscription : ($subscription->id ?? null),
            'status' => $session->payment_status,
            'user_id' => $this->intMeta($session->metadata['flock_user_id'] ?? null),
            'plan_id' => $this->intMeta($session->metadata['flock_plan_id'] ?? null),
        ];
    }

    /**
     * @return array{id: string, status: string, customer: ?string, period_end: ?int, cancel_at_period_end: bool, user_id: ?int, plan_id: ?int}|null
     */
    public function subscription(string $subscriptionId): ?array
    {
        $client = $this->stripe->client();

        if ($client === null) {
            return null;
        }

        try {
            $subscription = $client->subscriptions->retrieve($subscriptionId);
        } catch (ApiErrorException $e) {
            $this->log->error('[Flock] Could not read a subscription: '.$e->getMessage());

            return null;
        }

        $customer = $subscription->customer;

        return [
            'id' => $subscription->id,
            'status' => (string) $subscription->status,
            'customer' => is_string($customer) ? $customer : ($customer->id ?? null),
            'period_end' => self::periodEnd($subscription),
            'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            'user_id' => $this->intMeta($subscription->metadata['flock_user_id'] ?? null),
            'plan_id' => $this->intMeta($subscription->metadata['flock_plan_id'] ?? null),
        ];
    }

    /**
     * When the period a member has paid for runs out.
     *
     * Stripe moved this off the subscription and onto its items, because a
     * subscription can carry items on different cycles and so has no single
     * period any more. Flock sells exactly one price per plan, so the first
     * item's period is the subscription's.
     *
     * This is not cosmetic: with no date, a cancelled subscription looks like
     * one whose time is already up, and the member loses the rest of what they
     * paid for the moment they click cancel. The old field is still read as a
     * fallback, since an account pinned to an earlier API version still sends
     * it and nothing else would.
     *
     * Static and object-typed so it can be tested against both shapes without a
     * network.
     */
    public static function periodEnd(object $subscription): ?int
    {
        $item = $subscription->items->data[0] ?? null;

        if ($item !== null && isset($item->current_period_end) && is_numeric($item->current_period_end)) {
            return (int) $item->current_period_end;
        }

        if (isset($subscription->current_period_end) && is_numeric($subscription->current_period_end)) {
            return (int) $subscription->current_period_end;
        }

        return null;
    }

    /** @return array<string, string> */
    private function metadata(Plan $plan, int $userId): array
    {
        return [
            'flock_user_id' => (string) $userId,
            'flock_plan_id' => (string) $plan->id,
        ];
    }

    private function intMeta(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
