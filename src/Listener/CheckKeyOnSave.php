<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Listener;

use Flarum\Settings\Event\Saved;
use LinkRobins\Flock\KeyStatus;
use LinkRobins\Flock\Settings;

/**
 * Asks about the key when, and only when, the admin saved it.
 *
 * The save is the commitment, so this is the one call that passes bind=1 and
 * claims the key for this forum. Every other settings save on the forum passes
 * through here too, which is why it returns early on anything that did not
 * include the key.
 */
class CheckKeyOnSave
{
    public function __construct(
        protected Settings $settings,
        protected KeyStatus $status
    ) {
    }

    public function handle(Saved $event): void
    {
        if (! array_key_exists(Settings::KEY, $event->settings)) {
            return;
        }

        if (trim((string) $event->settings[Settings::KEY]) === '') {
            // Cleared on purpose. Drop what the service told us, and leave
            // every existing membership exactly where it is: this is a billing
            // relationship between the owner and us, and members are not part
            // of it.
            $this->settings->forgetStatus();

            return;
        }

        $this->status->refresh(bind: true);
    }
}
