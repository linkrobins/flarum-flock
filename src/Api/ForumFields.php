<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Api;

use Flarum\Api\Schema;
use LinkRobins\Flock\KeyStatus;
use LinkRobins\Flock\Subscription;
use Tobyz\JsonApiServer\Context;

/**
 * What the Join page needs to know before it draws anything.
 *
 * Which plans this member already has, so the page offers to join the ones they
 * do not rather than inviting them to buy the same membership twice, whether
 * this forum can start new memberships at all, and whether this member has
 * anything on Stripe's side to manage.
 *
 * Fail-closed throughout: these ride on every forum response, so anything
 * unexpected reads as "no memberships, not selling" rather than 500ing the boot
 * payload of a forum whose owner never even set Flock up.
 */
class ForumFields
{
    public function __construct(
        protected KeyStatus $key
    ) {
    }

    public function __invoke(): array
    {
        return [
            // The ids of plans this member is currently entitled to. Empty for
            // a guest, and empty on any error.
            Schema\Arr::make('flockPlanIds')
                ->get(function ($model, Context $context): array {
                    try {
                        $actor = $context->getActor();

                        if (! $actor->exists) {
                            return [];
                        }

                        return Subscription::query()
                            ->where('user_id', $actor->id)
                            ->get()
                            ->filter(fn (Subscription $subscription) => $subscription->grantsAccess())
                            ->pluck('plan_id')
                            ->filter()
                            ->map(fn ($id) => (int) $id)
                            ->values()
                            ->all();
                    } catch (\Throwable) {
                        return [];
                    }
                }),

            // Whether this member has anything to manage on Stripe's side.
            // False for a guest, and false on any error, so the button is
            // simply absent rather than offering a portal that cannot open.
            Schema\Boolean::make('flockCanManage')
                ->get(function ($model, Context $context): bool {
                    try {
                        $actor = $context->getActor();

                        if (! $actor->exists) {
                            return false;
                        }

                        return Subscription::query()
                            ->where('user_id', $actor->id)
                            ->whereNotNull('stripe_customer_id')
                            ->exists();
                    } catch (\Throwable) {
                        return false;
                    }
                }),

            Schema\Boolean::make('flockSelling')
                ->get(function (): bool {
                    try {
                        return $this->key->canSellNew();
                    } catch (\Throwable) {
                        return false;
                    }
                }),
        ];
    }
}
