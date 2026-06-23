<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 503 Service Unavailable — transient infrastructure or upstream issue
 * (exchange-rate provider down, outbound frozen, etc).
 *
 * RetryingTransport retries a 503 only for idempotent methods (GET/HEAD/OPTIONS),
 * up to RetryPolicy::maxRetries() and honoring Retry-After. A 503 on a
 * non-idempotent POST (create/cancel/simulate) is NOT retried — the request may
 * have already taken effect server-side — so it surfaces immediately.
 */
final class UnavailableException extends ApiException
{
}
