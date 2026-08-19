<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Flock\Settings;
use LinkRobins\Flock\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * What the Join page is told before it draws.
 *
 * These two fields ship on every forum response, including for forums whose
 * owner has never opened Flock's settings, so the thing worth testing is that
 * they are quiet and safe there rather than that they are clever.
 */
class ForumFieldsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-flock');

        $this->prepareDatabase([
            'users' => [$this->normalUser()], // id 2
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
            'flock_plans' => [
                ['id' => 1, 'name' => 'Supporter', 'group_id' => 100, 'amount' => 500, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1', 'is_active' => 1],
            ],
        ]);
    }

    /** @test */
    #[Test]
    public function a_forum_without_flock_set_up_says_nothing_and_sells_nothing(): void
    {
        $attributes = $this->forum();

        $this->assertSame([], $attributes['flockPlanIds']);
        $this->assertFalse($attributes['flockSelling']);
    }

    /** @test */
    #[Test]
    public function a_guest_holds_no_memberships(): void
    {
        $this->assertSame([], $this->forum()['flockPlanIds']);
    }

    /**
     * So the page can offer what they do not have rather than inviting them to
     * buy the same group twice.
     *
     * @test
     */
    #[Test]
    public function a_member_sees_the_plans_they_are_entitled_to(): void
    {
        $this->subscription('active', Carbon::now()->addMonth());

        $this->assertSame([1], $this->forum(2)['flockPlanIds']);
    }

    /**
     * Entitlement, not history: a subscription whose time is up stops counting
     * without anything having to delete the row.
     *
     * @test
     */
    #[Test]
    public function a_lapsed_membership_stops_counting(): void
    {
        $this->subscription('canceled', Carbon::now()->subDay());

        $this->assertSame([], $this->forum(2)['flockPlanIds']);
    }

    /** @test */
    #[Test]
    public function a_connected_forum_says_it_is_selling(): void
    {
        $this->setting(Settings::KEY, 'flk_test');
        $this->setting(Settings::STATUS, Settings::STATUS_ACTIVE);

        $this->assertTrue($this->forum()['flockSelling']);
    }

    protected function subscription(string $status, Carbon $until): void
    {
        $this->app();

        // Set rather than mass-assigned: the model guards its attributes,
        // since everything that writes one in production sets them by hand.
        $subscription = new Subscription();
        $subscription->user_id = 2;
        $subscription->plan_id = 1;
        $subscription->stripe_subscription_id = 'sub_1';
        $subscription->status = $status;
        $subscription->access_until = $until;
        $subscription->save();
    }

    /** @return array<string, mixed> */
    protected function forum(?int $as = null): array
    {
        $options = $as === null ? [] : ['authenticatedAs' => $as];

        $response = $this->send($this->request('GET', '/api', $options));

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['data']['attributes'];
    }
}
