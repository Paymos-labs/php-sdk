<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * Whether an order needs a NEW Paymos invoice instead of the one already cut.
 *
 * A plugin reuses the invoice it cut for an order (same external_order_id)
 * rather than minting one per visit. That is only right while the invoice can
 * still be paid. The server answers a repeated external_order_id with the
 * existing invoice whatever became of it: a customer who returns to a WHMCS
 * invoice days later, or pays again for a failed WooCommerce order, would
 * otherwise be sent to a checkout that says "expired".
 *
 * The deadline is not fixed at creation. expires_at starts as the window to
 * pick a network (InvoiceOptions.PaymentTtl, 30 minutes by default), and
 * confirming a network moves it to now + PaymentTtl (Invoice.ConfirmPayment)
 * WITHOUT a webhook. A copy of expires_at a plugin stored at creation is stale
 * from that moment: on a plugin's own snapshot, a passed deadline is only a
 * reason to read the invoice back from the server. Only the server's answer
 * may replace an invoice — a second invoice next to a payable one invites a
 * second payment.
 *
 * Renewal is required when the invoice ended without being paid in full
 * (expired, cancelled, underpaid) or when nobody started it and its deadline
 * passed by more than CLOCK_SKEW_SECONDS. A PAID invoice is final too, but is
 * never renewed: the payment already happened. A network picked, funds
 * confirming or a part payment in (awaiting_payment, confirming,
 * underpaid_waiting) is never renewed either: only the server closes those.
 *
 * Accepts the API invoice (`status`, `is_final`, `expires_at`) or a plugin's own
 * snapshot row carrying a `status` and, optionally, an `expires_at`.
 */
final class InvoiceRenewal
{
    /**
     * How far the store's clock may differ from the server's. The deadline is
     * the server's; an unstarted invoice whose deadline passed by less than
     * this may still be confirmed there. Same allowance as the webhook
     * timestamp tolerance (WebhookVerifier), which already requires the two
     * clocks to agree this closely.
     */
    public const CLOCK_SKEW_SECONDS = 300;

    /**
     * @param array<string, mixed> $invoice
     * @param int|null             $now Unix seconds; defaults to time().
     * @return bool
     */
    public static function isRequired(array $invoice, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $status = isset($invoice['status']) && is_scalar($invoice['status'])
            ? strtolower(trim((string) $invoice['status']))
            : '';

        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return false;
            case StatusMapper::ACTION_FAIL_ORDER:
            case StatusMapper::ACTION_CANCEL_ORDER:
                return true;
            case StatusMapper::ACTION_AWAITING_PAYMENT:
            case StatusMapper::ACTION_CONFIRMING:
                // A buyer picked a token (awaiting_payment and later): funds may be
                // on their way, so only the server may close this invoice.
                return false;
        }

        if ($status === '' && isset($invoice['is_final']) && $invoice['is_final'] === true) {
            return true;
        }

        // awaiting_client, or a placeholder a plugin stored before the first
        // webhook. Past expires_at the server refuses confirm-payment
        // (Invoice.ConfirmPayment → InvoiceExpiredError) even before its expiry
        // job flips the status to expired — by the server's clock, so the
        // store's clock must be past it by more than the skew allowance.
        $expiresAt = isset($invoice['expires_at']) && is_numeric($invoice['expires_at'])
            ? (int) $invoice['expires_at']
            : 0;

        return $expiresAt > 0 && $expiresAt + self::CLOCK_SKEW_SECONDS <= $now;
    }
}
