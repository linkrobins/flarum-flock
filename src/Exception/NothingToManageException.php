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

/** Asked to manage a membership by somebody who has never had one. */
class NothingToManageException extends Exception implements KnownError
{
    public function getType(): string
    {
        return 'flock_nothing_to_manage';
    }
}
