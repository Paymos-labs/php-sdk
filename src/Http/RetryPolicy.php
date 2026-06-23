<?php

declare(strict_types=1);

namespace Paymos\Http;

final class RetryPolicy
{
    /** @var int */
    private $maxRetries;

    /** @var int */
    private $baseDelayMilliseconds;

    public function __construct($maxRetries, $baseDelayMilliseconds)
    {
        $this->maxRetries = max(0, (int) $maxRetries);
        $this->baseDelayMilliseconds = max(0, (int) $baseDelayMilliseconds);
    }

    public static function default()
    {
        return new self(2, 150);
    }

    public function maxRetries()
    {
        return $this->maxRetries;
    }

    public function delayMicroseconds($attempt)
    {
        if ($this->baseDelayMilliseconds <= 0) {
            return 0;
        }

        return (int) ($this->baseDelayMilliseconds * 1000 * pow(2, max(0, (int) $attempt - 1)));
    }

    /**
     * Whether a status code is retryable in principle. 429 (rate limited) and
     * 5xx (server-side) — both indicate the request did not produce a definitive
     * result we should keep.
     */
    public function shouldRetry($statusCode)
    {
        $statusCode = (int) $statusCode;

        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Whether it is SAFE to retry this method+status combination.
     *
     * 429 is always safe: rate limiting happens before the request is processed,
     * so no side effect occurred. For 5xx we only retry idempotent methods
     * (GET/HEAD/OPTIONS) — a non-idempotent POST (create/cancel/simulate) may have
     * already taken effect server-side, so a blind retry could double-act.
     * Invoice creation is additionally protected by external_order_id idempotency,
     * but cancel/simulate are not, so we stay conservative.
     */
    public function shouldRetryMethod($method, $statusCode)
    {
        if (!$this->shouldRetry($statusCode)) {
            return false;
        }

        if ((int) $statusCode === 429) {
            return true;
        }

        $method = strtoupper(trim((string) $method));

        return $method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS';
    }

    /**
     * Parse a Retry-After header value into microseconds, or null if absent/unparseable.
     * Supports both delta-seconds (e.g. "5") and an HTTP-date (RFC 7231).
     */
    public function retryAfterMicroseconds($retryAfterHeader, $now = null)
    {
        if ($retryAfterHeader === null) {
            return null;
        }

        $value = trim((string) $retryAfterHeader);
        if ($value === '') {
            return null;
        }

        // delta-seconds form.
        if (ctype_digit($value)) {
            return (int) $value * 1000000;
        }

        // HTTP-date form.
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $now = $now === null ? time() : (int) $now;
        $deltaSeconds = $timestamp - $now;

        return $deltaSeconds > 0 ? $deltaSeconds * 1000000 : 0;
    }
}
