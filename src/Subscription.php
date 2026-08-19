<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Stripe last told us about one member's subscription.
 *
 * A record, not an authority. Stripe owns the truth about whether somebody is
 * paying; this row exists so the forum can grant a group without asking Stripe
 * on every page view, and so a webhook that arrives twice can recognise itself.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $plan_id
 * @property string $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property string $status
 * @property \Carbon\Carbon|null $access_until
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Plan|null $plan
 */
class Subscription extends AbstractModel
{
    /** Paying, or in a trial the owner set up. Access is on. */
    public const LIVE = ['active', 'trialing'];

    /**
     * The card failed and Stripe is retrying. Access stays on until the grace
     * the owner configured runs out, because a bank declining a renewal on a
     * Tuesday is not a decision to leave.
     */
    public const RETRYING = 'past_due';

    protected $table = 'flock_subscriptions';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'plan_id' => 'integer',
        'access_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Statuses that can still be holding time somebody paid for.
     *
     * A subscription that ended, or whose renewal is being retried, keeps its
     * group until the date it was paid up to. An `incomplete` one is NOT here,
     * and that distinction is the whole point of the list: a first payment that
     * never cleared has a period end in the future like any other, so anything
     * that reads the date without reading the status hands out memberships
     * nobody paid for. It did, until a test said so.
     */
    public const WINDING_DOWN = ['past_due', 'canceled', 'unpaid'];

    /** Whether this subscription should be holding its group right now. */
    public function grantsAccess(): bool
    {
        if (in_array($this->status, self::LIVE, true)) {
            return true;
        }

        if (! in_array($this->status, self::WINDING_DOWN, true)) {
            return false;
        }

        // Paid up until a date that has not arrived. They bought that time and
        // nothing here takes it back early.
        return $this->access_until !== null && $this->access_until->isFuture();
    }
}
