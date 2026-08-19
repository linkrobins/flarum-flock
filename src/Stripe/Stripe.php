<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Stripe;

use LinkRobins\Flock\Settings;
use Stripe\StripeClient;

/**
 * The owner's Stripe account, reached with the owner's restricted key.
 *
 * One class builds the client so nothing else has to know where the key comes
 * from, and so there is one place to be careful: this key belongs to the forum
 * owner and can move money inside their account. It is never logged, never
 * serialized to the frontend, and never sent anywhere except Stripe.
 *
 * Returns null rather than throwing when the forum has no key yet, because a
 * forum mid-setup is a normal state and not an error.
 */
class Stripe
{
    public function __construct(
        protected Settings $settings
    ) {
    }

    public function client(): ?StripeClient
    {
        $key = $this->settings->stripeKey();

        if ($key === '') {
            return null;
        }

        return new StripeClient([
            'api_key' => $key,
            // A member is waiting on the other end of a checkout, and an owner
            // on the other end of a save. Neither should hang on Stripe.
            'connect_timeout' => 5,
            'timeout' => 15,
        ]);
    }

    public function configured(): bool
    {
        return $this->settings->stripeKey() !== '';
    }
}
