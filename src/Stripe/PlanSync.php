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
 * Puts a plan into the owner's Stripe account, and keeps it there.
 *
 * The rule that shapes this class is Stripe's, not ours: a Price is immutable.
 * Changing what a plan costs cannot edit the old price, it creates a new one,
 * and everyone already subscribed keeps paying what they agreed to. That is
 * grandfathering, it is free, and the admin page says so rather than pretending
 * the change was retroactive.
 *
 * Nothing here ever deletes a Price or a Product. A price with subscribers
 * attached is a live agreement between the owner and their members, and this
 * extension is not in that relationship.
 */
class PlanSync
{
    public function __construct(
        protected Stripe $stripe,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Make Stripe match the plan, and record what it now points at.
     *
     * @return bool whether the plan is sellable afterwards
     */
    public function push(Plan $plan): bool
    {
        $client = $this->stripe->client();

        if ($client === null) {
            return false;
        }

        try {
            $product = $plan->stripe_product_id;

            if ($product === null) {
                $product = $client->products->create([
                    'name' => $plan->name,
                    'description' => $plan->description ?: null,
                    'metadata' => $this->metadata($plan),
                ])->id;

                $plan->stripe_product_id = $product;
            } else {
                // A product IS editable, so the name and description follow the
                // plan. Only the money is frozen.
                $client->products->update($product, [
                    'name' => $plan->name,
                    'description' => $plan->description ?: null,
                ]);
            }

            if ($this->needsPrice($plan, $client)) {
                $price = $client->prices->create([
                    'product' => $product,
                    'unit_amount' => $plan->amount,
                    'currency' => strtolower($plan->currency),
                    'recurring' => ['interval' => $plan->interval],
                    'metadata' => $this->metadata($plan),
                ]);

                // The old price is left alone on purpose: subscribers are still
                // on it, and it is what they agreed to pay.
                $plan->stripe_price_id = $price->id;
            }

            $plan->save();

            return $plan->isSellable();
        } catch (ApiErrorException $e) {
            // The owner's account said no. That is between them and Stripe, and
            // it must not take the admin page down with it: the plan stays as
            // it was, unsellable, and the message goes to the log.
            $this->log->error('[Flock] Stripe rejected a plan push: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Whether the money changed since the price was made.
     *
     * Asked of Stripe rather than of our own row, because the price is the
     * thing subscribers are on and Stripe is the record of it.
     */
    protected function needsPrice(Plan $plan, \Stripe\StripeClient $client): bool
    {
        if ($plan->stripe_price_id === null) {
            return true;
        }

        try {
            $price = $client->prices->retrieve($plan->stripe_price_id);
        } catch (ApiErrorException) {
            // It is gone, or the key cannot read it. Either way a new one is
            // the safe answer: nothing is deleted, and a fresh price cannot
            // overcharge anybody.
            return true;
        }

        return (int) $price->unit_amount !== (int) $plan->amount
            || strtolower((string) $price->currency) !== strtolower($plan->currency)
            || (string) ($price->recurring->interval ?? '') !== $plan->interval;
    }

    /** @return array<string, string> */
    protected function metadata(Plan $plan): array
    {
        return [
            'flock_plan_id' => (string) $plan->id,
            'flock_group_id' => (string) ($plan->group_id ?? ''),
        ];
    }
}
