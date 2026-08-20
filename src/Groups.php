<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Flarum\Foundation\DispatchEventsTrait;
use Flarum\Group\Group;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Arr;

/**
 * Every group change this extension makes goes through here.
 *
 * Two reasons it is one place. First, membership is the ONLY thing Flock is
 * ever allowed to change about a person: no user is deleted, suspended or
 * edited, and no post is touched, whatever happens with a card. Second, other
 * extensions have to see these changes. Badges, audit logs and notification
 * extensions all listen for GroupsChanged, so a quiet `groups()->attach()`
 * would grant access that the rest of the forum never hears about.
 *
 * The shape is core's own, from UserResource: capture the old groups, raise the
 * event on the user, sync, then dispatch what was raised.
 */
class Groups
{
    use DispatchEventsTrait;

    // The trait dispatches through $this->events and does not declare it, so
    // this class must: a dynamic property would be a deprecation in 8.2 and
    // gone in a later PHP.
    protected Dispatcher $events;

    public function __construct(Dispatcher $events)
    {
        $this->events = $events;
    }

    /** Idempotent: granting a group somebody already has changes nothing and announces nothing. */
    public function grant(User $user, Group $group): bool
    {
        $current = $user->groups()->get()->all();

        if (in_array($group->id, Arr::pluck($current, 'id'))) {
            return false;
        }

        $this->sync($user, $current, array_merge(Arr::pluck($current, 'id'), [$group->id]));

        return true;
    }

    /** Idempotent in the same way, and the only removal this extension performs. */
    public function revoke(User $user, Group $group): bool
    {
        $current = $user->groups()->get()->all();
        $ids = Arr::pluck($current, 'id');

        if (! in_array($group->id, $ids)) {
            return false;
        }

        $this->sync($user, $current, array_values(array_diff($ids, [$group->id])));

        return true;
    }

    /**
     * The group behind a plan, created on demand.
     *
     * Named after the plan and hidden by default, since a paid tier is not a
     * badge to wear unless the owner decides otherwise. The owner may repoint
     * a plan at a group they already run, which is why nothing here assumes it
     * made the group it is looking at.
     */
    public function forPlan(Plan $plan): Group
    {
        if ($plan->group_id !== null && ($existing = Group::find($plan->group_id))) {
            return $existing;
        }

        $group = new Group();
        $group->rename($plan->name, $plan->name);
        // Deliberately no colour and no icon. This group lives on the owner's
        // forum and belongs to them, so it arrives plain and they can dress it
        // however their forum is dressed. Branding somebody else's group with
        // ours would be presumptuous, and it is hidden by default anyway.
        $group->is_hidden = true;
        $group->save();

        $this->dispatchEventsFor($group);

        $plan->group_id = $group->id;
        $plan->save();

        return $group;
    }

    /**
     * @param array<int, Group> $oldGroups
     * @param array<int, int|string> $newIds
     */
    private function sync(User $user, array $oldGroups, array $newIds): void
    {
        $user->raise(new GroupsChanged($user, $oldGroups));

        $user->afterSave(function (User $user) use ($newIds) {
            $user->groups()->sync($newIds);
            $user->unsetRelation('groups');
        });

        $user->save();

        $this->dispatchEventsFor($user);
    }
}
