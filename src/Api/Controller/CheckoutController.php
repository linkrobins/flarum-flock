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
use LinkRobins\Flock\KeyStatus;
use LinkRobins\Flock\Plan;
use LinkRobins\Flock\Subscription;
use LinkRobins\Flock\Stripe\Gateway;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use LinkRobins\Flock\Exception\AlreadyAMemberException;
use LinkRobins\Flock\Exception\CheckoutFailedException;
use LinkRobins\Flock\Exception\NotSellingException;
use LinkRobins\Flock\Exception\PlanNotOnSaleException;

/**
 * Starts a hosted Checkout for the member asking.
 *
 * Logged-in members only: a subscription attaches to a person on this forum,
 * and a guest is nobody to attach it to. The card is Stripe's problem from here
 * on, which is the point of hosted Checkout.
 */
class CheckoutController implements RequestHandlerInterface
{
    public function __construct(
        protected Gateway $gateway,
        protected KeyStatus $key,
        protected Config $config
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $planId = (int) ($request->getParsedBody()['planId'] ?? 0);
        $plan = Plan::query()->find($planId);

        if ($plan === null || ! $plan->isSellable()) {
            throw new PlanNotOnSaleException();
        }

        // The one thing the Flock key gates, and it gates only this. Nobody
        // holding a membership is affected by the answer.
        if (! $this->key->canSellNew()) {
            throw new NotSellingException();
        }

        // The Join page hides the button for a plan they already hold, but the
        // page is not the boundary: a double click, a stale tab or a plain POST
        // would otherwise start a second subscription to the same group and
        // charge them twice for it, leaving the owner to do the refund.
        if ($this->alreadyHas($actor->id, $plan)) {
            throw new AlreadyAMemberException();
        }

        $url = $this->gateway->checkout(
            $plan,
            (int) $actor->id,
            $actor->email,
            $this->returnUrl(),
            $this->cancelUrl()
        );

        if ($url === null) {
            throw new CheckoutFailedException();
        }

        return new JsonResponse(['url' => $url]);
    }

    /** Whether this member is already entitled to what this plan grants. */
    protected function alreadyHas(int $userId, Plan $plan): bool
    {
        return Subscription::query()
            ->where('user_id', $userId)
            ->where('plan_id', $plan->id)
            ->get()
            ->contains(fn (Subscription $subscription) => $subscription->grantsAccess());
    }

    /**
     * Stripe substitutes the session id here. The member comes back to a route
     * that asks Stripe what happened rather than trusting the trip.
     */
    protected function returnUrl(): string
    {
        return $this->base().'/flock/complete?session_id={CHECKOUT_SESSION_ID}';
    }

    protected function cancelUrl(): string
    {
        return $this->base().'/flock/plans';
    }

    protected function base(): string
    {
        return rtrim((string) $this->config->url(), '/');
    }
}
