<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration\api;

use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use LinkRobins\Flock\Settings;
use LinkRobins\Flock\Tests\integration\FakeGateway;
use LinkRobins\Flock\Tests\integration\FakeGatewayProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * The way out.
 *
 * A member who has paid has to be able to stop paying, so the cases that matter
 * here are the ones where something else has gone wrong: the forum's key has
 * lapsed, or the plans have been taken off sale. None of that may trap anybody.
 */
class PortalTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    public function setUp(): void
    {
        parent::setUp();

        FakeGateway::forget();

        $this->extension('linkrobins-flock');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeGatewayProvider::class)
        );

        $this->setting(Settings::KEY, 'flk_test');
        $this->setting(Settings::STATUS, Settings::STATUS_ACTIVE);

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
                ['id' => 3, 'username' => 'someone', 'email' => 'someone@machine.local', 'is_email_confirmed' => 1, 'password' => 'nope'],
                ['id' => 4, 'username' => 'nobody', 'email' => 'nobody@machine.local', 'is_email_confirmed' => 1, 'password' => 'nope'],
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
            'flock_plans' => [
                ['id' => 1, 'name' => 'Supporter', 'group_id' => 100, 'amount' => 500, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1', 'is_active' => 1],
            ],
            'flock_subscriptions' => [
                ['id' => 1, 'user_id' => 2, 'plan_id' => 1, 'stripe_subscription_id' => 'sub_2', 'stripe_customer_id' => 'cus_two', 'status' => 'active'],
                ['id' => 2, 'user_id' => 3, 'plan_id' => 1, 'stripe_subscription_id' => 'sub_3', 'stripe_customer_id' => 'cus_three', 'status' => 'canceled'],
            ],
        ]);
    }

    /** @test */
    #[Test]
    public function a_member_gets_a_portal_url(): void
    {
        $response = $this->portal(as: 2);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringStartsWith('https://', json_decode((string) $response->getBody(), true)['url']);
    }

    /**
     * The one that would be a billing leak rather than a bug: sending somebody
     * to another member's portal hands them a stranger's card details, invoices
     * and the power to cancel their membership.
     */
    /** @test */
    #[Test]
    public function a_member_is_sent_to_their_own_customer(): void
    {
        $this->portal(as: 3);

        $this->assertEquals(['portal:cus_three'], FakeGateway::$asked);
    }

    /**
     * A cancelled membership still has receipts, and somebody who has stopped
     * paying may still need to see what they were charged.
     */
    /** @test */
    #[Test]
    public function a_former_member_can_still_open_it(): void
    {
        $this->assertEquals(200, $this->portal(as: 3)->getStatusCode());
    }

    /** @test */
    #[Test]
    public function somebody_who_never_subscribed_is_told_so(): void
    {
        $response = $this->portal(as: 4);

        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * Which 4xx it is depends on which guard speaks first, and in this harness
     * a tokenless POST is turned away as CSRF before the actor is ever looked
     * at. Pinning the number would be testing the harness; what matters is that
     * a guest leaves with no URL and that Stripe was never asked for one.
     */
    /** @test */
    #[Test]
    public function a_guest_is_refused(): void
    {
        $response = $this->send($this->request('POST', '/api/linkrobins-flock/portal'));

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertLessThan(500, $response->getStatusCode());
        $this->assertArrayNotHasKey('url', (array) json_decode((string) $response->getBody(), true));
        $this->assertSame([], FakeGateway::$asked);
    }

    /**
     * The forum's own subscription to Flock has lapsed. That stops new sales
     * and must not stop anybody leaving: a forum whose key has run out cannot
     * be a forum whose members are stuck paying it.
     */
    /** @test */
    #[Test]
    public function a_lapsed_flock_key_does_not_trap_a_member(): void
    {
        $this->setting(Settings::STATUS, Settings::STATUS_CANCELED);
        $this->setting(Settings::GOOD_AT, (string) (time() - 86400 * 365));

        $this->assertEquals(200, $this->portal(as: 2)->getStatusCode());
    }

    /**
     * Usually an owner who has never switched the portal on in their Stripe
     * dashboard. Nothing has changed either way, and the member is told that.
     */
    /** @test */
    #[Test]
    public function stripe_refusing_is_a_502_not_a_500(): void
    {
        FakeGateway::$portal = null;

        $this->assertEquals(502, $this->portal(as: 2)->getStatusCode());
    }

    protected function portal(int $as): ResponseInterface
    {
        return $this->send(
            $this->request('POST', '/api/linkrobins-flock/portal', ['authenticatedAs' => $as])
        );
    }
}
