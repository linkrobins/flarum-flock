<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Api\Controller;

use Flarum\Foundation\Config;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use LinkRobins\Flock\Exception\NothingToManageException;
use LinkRobins\Flock\Exception\PortalUnavailableException;
use LinkRobins\Flock\Stripe\Gateway;
use LinkRobins\Flock\Subscription;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends a member to Stripe's billing portal, which is where they cancel.
 *
 * A member who has paid has to be able to stop paying without asking anybody's
 * permission, and without the forum owner playing middleman in their own Stripe
 * dashboard. Stripe hosts the pages; whatever the member does there arrives
 * back as the webhooks this extension already handles, so cancelling through
 * the portal ends exactly the way cancelling in the dashboard does, with access
 * lasting to the end of the period they paid for.
 *
 * The Flock key is not consulted. Cancelling is not a sale, and a forum whose
 * key has lapsed must not be a forum whose members are trapped.
 */
class PortalController implements RequestHandlerInterface
{
    public function __construct(
        protected Gateway $gateway,
        protected Config $config
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $customerId = $this->customerFor((int) $actor->id);

        if ($customerId === null) {
            throw new NothingToManageException();
        }

        $url = $this->gateway->portal($customerId, $this->returnUrl());

        if ($url === null) {
            throw new PortalUnavailableException();
        }

        return new JsonResponse(['url' => $url]);
    }

    /**
     * The Stripe customer behind this member, if there is one.
     *
     * The newest row wins, and a cancelled one still counts: the portal is also
     * where somebody finds the receipts for a membership they have already
     * ended, and refusing them that would be its own small unkindness.
     */
    protected function customerFor(int $userId): ?string
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->whereNotNull('stripe_customer_id')
            ->orderByDesc('id')
            ->value('stripe_customer_id');
    }

    protected function returnUrl(): string
    {
        return rtrim((string) $this->config->url(), '/').'/flock/plans';
    }
}
