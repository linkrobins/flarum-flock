<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Flarum\Foundation\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Asks linkrobins.com whether this forum's Flock key is good.
 *
 * The only call this extension ever makes to us, and the only one a forum's
 * memberships could ever depend on. So it is written to be ignorable: short
 * timeouts, no exceptions out, and null for "we could not ask", which is a
 * different thing from "the answer was no" and is treated differently by
 * [[KeyStatus]].
 */
class KeyClient
{
    public const ENDPOINT = 'https://linkrobins.com/api/flock/status';

    /**
     * Short on purpose. This runs while an admin waits on a settings save, and
     * on nothing else: no member request, no checkout, no webhook ever waits on
     * our availability.
     */
    private const TIMEOUT = 5;

    public function __construct(
        protected Settings $settings,
        protected Config $config
    ) {
    }

    /**
     * @param bool $bind Claim this forum for the key. True only when the admin
     *                   saved the key, because that save is the commitment.
     * @return array{status: string, forum_url: ?string, last_seen_at: ?string}|null
     *         null means unreachable, which is never the same as a refusal.
     */
    public function status(bool $bind = false): ?array
    {
        $key = $this->settings->key();

        if ($key === '') {
            return null;
        }

        try {
            $response = (new Client())->post(self::ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer '.$key,
                    'Accept' => 'application/json',
                    // Cloudflare 1010s an unidentified client before it ever
                    // reaches the app.
                    'User-Agent' => 'Flock/1.0 (+https://linkrobins.com/flock)',
                ],
                'json' => array_filter([
                    'forum_url' => $this->forumUrl(),
                    'bind' => $bind ? 1 : null,
                ], fn ($value) => $value !== null),
                'connect_timeout' => self::TIMEOUT,
                'timeout' => self::TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            // Unreachable host, DNS, TLS, a timeout. Every one of them means
            // "we could not ask", never "the owner may not sell".
            return null;
        }

        // 401 is the one refusal that arrives as a status code: no key at all.
        if ($response->getStatusCode() === 401) {
            return ['status' => Settings::STATUS_INVALID, 'forum_url' => null, 'last_seen_at' => null];
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || ! isset($body['status']) || ! is_string($body['status'])) {
            return null;
        }

        return [
            'status' => $body['status'],
            'forum_url' => is_string($body['forum_url'] ?? null) ? $body['forum_url'] : null,
            'last_seen_at' => is_string($body['last_seen_at'] ?? null) ? $body['last_seen_at'] : null,
        ];
    }

    protected function forumUrl(): string
    {
        return (string) $this->config->url();
    }
}
