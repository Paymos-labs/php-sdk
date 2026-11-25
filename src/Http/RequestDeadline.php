<?php

declare(strict_types=1);

namespace Paymos\Http;

/**
 * Shared wall/monotonic deadline budget across one or more synchronous SDK HTTP calls
 * (e.g. a CMS webhook callback's reverse verification and recovery reads, BUG-1348).
 *
 * Keeps cumulative request I/O, retries, and backoff strictly bounded so callbacks finish
 * with margin for durable persistence and HTTP response inside the webhook delivery timeout.
 */
final class RequestDeadline
{
    /** Minimum remaining I/O margin required to attempt another retry after sleeping. */
    const MIN_RETRY_IO_MICROSECONDS = 250000;

    /** @var float */
    private $deadlineSeconds;

    /** @var callable */
    private $clock;

    /** @var callable */
    private $sleeper;

    /**
     * @param float         $deadlineSeconds Absolute timestamp in seconds from $clock.
     * @param callable|null $clock           Returns current time in float seconds (defaults to microtime(true)).
     * @param callable|null $sleeper         Sleeps for given integer microseconds (defaults to usleep).
     */
    public function __construct($deadlineSeconds, $clock = null, $sleeper = null)
    {
        $this->deadlineSeconds = (float) $deadlineSeconds;
        $this->clock = $clock !== null ? $clock : static function () {
            return microtime(true);
        };
        $this->sleeper = $sleeper !== null ? $sleeper : static function ($microseconds) {
            usleep((int) $microseconds);
        };
    }

    /**
     * @param float         $budgetSeconds
     * @param callable|null $clock
     * @param callable|null $sleeper
     * @return self
     */
    public static function fromNow($budgetSeconds, $clock = null, $sleeper = null)
    {
        $budget = (float) $budgetSeconds;
        if ($budget <= 0.0) {
            throw new \InvalidArgumentException('budgetSeconds must be greater than zero.');
        }

        $resolvedClock = $clock !== null ? $clock : static function () {
            return microtime(true);
        };

        return new self(((float) call_user_func($resolvedClock)) + $budget, $resolvedClock, $sleeper);
    }

    /** @return float Remaining seconds (>= 0.0). */
    public function remainingSeconds()
    {
        $remaining = $this->deadlineSeconds - (float) call_user_func($this->clock);
        return $remaining > 0.0 ? $remaining : 0.0;
    }

    /** @return int Remaining microseconds (>= 0). */
    public function remainingMicroseconds()
    {
        return (int) floor($this->remainingSeconds() * 1000000.0);
    }

    /** @return bool */
    public function isExhausted()
    {
        return $this->remainingSeconds() <= 0.0;
    }

    /**
     * Effective timeout for one physical attempt, capped by remaining budget.
     * Returns 0.0 when no budget remains.
     *
     * @param int|float $requestedTimeoutSeconds
     * @return float
     */
    public function attemptTimeoutSeconds($requestedTimeoutSeconds)
    {
        $remaining = $this->remainingSeconds();
        if ($remaining <= 0.0) {
            return 0.0;
        }

        $requested = (float) $requestedTimeoutSeconds;
        if ($requested <= 0.0) {
            return $remaining;
        }

        return min($requested, $remaining);
    }

    /**
     * Whether sleeping for $delayMicroseconds still leaves enough budget for a retry attempt.
     *
     * @param int $delayMicroseconds
     * @return bool
     */
    public function canSleepAndRetry($delayMicroseconds)
    {
        $delay = max(0, (int) $delayMicroseconds);
        return ($this->remainingMicroseconds() - $delay) >= self::MIN_RETRY_IO_MICROSECONDS;
    }

    /**
     * Sleep using the configured sleeper (supports deterministic clock fixtures in tests).
     *
     * @param int $microseconds
     * @return void
     */
    public function sleepMicroseconds($microseconds)
    {
        $delay = max(0, (int) $microseconds);
        if ($delay > 0) {
            call_user_func($this->sleeper, $delay);
        }
    }
}
