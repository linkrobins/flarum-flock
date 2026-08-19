<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock\Tests\unit;

use Flarum\Testing\unit\TestCase;
use LinkRobins\Flock\Stripe\Gateway;
use PHPUnit\Framework\Attributes\Test;

/**
 * Where the end of a paid-for period lives, which Stripe moved.
 *
 * This is the date behind "a member who cancels keeps access until the period
 * they paid for ends". Read it from the wrong place and it comes back null,
 * which reads as "their time is already up" and takes the rest of the month off
 * somebody who paid for it the day they cancel.
 */
class PeriodEndTest extends TestCase
{
    /** Where it lives now: on the item, because items can be on different cycles. */
    #[Test]
    public function it_reads_the_period_from_the_subscription_item(): void
    {
        $subscription = $this->subscription(itemEnd: 1800000000);

        $this->assertSame(1800000000, Gateway::periodEnd($subscription));
    }

    /**
     * An account pinned to an older API version still sends the flat field, and
     * nothing else would tell us.
     *
     * @test
     */
    #[Test]
    public function it_falls_back_to_the_old_flat_field(): void
    {
        $subscription = $this->subscription(itemEnd: null, flatEnd: 1799999999);

        $this->assertSame(1799999999, Gateway::periodEnd($subscription));
    }

    /** @test */
    #[Test]
    public function the_item_wins_when_both_are_present(): void
    {
        $subscription = $this->subscription(itemEnd: 1800000000, flatEnd: 1700000000);

        $this->assertSame(1800000000, Gateway::periodEnd($subscription));
    }

    /**
     * No date at all is a real answer, and callers have to treat it as "not
     * known" rather than as "expired".
     *
     * @test
     */
    #[Test]
    public function a_subscription_with_no_period_gives_null(): void
    {
        $this->assertNull(Gateway::periodEnd($this->subscription()));
        $this->assertNull(Gateway::periodEnd((object) []));
    }

    private function subscription(?int $itemEnd = null, ?int $flatEnd = null): object
    {
        $item = $itemEnd === null ? (object) [] : (object) ['current_period_end' => $itemEnd];

        $subscription = (object) [
            'items' => (object) ['data' => [$item]],
        ];

        if ($flatEnd !== null) {
            $subscription->current_period_end = $flatEnd;
        }

        return $subscription;
    }
}
