<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 429 Too Many Requests — slow down and retry.
 *
 * The SDK's RetryingTransport already retries 429 transparently (rate limiting
 * happens before the request is processed, so a retry is always safe) up to
 * RetryPolicy::maxRetries(), honoring the server's Retry-After. This exception
 * surfaces only once that budget is exhausted — at which point retryAfterSeconds()
 * lets the caller schedule its own back-off from the same Retry-After header.
 */
final class RateLimitException extends ApiException
{
    /**
     * How long the server asked the client to wait, in whole seconds, parsed
     * from the Retry-After header. Supports both forms defined by RFC 7231 §7.1.3:
     * delta-seconds (e.g. "5") and an HTTP-date (e.g. "Wed, 21 Oct 2026 07:28:00 GMT").
     *
     * Returns null when the header is absent or unparseable. A past HTTP-date
     * clamps to 0 (retry now).
     *
     * @param int|null $now Unix timestamp to measure an HTTP-date against (defaults to time()).
     * @return int|null
     */
    public function retryAfterSeconds($now = null)
    {
        $raw = $this->header('retry-after');
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // delta-seconds form: a non-negative integer count of seconds.
        if (ctype_digit($value)) {
            return (int) $value;
        }

        // HTTP-date form: absolute moment to retry at.
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        $now = $now === null ? time() : (int) $now;
        $delta = $timestamp - $now;

        return $delta > 0 ? $delta : 0;
    }
}
