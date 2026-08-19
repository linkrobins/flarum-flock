<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Stripe;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;
use LinkRobins\Flock\Settings;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;

/**
 * "Webhooks configure themselves."
 *
 * That sentence is on the sales page, so this class is what makes it true: when
 * the owner saves their Stripe key, the extension registers its own endpoint in
 * their account and keeps the signing secret. There is no step where somebody
 * pastes a URL into a Stripe dashboard.
 *
 * The signing secret is the only thing that makes the webhook trustworthy, and
 * Stripe returns it exactly once, at creation. So an endpoint that already
 * exists is replaced rather than reused: without the secret we cannot verify
 * anything it sends, and an unverifiable webhook is one we refuse.
 */
class WebhookEndpoint
{
    /** What we ask Stripe to tell us about. Nothing else is any of our business. */
    public const EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.payment_failed',
        'invoice.paid',
    ];

    public function __construct(
        protected Stripe $stripe,
        protected Settings $settings,
        protected SettingsRepositoryInterface $repository,
        protected Config $config,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Register this forum's endpoint, replacing one we registered before.
     *
     * @return bool whether the forum can now be told about subscriptions
     */
    public function ensure(): bool
    {
        $client = $this->stripe->client();

        if ($client === null) {
            return false;
        }

        $url = $this->url();

        // Stripe will not deliver to a private address, and neither would we
        // want it to. A forum on localhost is a developer's forum: say nothing,
        // change nothing, and let them use the CLI's forwarding.
        if (! $this->deliverable($url)) {
            return false;
        }

        try {
            foreach ($client->webhookEndpoints->all(['limit' => 100])->data as $existing) {
                if ($existing->url === $url) {
                    // Ours, from a previous save. The secret it was created
                    // with is not retrievable, so it is no use to us now.
                    $client->webhookEndpoints->delete($existing->id);
                }
            }

            $endpoint = $client->webhookEndpoints->create([
                'url' => $url,
                'enabled_events' => self::EVENTS,
                'description' => 'Flock, memberships for '.$this->config->url()->getHost(),
                'metadata' => ['flock' => '1'],
            ]);

            // The one moment this secret is ever visible.
            $this->repository->set(Settings::WEBHOOK_SECRET, $endpoint->secret);

            return true;
        } catch (ApiErrorException $e) {
            // Most likely the restricted key is missing the Webhook Endpoints
            // scope. The owner needs to know, and the save must still succeed:
            // everything else about their key may be fine.
            $this->log->error('[Flock] Could not register the webhook endpoint: '.$e->getMessage());

            return false;
        }
    }

    public function url(): string
    {
        return rtrim((string) $this->config->url(), '/').'/api/linkrobins-flock/stripe';
    }

    /** Whether Stripe could reach this forum at all. */
    protected function deliverable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.test')) {
            return false;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP);

        if ($ip !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        return str_contains($host, '.');
    }
}
