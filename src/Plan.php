<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One membership an owner sells.
 *
 * A plan is a price in the owner's Stripe account and a group on their forum,
 * and nothing else. It grants no permissions of its own: the owner points their
 * own tag and permission settings at the group, using Flarum's screens, which
 * is why this needs to know nothing about tags.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $group_id
 * @property int $amount minor units, the way Stripe counts
 * @property string $currency
 * @property string $interval month|year
 * @property string|null $stripe_product_id
 * @property string|null $stripe_price_id
 * @property bool $is_active
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Group|null $group
 */
class Plan extends AbstractModel
{
    public const INTERVAL_MONTH = 'month';
    public const INTERVAL_YEAR = 'year';

    protected $table = 'flock_plans';

    public $timestamps = true;

    protected $casts = [
        'id' => 'integer',
        'group_id' => 'integer',
        'amount' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Whether this plan can be sold right now.
     *
     * Its own readiness only. Whether the FORUM may sell is a separate
     * question with a separate answer, in [[KeyStatus]], and neither one is
     * allowed to touch anybody's existing membership.
     */
    public function isSellable(): bool
    {
        return $this->is_active
            && $this->group_id !== null
            && $this->stripe_price_id !== null;
    }

    public static function intervals(): array
    {
        return [self::INTERVAL_MONTH, self::INTERVAL_YEAR];
    }
}
