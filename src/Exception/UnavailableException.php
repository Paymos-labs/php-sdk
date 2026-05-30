<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 503 Service Unavailable — transient infrastructure or upstream issue
 * (exchange-rate provider down, outbound frozen, etc). RetryingTransport
 * will retry up to RetryPolicy::maxRetries() before this surfaces.
 */
final class UnavailableException extends ApiException
{
}
