<?php

declare(strict_types=1);

use Paymos\Plugin\StatusMapper;

function test_status_mapper_invoice_processing_events()
{
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('invoice.confirming'),       'invoice.confirming → processing');
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('invoice.underpaid_waiting'),'invoice.underpaid_waiting → processing');
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('invoice.paid'),             'invoice.paid → processing');
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('invoice.paid_over'),        'invoice.paid_over → processing (overpay still completes order)');
}

function test_status_mapper_invoice_terminal_events()
{
    assertSameValue(StatusMapper::ACTION_FAILED,    StatusMapper::paymentAction('invoice.underpaid'),  'invoice.underpaid → failed (final underpayment)');
    assertSameValue(StatusMapper::ACTION_CANCELLED, StatusMapper::paymentAction('invoice.expired'),    'invoice.expired → cancelled');
    assertSameValue(StatusMapper::ACTION_CANCELLED, StatusMapper::paymentAction('invoice.cancelled'),  'invoice.cancelled → cancelled');
}

function test_status_mapper_invoice_reorg_regression_event()
{
    // invoice.awaiting_payment is emitted ONLY on a reorg regression (a counted
    // payment vanished on-chain). The coarse mapper keeps the order in motion.
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('invoice.awaiting_payment'),
        'invoice.awaiting_payment (reorg) → processing');
}

function test_status_mapper_invoice_unknown_event()
{
    assertSameValue(StatusMapper::ACTION_IGNORE, StatusMapper::paymentAction('invoice.unknown_future'), 'unknown event → ignore');
    assertSameValue(StatusMapper::ACTION_IGNORE, StatusMapper::paymentAction(''),                       'empty event → ignore');
}

function test_status_mapper_invoice_legacy_status_fallback()
{
    // Legacy callers that pass status instead of eventType.
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('', 'paid'),          'fallback: status=paid → processing');
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('', 'paid_over'),     'fallback: status=paid_over → processing');
    assertSameValue(StatusMapper::ACTION_FAILED,     StatusMapper::paymentAction('', 'underpaid'),     'fallback: status=underpaid → failed');
    assertSameValue(StatusMapper::ACTION_CANCELLED,  StatusMapper::paymentAction('', 'expired'),       'fallback: status=expired → cancelled');
    assertSameValue(StatusMapper::ACTION_CANCELLED,  StatusMapper::paymentAction('', 'cancelled'),     'fallback: status=cancelled → cancelled');
}

function test_status_mapper_invoice_event_takes_precedence_over_status()
{
    // When both are supplied and they would produce different actions, the event wins.
    assertSameValue(StatusMapper::ACTION_CANCELLED, StatusMapper::paymentAction('invoice.cancelled', 'paid'),
        'event=cancelled overrides status=paid');
}

function test_status_mapper_withdrawal_events()
{
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::withdrawalAction('withdrawal.processing'), 'withdrawal.processing → processing');
    assertSameValue(StatusMapper::ACTION_COMPLETED,  StatusMapper::withdrawalAction('withdrawal.completed'),  'withdrawal.completed → completed');
    assertSameValue(StatusMapper::ACTION_CANCELLED,  StatusMapper::withdrawalAction('withdrawal.cancelled'),  'withdrawal.cancelled → cancelled');
    assertSameValue(StatusMapper::ACTION_FAILED,     StatusMapper::withdrawalAction('withdrawal.failed'),     'withdrawal.failed → failed');
    assertSameValue(StatusMapper::ACTION_IGNORE,     StatusMapper::withdrawalAction('withdrawal.created'),    'withdrawal.created → ignore (informational)');
}

function test_status_mapper_withdrawal_unknown_and_legacy()
{
    assertSameValue(StatusMapper::ACTION_IGNORE, StatusMapper::withdrawalAction('withdrawal.unknown_future'), 'unknown withdrawal event → ignore');
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::withdrawalAction('', 'processing'),         'fallback: withdrawal status=processing');
    assertSameValue(StatusMapper::ACTION_COMPLETED,  StatusMapper::withdrawalAction('', 'completed'),          'fallback: withdrawal status=completed');
    assertSameValue(StatusMapper::ACTION_FAILED,     StatusMapper::withdrawalAction('', 'failed'),             'fallback: withdrawal status=failed');
    assertSameValue(StatusMapper::ACTION_CANCELLED,  StatusMapper::withdrawalAction('', 'cancelled'),          'fallback: withdrawal status=cancelled');
}

function test_status_mapper_case_insensitive()
{
    assertSameValue(StatusMapper::ACTION_PROCESSING, StatusMapper::paymentAction('Invoice.Paid'),     'event type comparison is case-insensitive');
    assertSameValue(StatusMapper::ACTION_CANCELLED,  StatusMapper::paymentAction('INVOICE.EXPIRED'),  'event type comparison is case-insensitive (upper)');
}

function test_status_mapper_precise_invoice_actions_for_plugin_toolkit()
{
    assertSameValue(StatusMapper::ACTION_CONFIRMING, StatusMapper::invoiceAction('invoice.confirming'), 'confirming invoice needs confirming action.');
    assertSameValue(StatusMapper::ACTION_AWAITING_PAYMENT, StatusMapper::invoiceAction('invoice.underpaid_waiting'), 'underpaid waiting invoice stays awaiting payment.');
    assertSameValue(StatusMapper::ACTION_PAYMENT_COMPLETE, StatusMapper::invoiceAction('invoice.paid'), 'paid invoice completes payment.');
    assertSameValue(StatusMapper::ACTION_PAYMENT_COMPLETE, StatusMapper::invoiceAction('invoice.paid_over'), 'overpaid invoice completes payment.');
    assertSameValue(StatusMapper::ACTION_FAIL_ORDER, StatusMapper::invoiceAction('invoice.underpaid'), 'final underpayment fails order.');
    assertSameValue(StatusMapper::ACTION_CANCEL_ORDER, StatusMapper::invoiceAction('invoice.expired'), 'expired invoice cancels order.');
    assertSameValue(StatusMapper::ACTION_AWAITING_PAYMENT, StatusMapper::invoiceAction('invoice.awaiting_payment'), 'reorg regression returns order to awaiting payment.');
    assertSameValue(StatusMapper::ACTION_IGNORE, StatusMapper::invoiceAction('invoice.unknown_future'), 'unknown future event is ignored.');
}

function test_status_mapper_precise_invoice_action_uses_status_fallback()
{
    assertSameValue(StatusMapper::ACTION_PAYMENT_COMPLETE, StatusMapper::invoiceAction('', 'paid'), 'status fallback paid completes payment.');
    assertSameValue(StatusMapper::ACTION_CANCEL_ORDER, StatusMapper::invoiceAction('', 'cancelled'), 'status fallback cancelled cancels order.');
    assertSameValue(StatusMapper::ACTION_IGNORE, StatusMapper::invoiceAction('', 'unknown'), 'unknown status fallback is ignored.');
}
