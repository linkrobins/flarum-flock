<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration;

use Flarum\Foundation\AbstractServiceProvider;
use LinkRobins\Flock\Stripe\Gateway;

class FakeGatewayProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->singleton(Gateway::class, fn () => new FakeGateway());
    }
}
