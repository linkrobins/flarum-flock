<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Api\Resource;

use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use LinkRobins\Flock\Groups;
use LinkRobins\Flock\Plan;
use LinkRobins\Flock\Stripe\PlanSync;
use Tobyz\JsonApiServer\Context;

/**
 * The plans an owner sells.
 *
 * Readable by anyone who can see the forum, because a plan is a price list and
 * a member has to read one before deciding to pay for it. Writable by admins
 * only. The Stripe ids are the exception in both directions: they are the
 * owner's account's business and no member has any use for them.
 *
 * @extends AbstractDatabaseResource<Plan>
 */
class PlanResource extends AbstractDatabaseResource
{
    public function __construct(
        protected Groups $groups,
        protected PlanSync $sync
    ) {
    }

    public function type(): string
    {
        return 'flock-plans';
    }

    public function model(): string
    {
        return Plan::class;
    }

    public function scope(Builder $query, Context $context): void
    {
        // Members see what is on sale. Admins see everything, including plans
        // they have retired and ones not yet pushed to Stripe.
        if (! $this->isAdmin($context)) {
            $query->where('is_active', true);
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->authenticated()->admin(),
            Endpoint\Update::make()->authenticated()->admin(),
            Endpoint\Delete::make()->authenticated()->admin(),
            Endpoint\Show::make(),
            Endpoint\Index::make(),
        ];
    }

    /**
     * A plan without a group grants nothing, so it gets one the moment it
     * exists rather than waiting for the owner to notice. An owner who would
     * rather use a group they already run can repoint it afterwards, which is
     * why this never overwrites a group that is already set.
     */
    public function created(object $model, Context $context): ?object
    {
        if ($model instanceof Plan) {
            if ($model->group_id === null) {
                $this->groups->forPlan($model);
            }

            // Straight away, so the owner sees whether it is sellable in the
            // same response rather than wondering. A Stripe that says no leaves
            // the plan saved and unsellable rather than failing the save: the
            // plan is theirs, the outage is not.
            $this->sync->push($model);
        }

        return $model;
    }

    /**
     * An edited plan follows to Stripe. Name and description are editable
     * there; the money is not, so a changed price becomes a new Price and
     * everyone already subscribed keeps the one they agreed to.
     */
    public function saved(object $model, Context $context): ?object
    {
        if ($model instanceof Plan && $model->wasChanged(['name', 'description', 'amount', 'currency', 'interval'])) {
            $this->sync->push($model);
        }

        return $model;
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(100),

            Schema\Str::make('description')
                ->nullable()
                ->writable()
                ->maxLength(500),

            // Minor units. One is the smallest thing Stripe will charge, and a
            // free tier is a group the owner can simply grant.
            Schema\Integer::make('amount')
                ->requiredOnCreate()
                ->writable()
                ->min(1),

            Schema\Str::make('currency')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(3),

            Schema\Str::make('interval')
                ->requiredOnCreate()
                ->writable(),

            Schema\Boolean::make('isActive')
                ->property('is_active')
                ->writable(),

            // Repointing a plan at a group the owner already runs is deliberate
            // and supported; creating one is what happens if they do not.
            Schema\Integer::make('groupId')
                ->property('group_id')
                ->nullable()
                ->writable(),

            Schema\Boolean::make('isSellable')
                ->get(function (Plan $plan): bool {
                    try {
                        return $plan->isSellable();
                    } catch (\Throwable) {
                        return false;
                    }
                }),

            Schema\Str::make('stripePriceId')
                ->property('stripe_price_id')
                ->nullable()
                ->visible(fn (Plan $plan, Context $context) => $this->isAdmin($context)),

            Schema\Str::make('stripeProductId')
                ->property('stripe_product_id')
                ->nullable()
                ->visible(fn (Plan $plan, Context $context) => $this->isAdmin($context)),

            Schema\DateTime::make('createdAt')
                ->property('created_at'),
        ];
    }

    /** Fail closed: a field that cannot decide is a field nobody sees. */
    private function isAdmin(Context $context): bool
    {
        try {
            return $context->getActor()->isAdmin();
        } catch (\Throwable) {
            return false;
        }
    }
}
