<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Listener;

use Flarum\Settings\Event\Deserializing;
use LinkRobins\Flock\Settings;

/**
 * Keeps both secrets out of the admin page's payload.
 *
 * Flarum hands the whole settings table to the admin frontend, and one of these
 * is not even ours: the owner's Stripe restricted key can move money inside
 * their account. Neither is needed by any part of the UI, so neither is sent.
 * The Flock key is left visible so its field round-trips, which is also what
 * stops an unrelated save from looking like the admin cleared it.
 */
class HideKeysFromAdmin
{
    private const HIDDEN = [
        Settings::STRIPE_KEY,
        Settings::WEBHOOK_SECRET,
    ];

    public function handle(Deserializing $event): void
    {
        foreach (self::HIDDEN as $setting) {
            unset($event->settings[$setting]);
        }
    }
}
