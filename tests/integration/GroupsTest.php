<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration;

use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use LinkRobins\Flock\Groups;
use PHPUnit\Framework\Attributes\Test;

/**
 * Membership is the only thing this extension may change about a person, and it
 * has to change it loudly: badge, audit and notification extensions all listen
 * for GroupsChanged, so a quiet attach would hand out access the rest of the
 * forum never hears about.
 */
class GroupsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-flock');

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
        ]);
    }

    /** @test */
    #[Test]
    public function granting_adds_the_group_and_announces_it(): void
    {
        $heard = $this->listen();

        $this->assertTrue($this->flock()->grant($this->user(), $this->group()));

        $this->assertTrue($this->user()->groups->contains('id', 100));
        $this->assertCount(1, $heard, 'GroupsChanged was dispatched');
    }

    /** @test */
    #[Test]
    public function revoking_removes_the_group_and_announces_it(): void
    {
        $this->flock()->grant($this->user(), $this->group());

        $heard = $this->listen();

        $this->assertTrue($this->flock()->revoke($this->user(), $this->group()));

        $this->assertFalse($this->user()->groups->contains('id', 100));
        $this->assertCount(1, $heard);
    }

    /**
     * A webhook can arrive twice for the same subscription, and a reconcile can
     * land on top of a webhook. Neither may produce a second announcement, or
     * every listener downstream sees an event that did not happen.
     *
     * @test
     */
    #[Test]
    public function granting_twice_changes_nothing_the_second_time(): void
    {
        $this->flock()->grant($this->user(), $this->group());

        $heard = $this->listen();

        $this->assertFalse($this->flock()->grant($this->user(), $this->group()));
        $this->assertCount(0, $heard, 'nothing to announce');
    }

    /** @test */
    #[Test]
    public function revoking_a_group_they_never_had_changes_nothing(): void
    {
        $heard = $this->listen();

        $this->assertFalse($this->flock()->revoke($this->user(), $this->group()));
        $this->assertCount(0, $heard);
    }

    /**
     * The rule that outranks the rest of this class: nothing here may touch a
     * person beyond their groups.
     *
     * @test
     */
    #[Test]
    public function nothing_about_the_user_changes_except_their_groups(): void
    {
        // Boot before touching Eloquent: every other test here reaches the
        // container through listen() first, and this one does not.
        $this->app();

        $before = $this->user()->getAttributes();

        $this->flock()->grant($this->user(), $this->group());

        $after = $this->user()->getAttributes();

        unset($before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after);
    }

    /**
     * An ArrayObject rather than an array, because the array would be copied on
     * return and every append inside the listener would land in a value nobody
     * can see. (It did, and the tests passed nothing.)
     *
     * @return \ArrayObject<int, GroupsChanged>
     */
    protected function listen(): \ArrayObject
    {
        $heard = new \ArrayObject();

        $this->app()->getContainer()->make(Dispatcher::class)->listen(
            GroupsChanged::class,
            function (GroupsChanged $event) use ($heard) {
                $heard[] = $event;
            }
        );

        return $heard;
    }

    protected function flock(): Groups
    {
        return $this->app()->getContainer()->make(Groups::class);
    }

    protected function user(): User
    {
        return User::query()->findOrFail(2);
    }

    protected function group(): Group
    {
        return Group::query()->findOrFail(100);
    }
}
