<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Api\Controller;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Flock\Reconcile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * "Sync now", for an owner who would rather not wait for the lazy path.
 *
 * Asks Stripe about every subscription the forum knows of and settles each one.
 * An explicit POST, because it changes things.
 */
class SyncController implements RequestHandlerInterface
{
    public function __construct(
        protected Reconcile $reconcile
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse(['checked' => $this->reconcile->all()]);
    }
}
