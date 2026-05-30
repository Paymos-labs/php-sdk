<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 409 Conflict — request collided with current state.
 * Examples: insufficient_balance, withdrawal_quota_exceeded,
 * concurrency_conflict.
 */
final class ConflictException extends ApiException
{
}
