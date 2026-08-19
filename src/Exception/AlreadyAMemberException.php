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

/** They already have this one, and a second subscription would just charge them twice. */
class AlreadyAMemberException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'flock_already_a_member';
    }
}
