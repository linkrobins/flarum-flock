<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

namespace LinkRobins\Flock;

/**
 * What the key allows, and the one rule that outranks everything else here.
 *
 * A member's paid access must never depend on linkrobins.com being up. So this
 * class can answer exactly one question that ever blocks anything:
 * canSellNew(). Nothing in this extension may ask it before granting, keeping
 * or removing a group. Somebody paid the forum's owner; a problem between the
 * owner and us is not their problem, and it is not their money back.
 *
 * The window: a good answer is remembered for GRACE_DAYS, so an outage at our
 * end is invisible for a week. Past that, and on a definite refusal, new
 * checkouts stop and every existing membership carries on untouched.
 */
class KeyStatus
{
    /** How long a good answer keeps selling alive with no further contact. */
    public const GRACE_DAYS = 7;

    public function __construct(
        protected Settings $settings,
        protected KeyClient $client
    ) {
    }

    /**
     * The ONLY gate in this extension. True means the owner may open new
     * checkouts. False never means anybody loses anything they have.
     */
    public function canSellNew(): bool
    {
        if ($this->settings->key() === '') {
            return false;
        }

        $status = $this->settings->status();

        if ($status === Settings::STATUS_ACTIVE) {
            return true;
        }

        // The distinction the grace window exists for. "We could not ask" is
        // our problem and the owner should not feel it for a week. "We asked
        // and the answer was no" is an answer, and a lapsed or misplaced key
        // stops new sales now: the window is for an outage at our end, not a
        // way to keep selling on a subscription that ended.
        //
        // Either way this decides new checkouts and nothing else. No branch
        // here can cost a member the group they paid the owner for.
        if ($status === Settings::STATUS_UNREACHABLE || $status === Settings::STATUS_UNCONFIGURED) {
            return $this->withinGrace();
        }

        return false;
    }

    /** Whether a good answer is recent enough to still count. */
    public function withinGrace(): bool
    {
        $goodAt = $this->settings->goodAt();

        if ($goodAt === null) {
            return false;
        }

        return ($this->now() - $goodAt) <= self::GRACE_DAYS * 86400;
    }

    /**
     * Ask the service and remember what it said.
     *
     * @param bool $bind True only on an admin's save of the key.
     * @return string the status now recorded, including our own local ones
     */
    public function refresh(bool $bind = false): string
    {
        if ($this->settings->key() === '') {
            $this->settings->forgetStatus();

            return Settings::STATUS_UNCONFIGURED;
        }

        $answer = $this->client->status($bind);
        $now = $this->now();

        if ($answer === null) {
            // Could not ask. Record that we tried, and deliberately do NOT
            // touch the good-answer clock: an outage must not shorten the
            // window it exists to provide.
            $this->settings->recordStatus(Settings::STATUS_UNREACHABLE, null, false, $now);

            return Settings::STATUS_UNREACHABLE;
        }

        $this->settings->recordStatus(
            $answer['status'],
            $answer['forum_url'],
            $answer['status'] === Settings::STATUS_ACTIVE,
            $now
        );

        return $answer['status'];
    }

    /**
     * What the settings banner should say. Distinct from canSellNew() on
     * purpose: the banner tells the owner the truth about their key, while
     * selling keeps working through a wobble.
     */
    public function bannerStatus(): string
    {
        $status = $this->settings->status();

        if ($status === Settings::STATUS_UNREACHABLE && $this->withinGrace()) {
            // Honest, and not alarming: nothing is broken for anybody yet.
            return Settings::STATUS_UNREACHABLE;
        }

        return $status;
    }

    protected function now(): int
    {
        return time();
    }
}
