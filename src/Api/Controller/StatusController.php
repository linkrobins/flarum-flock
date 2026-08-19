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
use LinkRobins\Flock\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * What the settings banner reads.
 *
 * Admin only, and a read: it reports what was recorded during the last save
 * rather than asking the service, so opening the settings page cannot bind a
 * key and cannot be turned into a way to hammer us. "Check again" is a
 * different, explicit route.
 */
class StatusController implements RequestHandlerInterface
{
    public function __construct(
        protected Settings $settings,
        protected KeyStatus $status
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        return new JsonResponse([
            'status' => $this->status->bannerStatus(),
            'canSellNew' => $this->status->canSellNew(),
            'boundTo' => $this->settings->boundTo(),
            'checkedAt' => $this->settings->checkedAt(),
            // So the banner can say how long selling keeps working if we are
            // the thing that is broken.
            'graceEndsAt' => $this->settings->goodAt() === null
                ? null
                : $this->settings->goodAt() + KeyStatus::GRACE_DAYS * 86400,
        ]);
    }
}
