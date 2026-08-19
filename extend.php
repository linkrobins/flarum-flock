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
use LinkRobins\Flock\Api\Controller\CheckoutController;
use LinkRobins\Flock\Api\Controller\RecheckController;
use LinkRobins\Flock\Api\Resource\PlanResource;
use LinkRobins\Flock\Http\CompleteController;
use LinkRobins\Flock\Api\Controller\StatusController;
use LinkRobins\Flock\Listener\CheckKeyOnSave;
use LinkRobins\Flock\Listener\ConfigureStripeOnSave;
use LinkRobins\Flock\Listener\HideKeysFromAdmin;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    /*
     * Plans are readable by anyone who can see the forum, since a price list
     * has to be readable to be decided on, and writable by admins only.
     */
    (new Extend\ApiResource(PlanResource::class)),

    (new Extend\Event())
        ->listen(Saved::class, CheckKeyOnSave::class)
        ->listen(Saved::class, ConfigureStripeOnSave::class)
        ->listen(Deserializing::class, HideKeysFromAdmin::class),

    (new Extend\Routes('api'))
        ->get('/linkrobins-flock/status', 'linkrobins-flock.status', StatusController::class)
        ->post('/linkrobins-flock/recheck', 'linkrobins-flock.recheck', RecheckController::class)
        ->post('/linkrobins-flock/checkout', 'linkrobins-flock.checkout', CheckoutController::class),

    /*
     * Where Stripe returns a member to. A forum route rather than an API one,
     * because Stripe is redirecting a browser here, not calling an API.
     */
    (new Extend\Routes('forum'))
        ->get('/flock/complete', 'linkrobins-flock.complete', CompleteController::class),

    /*
     * Refusals a member can act on, instead of a 500 they cannot.
     */
    (new Extend\ErrorHandling())
        ->status('flock_plan_not_on_sale', 422)
        ->status('flock_not_selling', 422)
        ->status('flock_checkout_failed', 502),
];
