<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 429 Too Many Requests — slow down and retry. The server emits a
 * Retry-After header; the SDK's RetryingTransport already retries
 * 429 with exponential backoff up to RetryPolicy::maxRetries().
 */
final class RateLimitException extends ApiException
{
}
