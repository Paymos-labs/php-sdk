<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * The webhook event is locked by another delivery that has not finished yet.
 *
 * Not a duplicate: nothing has been applied. The server times a delivery out
 * after 10 seconds and retries, while the first attempt may still be running
 * (a slow reverse-verify call) — and may yet fail and release its lock.
 * Answering 2xx here marks the event delivered and loses it; answer non-2xx
 * (409) so the server tries again later.
 */
final class EventInProgressException extends \RuntimeException
{
}
