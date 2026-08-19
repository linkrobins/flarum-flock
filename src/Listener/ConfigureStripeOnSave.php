<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Listener;

use Flarum\Settings\Event\Saved;
use LinkRobins\Flock\Settings;
use LinkRobins\Flock\Stripe\WebhookEndpoint;

/**
 * Registers this forum's webhook endpoint when the owner saves their Stripe key.
 *
 * The sales page promises that webhooks configure themselves, so this is the
 * step nobody has to do by hand. It runs only on a save that included the key,
 * since re-registering on every unrelated settings save would churn the
 * endpoint and the signing secret with it.
 */
class ConfigureStripeOnSave
{
    public function __construct(
        protected WebhookEndpoint $endpoint
    ) {
    }

    public function handle(Saved $event): void
    {
        if (! array_key_exists(Settings::STRIPE_KEY, $event->settings)) {
            return;
        }

        if (trim((string) $event->settings[Settings::STRIPE_KEY]) === '') {
            return;
        }

        // Failure is logged, not thrown: a restricted key missing the webhook
        // scope is a fixable mistake, and the rest of the owner's save is fine.
        $this->endpoint->ensure();
    }
}
