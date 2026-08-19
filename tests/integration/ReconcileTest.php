<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration;

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use LinkRobins\Flock\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * What happens when a webhook never arrives.
 *
 * The expensive failure is the silent one: a subscription that ended, a
 * deletion event that was missed, and a member holding a paid group forever.
 * Nothing else in this extension notices that on its own, so these tests are
 * the ones standing between the owner and giving memberships away.
 */
class ReconcileTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-flock');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeGatewayProvider::class)
        );

        $this->prepareDatabase([
            'users' => [$this->normalUser()], // id 2
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
            'flock_plans' => [
                ['id' => 1, 'name' => 'Supporter', 'group_id' => 100, 'amount' => 500, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1', 'stripe_product_id' => 'prod_1', 'is_active' => 1],
            ],
            'group_user' => [
                ['user_id' => 2, 'group_id' => 100],
            ],
            'flock_subscriptions' => [
                [
                    'id' => 1,
                    'user_id' => 2,
                    'plan_id' => 1,
                    'stripe_subscription_id' => 'sub_1',
                    'stripe_customer_id' => 'cus_1',
                    'status' => 'active',
                    // Paid up until yesterday, and nothing has told the forum
                    // what happened since.
                    'access_until' => Carbon::now()->subDay()->toDateTimeString(),
                    'created_at' => Carbon::now()->subMonth()->toDateTimeString(),
                    'updated_at' => Carbon::now()->subMonth()->toDateTimeString(),
                ],
            ],
        ]);

        FakeGateway::forget();
    }

    /**
     * The one that costs the owner money if it fails: no webhook ever arrives,
     * and the membership still ends.
     *
     * @test
     */
    #[Test]
    public function an_ended_subscription_loses_its_group_with_no_webhook_at_all(): void
    {
        FakeGateway::$subscription = $this->stripeSays('canceled', periodEnd: time() - 86400);

        $this->visitForum();

        $this->assertFalse($this->member()->groups->contains('id', 100));
        $this->assertSame('canceled', Subscription::query()->find(1)->status);
    }

    /**
     * The mirror of it: a renewal whose webhook was missed must not cost a
     * paying member their access.
     *
     * @test
     */
    #[Test]
    public function a_renewal_we_never_heard_about_keeps_the_member_in(): void
    {
        FakeGateway::$subscription = $this->stripeSays('active', periodEnd: time() + 30 * 86400);

        $this->visitForum();

        $this->assertTrue($this->member()->groups->contains('id', 100));
        $this->assertTrue(Subscription::query()->find(1)->access_until->isFuture());
    }

    /**
     * An unreachable Stripe is not evidence about anybody, so nothing is taken
     * away on the strength of it.
     *
     * @test
     */
    #[Test]
    public function stripe_being_unreachable_takes_nothing_away(): void
    {
        FakeGateway::$subscription = null;

        $this->visitForum();

        $this->assertTrue($this->member()->groups->contains('id', 100), 'still a member');
        $this->assertSame('active', Subscription::query()->find(1)->status, 'and nothing was recorded');
    }

    /**
     * It runs on requests members are already making, so "how often" is the
     * whole cost question.
     *
     * @test
     */
    #[Test]
    public function one_member_is_only_asked_about_once_an_hour(): void
    {
        FakeGateway::$subscription = $this->stripeSays('active', periodEnd: time() + 30 * 86400);

        $this->visitForum();
        $this->visitForum();
        $this->visitForum();

        $asked = array_filter(FakeGateway::$asked, fn ($call) => str_starts_with($call, 'subscription:'));

        $this->assertCount(1, $asked, 'three page loads, one question');
    }

    /**
     * A fresh row that has not expired is nobody's problem yet.
     *
     * @test
     */
    #[Test]
    public function a_healthy_recent_subscription_is_left_alone(): void
    {
        // Boot before touching Eloquent: nothing has made a request yet.
        $this->app();

        Subscription::query()->find(1)->forceFill([
            'access_until' => Carbon::now()->addWeek(),
            'updated_at' => Carbon::now()->subMinutes(5),
        ])->save();

        $this->visitForum();

        $this->assertSame([], FakeGateway::$asked, 'Stripe was not troubled');
    }

    /**
     * The owner's button asks about everything, regardless of freshness.
     *
     * @test
     */
    #[Test]
    public function sync_now_asks_about_everything(): void
    {
        $this->app();

        Subscription::query()->find(1)->forceFill([
            'access_until' => Carbon::now()->addWeek(),
            'updated_at' => Carbon::now(),
        ])->save();

        FakeGateway::$subscription = $this->stripeSays('active', periodEnd: time() + 30 * 86400);

        $response = $this->send($this->request('POST', '/api/linkrobins-flock/sync', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame(1, json_decode((string) $response->getBody(), true)['checked']);
    }

    /** @test */
    #[Test]
    public function sync_now_is_not_for_members(): void
    {
        $this->assertEquals(403, $this->send($this->request('POST', '/api/linkrobins-flock/sync', ['authenticatedAs' => 2]))->getStatusCode());
    }

    /** @return array<string, mixed> */
    protected function stripeSays(string $status, int $periodEnd): array
    {
        return [
            'id' => 'sub_1',
            'status' => $status,
            'customer' => 'cus_1',
            'period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'user_id' => 2,
            'plan_id' => 1,
        ];
    }

    /** A page load by the member, which is all the lazy path needs. */
    protected function visitForum(): void
    {
        $this->send($this->request('GET', '/', ['authenticatedAs' => 2]));
    }

    protected function member(): User
    {
        return User::query()->findOrFail(2);
    }
}
