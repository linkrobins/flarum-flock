<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use LinkRobins\Flock\Stripe\Gateway;
use Psr\Log\LoggerInterface;

/**
 * Turns what Stripe says about a subscription into what the forum does about it.
 *
 * Both roads lead here. The member's return from Checkout calls it, and so does
 * the webhook, and whichever arrives first does the work while the other finds
 * everything already done. That is what makes "access is instant" and "nothing
 * exists until Stripe confirms" the same design rather than opposing ones: this
 * asks Stripe every time and writes nothing on anybody's say-so.
 *
 * The user comes from the subscription's metadata, never from whoever happens
 * to be holding the browser. A return URL is just a URL.
 */
class Fulfilment
{
    public function __construct(
        protected Gateway $gateway,
        protected Groups $groups,
        protected Settings $settings,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Bring the forum in line with one subscription, whatever state it is in.
     *
     * Idempotent by construction: the row is keyed on Stripe's id, and the
     * group change is a no-op when there is nothing to change.
     */
    public function apply(string $subscriptionId): ?Subscription
    {
        $remote = $this->gateway->subscription($subscriptionId);

        if ($remote === null) {
            // Could not ask. Nothing is written and nothing is taken away: a
            // Stripe that will not answer is not evidence about anybody.
            return null;
        }

        $userId = $remote['user_id'];

        if ($userId === null) {
            $this->log->warning('[Flock] Subscription '.$subscriptionId.' has no member in its metadata; ignoring.');

            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        $subscription = Subscription::query()
            ->where('stripe_subscription_id', $subscriptionId)
            ->first() ?? new Subscription();

        $subscription->user_id = $user->id;
        $subscription->plan_id = $remote['plan_id'] ?? $subscription->plan_id;
        $subscription->stripe_subscription_id = $subscriptionId;
        $subscription->stripe_customer_id = $remote['customer'];
        $subscription->status = $remote['status'];
        $subscription->access_until = $this->accessUntil($remote);
        $subscription->save();

        $this->settle($subscription, $user);

        return $subscription;
    }

    /**
     * Hand out or take back the plan's group, and nothing else.
     *
     * This is the only place either happens for a subscription, so the rule is
     * enforceable by reading one method: membership is all Flock ever changes
     * about a person.
     */
    protected function settle(Subscription $subscription, User $user): void
    {
        $plan = $subscription->plan;

        if ($plan === null || $plan->group_id === null) {
            return;
        }

        $group = Group::query()->find($plan->group_id);

        if ($group === null) {
            return;
        }

        if ($subscription->grantsAccess()) {
            $this->groups->grant($user, $group);

            return;
        }

        // Only ever the group this plan grants, and only when the time they
        // paid for is actually over.
        $this->groups->revoke($user, $group);
    }

    /**
     * The date access runs out if nothing else changes.
     *
     * For a live subscription that is the end of the period they have paid for.
     * For one Stripe is retrying, it is the grace the owner configured, which
     * is what keeps a declined card on a Tuesday from reading as a decision to
     * leave.
     *
     * @param array{status: string, period_end: ?int, cancel_at_period_end: bool} $remote
     */
    protected function accessUntil(array $remote): ?Carbon
    {
        $periodEnd = $remote['period_end'] !== null ? Carbon::createFromTimestamp($remote['period_end']) : null;

        if ($remote['status'] === Subscription::RETRYING) {
            $grace = Carbon::now()->addDays($this->settings->graceDays());

            // Whichever is later: a member part-way through a paid period does
            // not lose the rest of it because a renewal failed early.
            return $periodEnd !== null && $periodEnd->greaterThan($grace) ? $periodEnd : $grace;
        }

        return $periodEnd;
    }
}
