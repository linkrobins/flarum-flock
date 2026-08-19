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

/** A refusal a member can be told about, rather than a 500 they cannot act on. */
class CheckoutFailedException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'flock_checkout_failed';
    }
}
