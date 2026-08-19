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
use LinkRobins\Flock\KeyStatus;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Check again", for an owner who has just fixed something at our end.
 *
 * A read, not a claim: no bind. Binding happens on the save of the key and
 * nowhere else, so pressing this on a second forum cannot move a key.
 */
class RecheckController implements RequestHandlerInterface
{
    public function __construct(
        protected KeyStatus $status
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse([
            'status' => $this->status->refresh(bind: false),
            'canSellNew' => $this->status->canSellNew(),
        ]);
    }
}
