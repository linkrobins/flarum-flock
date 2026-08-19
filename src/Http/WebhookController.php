<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Http;

use Laminas\Diactoros\Response\EmptyResponse;
use LinkRobins\Flock\Fulfilment;
use LinkRobins\Flock\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * What Stripe tells the forum, and the one door it comes through.
 *
 * Everything about this endpoint follows from the fact that its URL is public.
 * Anyone can POST here. So the signature is not a formality: it is the entire
 * reason to believe a word of the request, and a body that fails it is refused
 * before it is read as anything. Stripe's own verifier is used rather than a
 * hand-rolled HMAC, because it also enforces the timestamp tolerance that stops
 * a captured-and-replayed webhook from working forever.
 *
 * The work happens in the request. No queue worker is needed for correctness,
 * which matters because a forum whose worker is stopped would otherwise take
 * payments and hand out nothing.
 *
 * And the event is a nudge, not a source of truth: it says which subscription
 * changed, and everything after that comes from asking Stripe. A forged body
 * that somehow passed verification still could not lie about who paid.
 */
class WebhookController implements RequestHandlerInterface
{
    /** Tolerance in seconds for the timestamp Stripe signs. Stripe's own default. */
    private const TOLERANCE = 300;

    public function __construct(
        protected Settings $settings,
        protected Fulfilment $fulfilment,
        protected LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $secret = $this->settings->webhookSecret();

        if ($secret === '') {
            // Nothing registered here yet, so nothing can be verified, so
            // nothing is believed.
            return new EmptyResponse(400);
        }

        $body = (string) $request->getBody();

        try {
            $event = Webhook::constructEvent(
                $body,
                $request->getHeaderLine('Stripe-Signature'),
                $secret,
                self::TOLERANCE
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            $this->log->warning('[Flock] Refused a webhook: '.$e->getMessage());

            return new EmptyResponse(400);
        }

        $subscriptionId = $this->subscriptionFor($event->type, $event->data->object ?? null);

        if ($subscriptionId !== null) {
            // Same path the member's return takes. Whichever got here first did
            // the work; this finds it done.
            $this->fulfilment->apply($subscriptionId);
        }

        // 200 even for an event this extension does not care about, because a
        // non-200 tells Stripe to retry something that will never succeed.
        return new EmptyResponse(200);
    }

    /**
     * Which subscription an event is about, or null if it is none of our
     * business.
     */
    protected function subscriptionFor(string $type, mixed $object): ?string
    {
        if ($object === null) {
            return null;
        }

        if (str_starts_with($type, 'customer.subscription.')) {
            return is_string($object->id ?? null) ? $object->id : null;
        }

        // A completed checkout and an invoice both point at the subscription
        // they belong to. An abandoned checkout has none, which is the zombie
        // rule holding: no subscription, nothing written.
        if ($type === 'checkout.session.completed' || str_starts_with($type, 'invoice.')) {
            $subscription = $object->subscription ?? null;

            if (is_string($subscription)) {
                return $subscription;
            }

            return is_string($subscription->id ?? null) ? $subscription->id : null;
        }

        return null;
    }
}
