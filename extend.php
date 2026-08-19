<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Extend;
use Flarum\Settings\Event\Deserializing;
use Flarum\Settings\Event\Saved;
use LinkRobins\Flock\Api\Controller\RecheckController;
use LinkRobins\Flock\Api\Controller\StatusController;
use LinkRobins\Flock\Listener\CheckKeyOnSave;
use LinkRobins\Flock\Listener\HideKeysFromAdmin;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Event())
        ->listen(Saved::class, CheckKeyOnSave::class)
        ->listen(Deserializing::class, HideKeysFromAdmin::class),

    (new Extend\Routes('api'))
        ->get('/linkrobins-flock/status', 'linkrobins-flock.status', StatusController::class)
        ->post('/linkrobins-flock/recheck', 'linkrobins-flock.recheck', RecheckController::class),
];
