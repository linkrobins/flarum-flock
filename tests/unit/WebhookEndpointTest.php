<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\unit;

use Flarum\Foundation\Config;
use Flarum\Testing\unit\TestCase;
use LinkRobins\Flock\Settings;
use LinkRobins\Flock\Stripe\Stripe;
use LinkRobins\Flock\Stripe\WebhookEndpoint;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/**
 * Where the extension asks Stripe to deliver, and where it refuses to ask.
 *
 * The refusals matter more than they look. Registering an endpoint Stripe can
 * never reach leaves an owner with a dead webhook and no sign of why, and
 * pointing one at a private address is asking Stripe to knock on a door inside
 * somebody's network.
 */
class WebhookEndpointTest extends TestCase
{
    /** @test */
    #[Test]
    public function the_url_is_this_forum_plus_the_route(): void
    {
        $this->assertSame(
            'https://forum.example/api/linkrobins-flock/stripe',
            $this->endpoint('https://forum.example')->url()
        );
    }

    /** @test */
    #[Test]
    public function a_trailing_slash_does_not_double_up(): void
    {
        $this->assertSame(
            'https://forum.example/api/linkrobins-flock/stripe',
            $this->endpoint('https://forum.example/')->url()
        );
    }

    /** @test */
    #[Test]
    public function a_forum_stripe_can_reach_is_deliverable(): void
    {
        foreach (['https://forum.example', 'https://forum.example/community', 'http://sub.domain.co.uk'] as $url) {
            $this->assertTrue($this->deliverable($url), $url);
        }
    }

    /**
     * A developer's forum. Nothing is registered, nothing is said, and the
     * Stripe CLI's forwarding is the right tool there.
     *
     * @test
     */
    #[Test]
    public function a_local_forum_is_not_deliverable(): void
    {
        foreach (['http://localhost', 'http://flarum.localhost', 'http://myforum.test', 'http://127.0.0.1'] as $url) {
            $this->assertFalse($this->deliverable($url), $url);
        }
    }

    /**
     * Asking Stripe to POST to a private address is asking it to knock on a
     * door inside somebody else's network.
     *
     * @test
     */
    #[Test]
    public function a_private_address_is_not_deliverable(): void
    {
        foreach (['http://10.0.0.5', 'http://192.168.1.10', 'http://172.16.4.4'] as $url) {
            $this->assertFalse($this->deliverable($url), $url);
        }
    }

    /**
     * The events it asks for, and nothing beyond them. Every extra event is
     * data about the owner's business that this extension has no use for.
     *
     * @test
     */
    #[Test]
    public function it_subscribes_only_to_subscription_events(): void
    {
        foreach (WebhookEndpoint::EVENTS as $event) {
            $this->assertTrue(
                str_starts_with($event, 'checkout.') || str_starts_with($event, 'customer.subscription.') || str_starts_with($event, 'invoice.'),
                $event.' is not about a subscription'
            );
        }
    }

    private function deliverable(string $url): bool
    {
        $endpoint = $this->endpoint($url);

        return (fn () => $this->deliverable($this->url()))->call($endpoint);
    }

    private function endpoint(string $url): WebhookEndpoint
    {
        $settings = new Settings(new ArraySettings());

        return new WebhookEndpoint(
            new Stripe($settings),
            $settings,
            new ArraySettings(),
            new Config(['url' => $url]),
            new NullLogger()
        );
    }
}
