<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration;

use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use LinkRobins\Flock\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * The road back from Stripe, and the two ways it goes wrong if written the
 * obvious way.
 *
 * A member returning from Checkout arrives with a session id in a URL. A URL
 * can be shared, replayed, or opened by somebody else entirely, so the only
 * facts this path may use are the ones Stripe gives back when asked.
 */
class FulfilmentTest extends TestCase
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
            'users' => [
                $this->normalUser(), // id 2, the member who pays
                ['id' => 3, 'username' => 'bystander', 'password' => 'x', 'email' => 'b@example.com', 'is_email_confirmed' => 1],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
            'flock_plans' => [
                ['id' => 1, 'name' => 'Supporter', 'group_id' => 100, 'amount' => 500, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1', 'stripe_product_id' => 'prod_1', 'is_active' => 1],
            ],
        ]);

        FakeGateway::forget();
    }

    /**
     * The classic bug in this pattern, and the reason the beneficiary is read
     * from Stripe rather than from the session cookie: if member A's return URL
     * is opened in member B's browser, B must not end up with what A paid for.
     *
     * @test
     */
    #[Test]
    public function the_membership_goes_to_whoever_stripe_says_paid_for_it(): void
    {
        $this->stripeSays(userId: 2, status: 'active');

        // Opened by member 3, holding member 2's session id.
        $this->complete(as: 3);

        $this->assertTrue($this->user(2)->groups->contains('id', 100), 'the member who paid has it');
        $this->assertFalse($this->user(3)->groups->contains('id', 100), 'the member who opened the link does not');
    }

    /**
     * Owners run full-discount codes, and Stripe marks those sessions
     * no_payment_required rather than paid. Gating on "paid" would leave every
     * one of those members looking at a page that promised instant access.
     *
     * @test
     */
    #[Test]
    public function a_hundred_percent_promo_code_still_gets_access(): void
    {
        $this->stripeSays(userId: 2, status: 'active', payment: 'no_payment_required');

        $this->complete();

        $this->assertTrue($this->user(2)->groups->contains('id', 100));
    }

    /**
     * The zombie rule. An abandoned checkout has no subscription behind it, and
     * must leave nothing at all.
     *
     * @test
     */
    #[Test]
    public function an_abandoned_checkout_leaves_nothing(): void
    {
        FakeGateway::$session = ['subscription' => null, 'status' => 'unpaid', 'user_id' => 2, 'plan_id' => 1];

        $this->complete();

        $this->assertSame(0, Subscription::query()->count());
        $this->assertFalse($this->user(2)->groups->contains('id', 100));
    }

    /**
     * A card that has not cleared is not a membership yet.
     *
     * @test
     */
    #[Test]
    public function an_incomplete_subscription_grants_nothing(): void
    {
        $this->stripeSays(userId: 2, status: 'incomplete');

        $this->complete();

        $this->assertSame(1, Subscription::query()->count(), 'recorded, because Stripe knows about it');
        $this->assertFalse($this->user(2)->groups->contains('id', 100), 'but it grants nothing');
    }

    /**
     * The return and the webhook both call the same path, and a member can
     * refresh the page. Whichever arrives second must find the work done.
     *
     * @test
     */
    #[Test]
    public function arriving_twice_changes_nothing_the_second_time(): void
    {
        $this->stripeSays(userId: 2, status: 'active');

        $this->complete();
        $this->complete();

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, $this->user(2)->groups->where('id', 100)->count());
    }

    /**
     * A bank declining a renewal on a Tuesday is not a decision to leave, so
     * the group stays while Stripe retries.
     *
     * @test
     */
    #[Test]
    public function a_failed_renewal_keeps_access_through_the_grace_window(): void
    {
        $this->stripeSays(userId: 2, status: 'active');
        $this->complete();

        $this->stripeSays(userId: 2, status: 'past_due', periodEnd: time() - 3600);
        $this->complete();

        $this->assertTrue($this->user(2)->groups->contains('id', 100), 'still a member while the card is retried');

        $subscription = Subscription::query()->firstOrFail();

        $this->assertTrue($subscription->access_until->isFuture());
    }

    /** A subscription Stripe says is over takes back the group, and nothing else. */
    #[Test]
    public function a_finished_subscription_takes_back_the_group_only(): void
    {
        $this->stripeSays(userId: 2, status: 'active');
        $this->complete();

        $before = $this->user(2)->getAttributes();

        $this->stripeSays(userId: 2, status: 'canceled', periodEnd: time() - 86400);
        $this->complete();

        $this->assertFalse($this->user(2)->groups->contains('id', 100));

        $after = $this->user(2)->getAttributes();
        unset($before['updated_at'], $after['updated_at']);

        $this->assertSame($before, $after, 'the person is untouched');
    }

    protected function stripeSays(int $userId, string $status, string $payment = 'paid', ?int $periodEnd = null): void
    {
        FakeGateway::$session = [
            'subscription' => 'sub_1',
            'status' => $payment,
            'user_id' => $userId,
            'plan_id' => 1,
        ];

        FakeGateway::$subscription = [
            'id' => 'sub_1',
            'status' => $status,
            'customer' => 'cus_1',
            'period_end' => $periodEnd ?? time() + 30 * 86400,
            'cancel_at_period_end' => false,
            'user_id' => $userId,
            'plan_id' => 1,
        ];
    }

    protected function complete(?int $as = null): void
    {
        $options = $as === null ? [] : ['authenticatedAs' => $as];

        $response = $this->send(
            $this->request('GET', '/flock/complete', $options)->withQueryParams(['session_id' => 'cs_1'])
        );

        $this->assertEquals(302, $response->getStatusCode(), 'the member is sent back to the forum');
    }

    protected function user(int $id): User
    {
        return User::query()->findOrFail($id);
    }
}
