<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Carbon\Carbon;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Catches up with Stripe when a webhook never arrived.
 *
 * Webhooks get missed. A host is down for an hour, a deploy drops a request, an
 * endpoint gets deleted and remade. Two things then go wrong in opposite
 * directions, and this fixes both without a cron job:
 *
 * A subscription that ended and whose deletion we never heard about would leave
 * the member holding their group forever. That is the one that costs the owner
 * money, and it is why an expired row is re-checked even when it is otherwise
 * fresh.
 *
 * A subscription that renewed and whose renewal we never heard about would look
 * stale here while the member is paying perfectly happily. That is why anything
 * older than a day gets asked about too, rather than only expired rows.
 *
 * What it will not do is punish a member for Stripe being unreachable. If the
 * answer cannot be had, everything stays exactly as it is and the question gets
 * asked again later.
 */
class Reconcile
{
    /** How stale a row may be before it is worth asking about again. */
    public const STALE_HOURS = 24;

    /**
     * How often one member's rows may be re-examined. Their subscriptions are
     * checked on requests they are already making, so this is what keeps that
     * from meaning "on every page view".
     */
    private const CHECK_EVERY_MINUTES = 60;

    public function __construct(
        protected Fulfilment $fulfilment,
        protected Cache $cache
    ) {
    }

    /**
     * Bring one member's memberships up to date, rarely.
     *
     * @return int how many subscriptions were re-checked
     */
    public function forUser(User $user): int
    {
        $key = 'linkrobins-flock.checked.'.$user->id;

        if ($this->cache->get($key) !== null) {
            return 0;
        }

        // Marked before the work, not after: a slow Stripe must not turn one
        // member's page load into a queue of duplicate checks.
        $this->cache->put($key, 1, self::CHECK_EVERY_MINUTES * 60);

        return $this->check(
            Subscription::query()->where('user_id', $user->id)->get()->all()
        );
    }

    /**
     * Everything the forum thinks it knows, re-asked. What the admin's "sync
     * now" button runs.
     */
    public function all(): int
    {
        $checked = 0;

        Subscription::query()->orderBy('id')->chunk(100, function ($rows) use (&$checked) {
            $checked += $this->check($rows->all(), force: true);
        });

        return $checked;
    }

    /**
     * @param array<int, Subscription> $subscriptions
     */
    protected function check(array $subscriptions, bool $force = false): int
    {
        $checked = 0;

        foreach ($subscriptions as $subscription) {
            if (! $force && ! $this->worthAsking($subscription)) {
                continue;
            }

            // Everything downstream is Stripe's answer, including whether the
            // group goes. apply() is the same path the webhook uses.
            $this->fulfilment->apply($subscription->stripe_subscription_id);

            $checked++;
        }

        return $checked;
    }

    /**
     * Whether this row is worth a question.
     *
     * An expired one always is, whatever its age: that is the missed
     * cancellation, and it is the case where doing nothing hands out a
     * membership nobody is paying for.
     */
    protected function worthAsking(Subscription $subscription): bool
    {
        if ($subscription->access_until !== null && $subscription->access_until->isPast()) {
            return true;
        }

        $updated = $subscription->updated_at;

        return $updated === null || $updated->lessThan(Carbon::now()->subHours(self::STALE_HOURS));
    }
}
