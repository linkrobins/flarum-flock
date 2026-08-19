<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Flock\Settings;
use LinkRobins\Flock\Subscription;
use LinkRobins\Flock\Tests\integration\FakeGatewayProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Who may start a checkout, and who may not.
 *
 * The page hides what a member cannot do, but the page is not the boundary.
 * Everything refused here is refused for somebody who bypassed it.
 */
class CheckoutTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-flock');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeGatewayProvider::class)
        );

        $this->setting(Settings::KEY, 'flk_test');
        $this->setting(Settings::STATUS, Settings::STATUS_ACTIVE);

        $this->prepareDatabase([
            'users' => [$this->normalUser()], // id 2
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
            'flock_plans' => [
                ['id' => 1, 'name' => 'Supporter', 'group_id' => 100, 'amount' => 500, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1', 'is_active' => 1],
                // Never pushed to Stripe, so it cannot be bought.
                ['id' => 2, 'name' => 'Draft', 'group_id' => 100, 'amount' => 900, 'currency' => 'usd', 'interval' => 'month', 'is_active' => 1],
            ],
        ]);
    }

    /** @test */
    #[Test]
    public function a_member_gets_a_checkout_url(): void
    {
        $response = $this->checkout(1, as: 2);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringStartsWith('https://', json_decode((string) $response->getBody(), true)['url']);
    }

    /**
     * The one that costs a member money if it is missing: a second checkout for
     * a membership they already hold would bill them twice for one group.
     *
     * @test
     */
    #[Test]
    public function a_member_cannot_buy_the_same_membership_twice(): void
    {
        $this->app();

        $subscription = new Subscription();
        $subscription->user_id = 2;
        $subscription->plan_id = 1;
        $subscription->stripe_subscription_id = 'sub_1';
        $subscription->status = 'active';
        $subscription->access_until = Carbon::now()->addMonth();
        $subscription->save();

        $this->assertEquals(409, $this->checkout(1, as: 2)->getStatusCode());
    }

    /**
     * A lapsed membership is not a membership, so rejoining has to work.
     *
     * @test
     */
    #[Test]
    public function a_member_whose_subscription_ended_can_join_again(): void
    {
        $this->app();

        $subscription = new Subscription();
        $subscription->user_id = 2;
        $subscription->plan_id = 1;
        $subscription->stripe_subscription_id = 'sub_old';
        $subscription->status = 'canceled';
        $subscription->access_until = Carbon::now()->subDay();
        $subscription->save();

        $this->assertEquals(200, $this->checkout(1, as: 2)->getStatusCode());
    }

    /** @test */
    #[Test]
    public function a_plan_stripe_has_never_seen_cannot_be_bought(): void
    {
        $this->assertEquals(422, $this->checkout(2, as: 2)->getStatusCode());
    }

    /** @test */
    #[Test]
    public function a_forum_that_cannot_sell_starts_no_checkouts(): void
    {
        $this->setting(Settings::STATUS, Settings::STATUS_CANCELED);

        $this->assertEquals(422, $this->checkout(1, as: 2)->getStatusCode());
    }

    /** @test */
    #[Test]
    public function a_guest_cannot_start_a_checkout(): void
    {
        $response = $this->send(
            $this->request('POST', '/api/linkrobins-flock/checkout')->withParsedBody(['planId' => 1])
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
    }

    protected function checkout(int $planId, int $as): \Psr\Http\Message\ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/linkrobins-flock/checkout', ['authenticatedAs' => $as])
                ->withParsedBody(['planId' => $planId])
        );
    }
}
