<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class RefundStates
{
    public const FULL = 'full';
    public const OVERPAYMENT = 'overpayment';

    public const NOT_SUBMITTED = 'not_submitted';
    public const UNKNOWN = 'unknown';
    public const CREATED = 'created';
    public const PROCESSING = 'processing';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const REVIEW = 'review';

    public const PROJECTION_NONE = 'none';
    public const PROJECTION_PENDING = 'pending';
    public const PROJECTION_APPLIED = 'applied';
    public const NATIVE_REVIEW = 'native_review';

    public const NONE = 'none';
    public const SHOW_PENDING = 'show_pending';
    public const APPLY_COMPLETED = 'apply_completed';
    public const SHOW_FAILURE = 'show_failure';
    public const SHOW_CANCELLED = 'show_cancelled';
    public const SHOW_REVIEW = 'show_review';
    public const RESUME_RECOVERY = 'resume_recovery';

    public const EFFECT_PENDING = 'pending';
    public const EFFECT_STARTED = 'started';
    public const EFFECT_DELIVERED = 'delivered';
    public const EFFECT_FAILED_NO_EFFECT = 'failed_no_effect';
    public const EFFECT_UNKNOWN = 'unknown';
    public const EFFECT_SKIPPED = 'skipped';

    public static function cashState(string $status): string
    {
        switch ($status) {
            case 'created': return self::CREATED;
            case 'signed':
            case 'pending_review':
            case 'cancelling': return self::PROCESSING;
            case 'completed': return self::COMPLETED;
            case 'failed': return self::FAILED;
            case 'cancelled': return self::CANCELLED;
            default: return self::REVIEW;
        }
    }
}
