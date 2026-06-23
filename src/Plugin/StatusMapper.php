<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * Maps Paymos webhook event types to coarse-grained actions that a plugin
 * (WooCommerce, OpenCart, etc.) should perform on its order/payment state.
 *
 * Canonical event-type list: https://paymos.io/docs/webhooks/events
 *
 * Action semantics:
 *   ACTION_IGNORE     — informational; do not mutate order state.
 *   ACTION_PROCESSING — payment is in motion (received, confirming, partial, paid).
 *                       Plugin should mark the order as "processing" / "awaiting fulfilment".
 *   ACTION_COMPLETED  — terminal success (used for withdrawals).
 *   ACTION_CANCELLED  — terminal cancellation (user cancelled, deadline expired).
 *                       Plugin should release stock and cancel the order.
 *   ACTION_FAILED     — terminal failure (final underpayment, withdrawal failure).
 *                       Plugin should mark the order as failed.
 */
final class StatusMapper
{
    public const ACTION_IGNORE = 'ignore';
    public const ACTION_PROCESSING = 'processing';
    public const ACTION_COMPLETED = 'completed';
    public const ACTION_CANCELLED = 'cancelled';
    public const ACTION_FAILED = 'failed';
    public const ACTION_AWAITING_PAYMENT = 'awaiting_payment';
    public const ACTION_CONFIRMING = 'confirming';
    public const ACTION_PAYMENT_COMPLETE = 'payment_complete';
    public const ACTION_CANCEL_ORDER = 'cancel_order';
    public const ACTION_FAIL_ORDER = 'fail_order';
    public const ACTION_MANUAL_REVIEW = 'manual_review';

    // ── Invoice event types ─────────────────────────────────────────────────

    // Server source: Paymos.Domain.Invoices.InvoiceEventTypes (exactly 8 events).
    // There is NO invoice.created / invoice.token_selected — the server never
    // emits them (the AwaitingClient->AwaitingPayment confirm is silent).
    //
    // Payment regression: a chain reorg phantomed every counted payment and the
    // invoice fell back to awaiting_payment. The order must return to "awaiting
    // payment" — a previously-confirmed payment no longer exists on-chain.
    private const EVT_INVOICE_AWAITING_PAYMENT = 'invoice.awaiting_payment';
    private const EVT_INVOICE_CONFIRMING = 'invoice.confirming';
    private const EVT_INVOICE_UNDERPAID_WAITING = 'invoice.underpaid_waiting';
    private const EVT_INVOICE_PAID = 'invoice.paid';
    private const EVT_INVOICE_PAID_OVER = 'invoice.paid_over';
    private const EVT_INVOICE_UNDERPAID = 'invoice.underpaid';
    private const EVT_INVOICE_EXPIRED = 'invoice.expired';
    private const EVT_INVOICE_CANCELLED = 'invoice.cancelled';

    // ── Withdrawal event types ──────────────────────────────────────────────

    private const EVT_WITHDRAWAL_CREATED = 'withdrawal.created';
    private const EVT_WITHDRAWAL_PROCESSING = 'withdrawal.processing';
    private const EVT_WITHDRAWAL_COMPLETED = 'withdrawal.completed';
    private const EVT_WITHDRAWAL_FAILED = 'withdrawal.failed';
    private const EVT_WITHDRAWAL_CANCELLED = 'withdrawal.cancelled';

    /**
     * Map an invoice webhook event to the action a plugin should take.
     *
     * @param string      $eventType Lowercase event type from the webhook payload.
     * @param string|null $status    Optional invoice status fallback (legacy callers).
     * @return string                One of the ACTION_* constants.
     */
    public static function paymentAction($eventType, $status = null)
    {
        $eventType = strtolower((string) $eventType);
        $status = strtolower((string) $status);

        switch ($eventType) {
            // Mid-flight signals — payment is in motion, mark order processing.
            case self::EVT_INVOICE_CONFIRMING:
            case self::EVT_INVOICE_UNDERPAID_WAITING:
            case self::EVT_INVOICE_PAID:
            case self::EVT_INVOICE_PAID_OVER:
                return self::ACTION_PROCESSING;

            // Terminal failure — buyer underpaid past the deadline.
            case self::EVT_INVOICE_UNDERPAID:
                return self::ACTION_FAILED;

            // Terminal cancellation — deadline passed or merchant/customer cancelled.
            case self::EVT_INVOICE_EXPIRED:
            case self::EVT_INVOICE_CANCELLED:
                return self::ACTION_CANCELLED;

            // Payment regression (reorg): treat as still in motion — the order
            // stays "processing" while the customer's payment is re-confirmed.
            case self::EVT_INVOICE_AWAITING_PAYMENT:
                return self::ACTION_PROCESSING;
        }

        // Legacy `status`-based fallback for callers that don't pass eventType.
        switch ($status) {
            case 'awaiting_payment':
            case 'confirming':
            case 'underpaid_waiting':
            case 'paid':
            case 'paid_over':
                return self::ACTION_PROCESSING;
            case 'underpaid':
                return self::ACTION_FAILED;
            case 'expired':
            case 'cancelled':
                return self::ACTION_CANCELLED;
        }

        return self::ACTION_IGNORE;
    }

    /**
     * More precise invoice action mapping for reusable CMS plugin toolkits.
     *
     * `paymentAction()` is kept as a coarse legacy mapper. New plugins should use
     * this method so paid invoices are distinguished from mid-flight states.
     *
     * @param string      $eventType Lowercase event type from the webhook payload.
     * @param string|null $status    Optional invoice status fallback.
     * @return string                One of the ACTION_* constants.
     */
    public static function invoiceAction($eventType, $status = null)
    {
        $eventType = strtolower((string) $eventType);
        $status = strtolower((string) $status);

        switch ($eventType) {
            // Reorg regression — a previously-counted payment vanished on-chain;
            // pull the order back to "awaiting payment".
            case self::EVT_INVOICE_AWAITING_PAYMENT:
                return self::ACTION_AWAITING_PAYMENT;
            case self::EVT_INVOICE_CONFIRMING:
                return self::ACTION_CONFIRMING;
            case self::EVT_INVOICE_UNDERPAID_WAITING:
                return self::ACTION_AWAITING_PAYMENT;
            case self::EVT_INVOICE_PAID:
            case self::EVT_INVOICE_PAID_OVER:
                return self::ACTION_PAYMENT_COMPLETE;
            case self::EVT_INVOICE_UNDERPAID:
                return self::ACTION_FAIL_ORDER;
            case self::EVT_INVOICE_EXPIRED:
            case self::EVT_INVOICE_CANCELLED:
                return self::ACTION_CANCEL_ORDER;
        }

        switch ($status) {
            case 'awaiting_payment':
                return self::ACTION_AWAITING_PAYMENT;
            case 'confirming':
                return self::ACTION_CONFIRMING;
            case 'underpaid_waiting':
                return self::ACTION_AWAITING_PAYMENT;
            case 'paid':
            case 'paid_over':
                return self::ACTION_PAYMENT_COMPLETE;
            case 'underpaid':
                return self::ACTION_FAIL_ORDER;
            case 'expired':
            case 'cancelled':
                return self::ACTION_CANCEL_ORDER;
        }

        return self::ACTION_IGNORE;
    }

    /**
     * Map a withdrawal webhook event to the action a plugin should take.
     *
     * @param string      $eventType Lowercase event type from the webhook payload.
     * @param string|null $status    Optional withdrawal status fallback (legacy callers).
     * @return string                One of the ACTION_* constants.
     */
    public static function withdrawalAction($eventType, $status = null)
    {
        $eventType = strtolower((string) $eventType);
        $status = strtolower((string) $status);

        switch ($eventType) {
            case self::EVT_WITHDRAWAL_PROCESSING:
                return self::ACTION_PROCESSING;
            case self::EVT_WITHDRAWAL_COMPLETED:
                return self::ACTION_COMPLETED;
            case self::EVT_WITHDRAWAL_FAILED:
                return self::ACTION_FAILED;
            case self::EVT_WITHDRAWAL_CANCELLED:
                return self::ACTION_CANCELLED;
            case self::EVT_WITHDRAWAL_CREATED:
                return self::ACTION_IGNORE;
        }

        // Legacy `status`-based fallback.
        switch ($status) {
            case 'processing':
                return self::ACTION_PROCESSING;
            case 'completed':
                return self::ACTION_COMPLETED;
            case 'failed':
                return self::ACTION_FAILED;
            case 'cancelled':
                return self::ACTION_CANCELLED;
        }

        return self::ACTION_IGNORE;
    }
}
