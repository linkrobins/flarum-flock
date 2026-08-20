<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Exception;

use Exception;
use Flarum\Foundation\KnownError;

/**
 * Stripe would not open its billing portal.
 *
 * Usually the owner's doing rather than the member's: a portal that has never
 * been configured in the Stripe dashboard, or a restricted key minted without
 * access to it. The member is told plainly and pointed at the owner.
 */
class PortalUnavailableException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'flock_portal_unavailable';
    }
}
