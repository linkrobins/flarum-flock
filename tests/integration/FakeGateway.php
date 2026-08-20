<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\integration;

use LinkRobins\Flock\Plan;
use LinkRobins\Flock\Stripe\Gateway;

/**
 * Stripe, answering whatever a test needs it to.
 *
 * Dictating the answers is the point: the interesting cases here are the ones
 * where the answer disagrees with what the browser brought, which is exactly
 * what cannot be arranged against the real thing.
 */
class FakeGateway extends Gateway
{
    /** @var array<string, mixed>|null */
    public static ?array $session = null;

    /** @var array<string, mixed>|null */
    public static ?array $subscription = null;

    /** @var list<string> */
    public static array $asked = [];

    /** Null stands for Stripe refusing to open the portal. */
    public static ?string $portal = 'https://billing.stripe.test/session';

    public function __construct()
    {
    }

    public static function forget(): void
    {
        self::$session = null;
        self::$subscription = null;
        self::$portal = 'https://billing.stripe.test/session';
        self::$asked = [];
    }

    public function checkout(Plan $plan, int $userId, ?string $email, string $successUrl, string $cancelUrl): ?string
    {
        return 'https://checkout.stripe.test/session';
    }

    public function portal(string $customerId, string $returnUrl): ?string
    {
        self::$asked[] = 'portal:'.$customerId;

        return self::$portal;
    }

    public function session(string $sessionId): ?array
    {
        self::$asked[] = 'session:'.$sessionId;

        return self::$session;
    }

    public function subscription(string $subscriptionId): ?array
    {
        self::$asked[] = 'subscription:'.$subscriptionId;

        return self::$subscription;
    }
}
