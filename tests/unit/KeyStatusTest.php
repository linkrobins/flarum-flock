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
use LinkRobins\Flock\KeyClient;
use LinkRobins\Flock\KeyStatus;
use LinkRobins\Flock\Settings;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rule that outranks everything else in this extension: a member's paid
 * access must never depend on linkrobins.com being up.
 *
 * So these tests are about one method. canSellNew() is the only gate in the
 * extension, it governs new checkouts and nothing else, and the interesting
 * question is what it does when we are the thing that is broken.
 */
class KeyStatusTest extends TestCase
{
    /** @test */
    #[Test]
    public function a_forum_with_no_key_cannot_sell(): void
    {
        $this->assertFalse($this->keyStatus([])->canSellNew());
    }

    /** @test */
    #[Test]
    public function an_active_key_can_sell(): void
    {
        $this->assertTrue($this->keyStatus([
            Settings::KEY => 'flk_test',
            Settings::STATUS => Settings::STATUS_ACTIVE,
        ])->canSellNew());
    }

    /**
     * The whole point. We are down, the owner is fine.
     *
     * @test
     */
    #[Test]
    public function an_outage_does_not_stop_an_owner_selling(): void
    {
        $status = $this->keyStatus([
            Settings::KEY => 'flk_test',
            Settings::STATUS => Settings::STATUS_UNREACHABLE,
            Settings::GOOD_AT => self::NOW - 6 * 86400,
        ]);

        $this->assertTrue($status->canSellNew(), 'six days into an outage');
    }

    /** @test */
    #[Test]
    public function an_outage_longer_than_the_window_pauses_new_sales(): void
    {
        $status = $this->keyStatus([
            Settings::KEY => 'flk_test',
            Settings::STATUS => Settings::STATUS_UNREACHABLE,
            Settings::GOOD_AT => self::NOW - 8 * 86400,
        ]);

        $this->assertFalse($status->canSellNew(), 'eight days is past the window');
    }

    /**
     * An answer is not an outage. The window is for the case where we cannot
     * say, not a way to keep selling on a subscription that ended.
     *
     * @test
     */
    #[Test]
    public function a_lapsed_key_stops_new_sales_even_inside_the_window(): void
    {
        foreach ([Settings::STATUS_CANCELED, Settings::STATUS_INVALID, Settings::STATUS_BOUND_ELSEWHERE] as $answer) {
            $status = $this->keyStatus([
                Settings::KEY => 'flk_test',
                Settings::STATUS => $answer,
                Settings::GOOD_AT => self::NOW - 60,
            ]);

            $this->assertFalse($status->canSellNew(), $answer.' is a definite answer');
        }
    }

    /**
     * An outage must not shorten the window it exists to provide, so a failed
     * check leaves the good-answer clock alone.
     *
     * @test
     */
    #[Test]
    public function a_failed_check_does_not_move_the_clock(): void
    {
        $settings = new ArraySettings([
            Settings::KEY => 'flk_test',
            Settings::GOOD_AT => self::NOW - 3 * 86400,
        ]);

        $this->statusFor($settings, null)->refresh();

        $this->assertSame((string) (self::NOW - 3 * 86400), (string) $settings->get(Settings::GOOD_AT));
        $this->assertSame(Settings::STATUS_UNREACHABLE, $settings->get(Settings::STATUS));
    }

    /** @test */
    #[Test]
    public function a_good_answer_restarts_the_window(): void
    {
        $settings = new ArraySettings([
            Settings::KEY => 'flk_test',
            Settings::GOOD_AT => self::NOW - 6 * 86400,
        ]);

        $this->statusFor($settings, ['status' => Settings::STATUS_ACTIVE, 'forum_url' => 'https://forum.example', 'last_seen_at' => null])
            ->refresh();

        $this->assertSame((string) self::NOW, (string) $settings->get(Settings::GOOD_AT));
        $this->assertSame('https://forum.example', $settings->get(Settings::BOUND_TO));
    }

    /**
     * A refusal is recorded as what it is, and does NOT stamp the good clock,
     * or a cancelled key would buy itself another week on every check.
     *
     * @test
     */
    #[Test]
    public function a_refusal_is_not_a_good_answer(): void
    {
        $settings = new ArraySettings([
            Settings::KEY => 'flk_test',
            Settings::GOOD_AT => self::NOW - 6 * 86400,
        ]);

        $this->statusFor($settings, ['status' => Settings::STATUS_CANCELED, 'forum_url' => null, 'last_seen_at' => null])
            ->refresh();

        $this->assertSame((string) (self::NOW - 6 * 86400), (string) $settings->get(Settings::GOOD_AT));
        $this->assertSame(Settings::STATUS_CANCELED, $settings->get(Settings::STATUS));
    }

    private const NOW = 1800000000;

    /**
     * @param array<string, mixed> $values
     */
    private function keyStatus(array $values): KeyStatus
    {
        return $this->statusFor(new ArraySettings($values), null);
    }

    /**
     * @param array{status: string, forum_url: ?string, last_seen_at: ?string}|null $answer
     */
    private function statusFor(ArraySettings $repository, ?array $answer): KeyStatus
    {
        $settings = new Settings($repository);

        $client = new class($settings, $answer) extends KeyClient {
            /** @param array{status: string, forum_url: ?string, last_seen_at: ?string}|null $answer */
            public function __construct(Settings $settings, private ?array $answer)
            {
                parent::__construct($settings, new Config(['url' => 'https://forum.example']));
            }

            public function status(bool $bind = false): ?array
            {
                return $this->answer;
            }
        };

        return new class($settings, $client) extends KeyStatus {
            protected function now(): int
            {
                return KeyStatusTest::now();
            }
        };
    }

    public static function now(): int
    {
        return self::NOW;
    }
}
