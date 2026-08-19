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
use Psr\Http\Message\ResponseInterface;
use Laminas\Diactoros\StreamFactory;
use LinkRobins\Flock\Settings;
use LinkRobins\Flock\Subscription;
use PHPUnit\Framework\Attributes\Test;

/**
 * The webhook's URL is public, so anyone can POST to it. The signature is the
 * entire reason to believe any of it, and these tests are mostly about refusing
 * things rather than accepting them.
 */
class WebhookTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SECRET = 'whsec_testsecret';

    public function setUp(): void
    {
        parent::setUp();

        $this->extension('linkrobins-flock');

        $this->extend(
            (new Extend\ServiceProvider())->register(FakeGatewayProvider::class)
        );

        $this->setting(Settings::WEBHOOK_SECRET, self::SECRET);

        $this->prepareDatabase([
            'users' => [
                $this->normalUser(), // id 2
            ],
            'groups' => [
                ['id' => 100, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters', 'is_hidden' => 1],
            ],
            'flock_plans' => [
                ['id' => 1, 'name' => 'Supporter', 'group_id' => 100, 'amount' => 500, 'currency' => 'usd', 'interval' => 'month', 'stripe_price_id' => 'price_1', 'stripe_product_id' => 'prod_1', 'is_active' => 1],
            ],
        ]);

        FakeGateway::forget();
        FakeGateway::$subscription = [
            'id' => 'sub_1',
            'status' => 'active',
            'customer' => 'cus_1',
            'period_end' => time() + 30 * 86400,
            'cancel_at_period_end' => false,
            'user_id' => 2,
            'plan_id' => 1,
        ];
    }

    /** @test */
    #[Test]
    public function a_signed_event_grants_the_membership(): void
    {
        $payload = $this->payload('customer.subscription.updated', ['id' => 'sub_1']);

        $this->assertEquals(200, $this->post($payload, $this->sign($payload))->getStatusCode());
        $this->assertTrue($this->member()->groups->contains('id', 100));
    }

    /**
     * The one that matters. Anyone can POST here.
     *
     * @test
     */
    #[Test]
    public function an_unsigned_or_forged_event_is_refused_and_changes_nothing(): void
    {
        $payload = $this->payload('customer.subscription.updated', ['id' => 'sub_1']);

        foreach (['', 'garbage', $this->sign($payload, 'whsec_thewrongsecret')] as $signature) {
            $response = $this->post($payload, $signature);

            $this->assertEquals(400, $response->getStatusCode(), 'signature: '.$signature);
        }

        $this->assertSame(0, Subscription::query()->count());
        $this->assertFalse($this->member()->groups->contains('id', 100));
        $this->assertSame([], FakeGateway::$asked, 'Stripe was never even asked about it');
    }

    /**
     * A captured webhook replayed next week must not still work, which is what
     * the signed timestamp is for.
     *
     * @test
     */
    #[Test]
    public function a_stale_replay_is_refused(): void
    {
        $payload = $this->payload('customer.subscription.updated', ['id' => 'sub_1']);
        $signature = $this->sign($payload, timestamp: time() - 3600);

        $this->assertEquals(400, $this->post($payload, $signature)->getStatusCode());
        $this->assertFalse($this->member()->groups->contains('id', 100));
    }

    /**
     * Nothing is registered yet, so nothing can be verified, so nothing is
     * believed.
     *
     * @test
     */
    #[Test]
    public function a_forum_with_no_secret_refuses_everything(): void
    {
        $this->setting(Settings::WEBHOOK_SECRET, '');

        $payload = $this->payload('customer.subscription.updated', ['id' => 'sub_1']);

        $this->assertEquals(400, $this->post($payload, $this->sign($payload))->getStatusCode());
    }

    /**
     * The zombie rule from this side: a checkout nobody completed carries no
     * subscription, and must leave nothing behind.
     *
     * @test
     */
    #[Test]
    public function a_checkout_with_no_subscription_leaves_nothing(): void
    {
        $payload = $this->payload('checkout.session.completed', ['id' => 'cs_1', 'subscription' => null]);

        $this->assertEquals(200, $this->post($payload, $this->sign($payload))->getStatusCode());
        $this->assertSame(0, Subscription::query()->count());
        $this->assertSame([], FakeGateway::$asked);
    }

    /**
     * Stripe retries anything that is not a 200, so an event this extension has
     * no use for is accepted and ignored rather than refused forever.
     *
     * @test
     */
    #[Test]
    public function an_event_we_do_not_care_about_is_accepted_and_ignored(): void
    {
        $payload = $this->payload('customer.created', ['id' => 'cus_1']);

        $this->assertEquals(200, $this->post($payload, $this->sign($payload))->getStatusCode());
        $this->assertSame(0, Subscription::query()->count());
    }

    /**
     * The return from checkout and the webhook do the same work. Whichever is
     * second finds it done.
     *
     * @test
     */
    #[Test]
    public function a_webhook_after_the_return_changes_nothing(): void
    {
        FakeGateway::$session = ['subscription' => 'sub_1', 'status' => 'paid', 'user_id' => 2, 'plan_id' => 1];

        $this->send($this->request('GET', '/flock/complete')->withQueryParams(['session_id' => 'cs_1']));

        $payload = $this->payload('customer.subscription.updated', ['id' => 'sub_1']);
        $this->post($payload, $this->sign($payload));

        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, $this->member()->groups->where('id', 100)->count());
    }

    /** @param array<string, mixed> $object */
    protected function payload(string $type, array $object): string
    {
        return json_encode([
            'id' => 'evt_1',
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);
    }

    /** Stripe's scheme: the timestamp and body are signed together. */
    protected function sign(string $payload, string $secret = self::SECRET, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return 't='.$timestamp.',v1='.$signature;
    }

    protected function post(string $payload, string $signature): ResponseInterface
    {
        // A body built from the raw bytes, because that is what the signature
        // covers: re-encoding the JSON anywhere in between would invalidate it,
        // which is also true in production.
        $request = $this->request('POST', '/api/linkrobins-flock/stripe')
            ->withHeader('Stripe-Signature', $signature)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($payload));

        return $this->send($request);
    }

    protected function member(): User
    {
        return User::query()->findOrFail(2);
    }
}
