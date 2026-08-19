<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Http;

use Flarum\Foundation\Config;
use Laminas\Diactoros\Response\RedirectResponse;
use LinkRobins\Flock\Fulfilment;
use LinkRobins\Flock\Stripe\Gateway;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where Stripe sends a member back to, and where access becomes instant.
 *
 * It asks Stripe what happened rather than believing the trip: the session id
 * arrives in a URL, and a URL can be shared, replayed or mistyped. Two rules
 * follow, and both matter more than they look.
 *
 * The member who gets the group is the one recorded in the session's metadata,
 * NEVER whoever is holding this browser. If A's session id turns up in B's
 * browser, this grants to A again, which changes nothing, rather than handing B
 * the membership A paid for.
 *
 * And "paid" is not the test. The owner's promotion codes are switched on, so a
 * full-discount code produces a session Stripe marks no_payment_required. The
 * subscription's own status decides, which is the same source of truth the
 * webhook and every later reconcile use.
 */
class CompleteController implements RequestHandlerInterface
{
    public function __construct(
        protected Gateway $gateway,
        protected Fulfilment $fulfilment,
        protected Config $config
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $sessionId = (string) ($request->getQueryParams()['session_id'] ?? '');

        if ($sessionId !== '') {
            $session = $this->gateway->session($sessionId);

            if ($session !== null && is_string($session['subscription'])) {
                // Everything past this point is Stripe's answer about the
                // subscription, not anything the browser brought with it.
                $this->fulfilment->apply($session['subscription']);
            }
        }

        // Back to the forum either way. A member who lands here with a stale or
        // unknown session id has simply arrived at their forum.
        return new RedirectResponse(rtrim((string) $this->config->url(), '/').'/');
    }
}
