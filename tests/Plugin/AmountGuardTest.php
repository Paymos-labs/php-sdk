<?php

declare(strict_types=1);

use Paymos\Plugin\AmountGuard;

function test_amount_guard_allows_matching_snapshot_current_order_and_event_order()
{
    assertTrueValue(
        AmountGuard::isSafeToComplete('100.00', 'usd', '100', 'USD', '100.0', 'usd'),
        'matching amounts and currencies must be safe even with harmless decimal/case differences.'
    );
}

function test_amount_guard_treats_leading_zeros_as_equal()
{
    // "50.00" vs "050.00" is the same money — leading zeros are insignificant.
    // The earlier normalizer trimmed only trailing zeros, so this falsely
    // mismatched and pushed a paid order into manual review.
    assertTrueValue(
        AmountGuard::isSafeToComplete('50.00', 'USD', '050.00', 'usd', '050.0', 'USD'),
        'leading zeros must not cause a false amount mismatch.'
    );
    assertTrueValue(
        AmountGuard::isSafeToComplete('0050', 'EUR', '50', 'EUR'),
        'leading zeros on a whole-number amount must compare equal.'
    );
}

function test_amount_guard_still_blocks_genuinely_different_amounts()
{
    assertFalseValue(
        AmountGuard::isSafeToComplete('50.00', 'USD', '50.01', 'USD', '50.00', 'USD'),
        'a one-cent difference must still block automatic completion.'
    );
}

function test_amount_guard_blocks_changed_current_order_amount()
{
    assertFalseValue(
        AmountGuard::isSafeToComplete('100.00', 'USD', '120.00', 'USD', '100.00', 'USD'),
        'changed current order amount must block automatic completion.'
    );
}

function test_amount_guard_blocks_event_order_currency_mismatch()
{
    assertFalseValue(
        AmountGuard::isSafeToComplete('100.00', 'USD', '100.00', 'USD', '100.00', 'EUR'),
        'webhook order currency mismatch must block automatic completion.'
    );
}

function test_amount_guard_formats_mismatch_summary()
{
    $summary = AmountGuard::mismatchSummary('100.00', 'USD', '120.00', 'USD', '100.00', 'USD');

    assertTrueValue(strpos($summary, 'Invoice snapshot: 100.00 USD') !== false, 'summary must include invoice snapshot.');
    assertTrueValue(strpos($summary, 'Current order: 120.00 USD') !== false, 'summary must include current order.');
    assertTrueValue(strpos($summary, 'Webhook order: 100.00 USD') !== false, 'summary must include webhook order.');
}
