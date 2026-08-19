<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Http;

use Flarum\Http\RequestUtil;
use LinkRobins\Flock\Reconcile;
use LinkRobins\Flock\Subscription;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Where the catching-up actually happens: on a request the member was making
 * anyway.
 *
 * The spec rules out a cron job, so the alternative is to do it lazily, and the
 * honest place for work with side effects is a middleware rather than anything
 * that serializes a response. Core does the same sort of thing here for
 * sessions and garbage collection.
 *
 * Two guards keep it from being a cost. Members with no subscription row never
 * reach the service at all, which is nearly everyone on a forum, and the
 * service itself only looks at one member once an hour. Failures are swallowed:
 * this is housekeeping, and housekeeping must never be the reason a page did
 * not load.
 */
class ReconcileMiddleware implements MiddlewareInterface
{
    public function __construct(
        protected Reconcile $reconcile,
        protected LoggerInterface $log
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->exists && $this->hasSubscription((int) $actor->id)) {
            try {
                $this->reconcile->forUser($actor);
            } catch (\Throwable $e) {
                $this->log->error('[Flock] Reconcile failed for user '.$actor->id.': '.$e->getMessage());
            }
        }

        return $handler->handle($request);
    }

    /** One indexed count, and only for a logged-in member. */
    protected function hasSubscription(int $userId): bool
    {
        return Subscription::query()->where('user_id', $userId)->exists();
    }
}
