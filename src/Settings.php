<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Typed access to Flock's settings, so nothing else parses raw setting strings.
 *
 * Two secrets live here and neither may ever reach the frontend: the Flock key,
 * which is ours, and the owner's Stripe restricted key, which is theirs and
 * worse to leak. Both are stripped from the admin payload by
 * [[HideKeysFromAdmin]]; nothing here is serialized to the forum.
 */
final class Settings
{
    public const PREFIX = 'linkrobins-flock.';

    /** The one field an owner pastes from linkrobins.com. */
    public const KEY = self::PREFIX.'key';

    /**
     * The owner's Stripe RESTRICTED key. Never their secret key: the spec's
     * scope list is what this is allowed to do, and nothing wider.
     */
    public const STRIPE_KEY = self::PREFIX.'stripe_key';

    /** Minted by the extension when the Stripe key is saved, not by hand. */
    public const WEBHOOK_SECRET = self::PREFIX.'webhook_secret';

    /** What linkrobins.com said last time we asked, and when. */
    public const STATUS = self::PREFIX.'status';
    public const CHECKED_AT = self::PREFIX.'checked_at';

    /**
     * When we last got a GOOD answer, which is the only thing standing between
     * an outage at our end and an owner who cannot sell memberships. See
     * [[KeyStatus]] for what it buys.
     */
    public const GOOD_AT = self::PREFIX.'good_at';

    /** Which forum host the key is bound to, as reported by the service. */
    public const BOUND_TO = self::PREFIX.'bound_to';

    /** How long a past_due subscription keeps its group, in days. */
    public const GRACE_DAYS = self::PREFIX.'grace_days';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_INVALID = 'invalid_key';
    public const STATUS_BOUND_ELSEWHERE = 'bound_elsewhere';

    /** Not a status the service returns: it means we could not ask. */
    public const STATUS_UNREACHABLE = 'unreachable';

    /** Nothing pasted yet. */
    public const STATUS_UNCONFIGURED = 'unconfigured';

    public const DEFAULT_GRACE_DAYS = 7;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function key(): string
    {
        return trim((string) $this->settings->get(self::KEY, ''));
    }

    public function stripeKey(): string
    {
        return trim((string) $this->settings->get(self::STRIPE_KEY, ''));
    }

    public function webhookSecret(): string
    {
        return (string) $this->settings->get(self::WEBHOOK_SECRET, '');
    }

    public function status(): string
    {
        $status = (string) $this->settings->get(self::STATUS, '');

        return $status !== '' ? $status : self::STATUS_UNCONFIGURED;
    }

    public function boundTo(): string
    {
        return (string) $this->settings->get(self::BOUND_TO, '');
    }

    public function checkedAt(): ?int
    {
        return $this->timestamp(self::CHECKED_AT);
    }

    public function goodAt(): ?int
    {
        return $this->timestamp(self::GOOD_AT);
    }

    /**
     * Days a past_due subscription keeps its group before the group is taken
     * back. Bounded so a typo cannot mean "forever" or "immediately".
     */
    public function graceDays(): int
    {
        $days = (int) $this->settings->get(self::GRACE_DAYS, self::DEFAULT_GRACE_DAYS);

        return max(0, min(90, $days));
    }

    /**
     * Record what the service said. A good answer also stamps GOOD_AT, which is
     * the clock the fail-open window runs on.
     */
    public function recordStatus(string $status, ?string $boundTo, bool $good, int $now): void
    {
        $this->settings->set(self::STATUS, $status);
        $this->settings->set(self::CHECKED_AT, $now);

        if ($boundTo !== null) {
            $this->settings->set(self::BOUND_TO, $boundTo);
        }

        if ($good) {
            $this->settings->set(self::GOOD_AT, $now);
        }
    }

    /** Everything the service told us, dropped. The key itself is not ours to clear. */
    public function forgetStatus(): void
    {
        foreach ([self::STATUS, self::CHECKED_AT, self::GOOD_AT, self::BOUND_TO] as $setting) {
            $this->settings->delete($setting);
        }
    }

    private function timestamp(string $key): ?int
    {
        $value = $this->settings->get($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
