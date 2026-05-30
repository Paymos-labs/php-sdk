<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 410 Gone — the resource is in a terminal state and the requested action
 * is no longer permitted (e.g. cancelling an invoice that is already
 * Paid / Expired / Cancelled).
 */
final class GoneException extends ApiException
{
}
