<?php

declare(strict_types=1);

namespace Paymos\Plugin;

use Paymos\Exception\NotFoundException;

/**
 * Closes an order's invoice before a plugin cuts a replacement for it.
 *
 * A plugin replaces an order's invoice — with a new external_order_id — when
 * the order amount, currency, environment or project changed, or when the old
 * invoice can no longer be paid (InvoiceRenewal). Left open on the server, the
 * old invoice stays payable beside the new one, and a buyer could pay both.
 *
 * close() cancels it through the public endpoint first. Server contract
 * (src/): POST /v1/invoices/{id}/cancel moves only an awaiting_client invoice
 * to cancelled (Invoice.Cancel); a cancelled one answers 200 again; every
 * other status answers 409 invoice_cannot_be_cancelled — a network picked,
 * funds confirming or a part paid stays payable, and final statuses never
 * change. When the cancel does not come back "cancelled", the invoice is read
 * back and only a status that ended unpaid (expired, cancelled, underpaid)
 * lets the replacement through.
 *
 * Everything else is fail-closed: paid, still open, a failed read, and 404.
 * The server answers 404 alike for a missing invoice and for one the caller's
 * credentials may not read — another merchant's after a reconnect to another
 * account, or another environment's (AuthorizationBehavior maps an ownership
 * violation to not_found on purpose). A plugin only holds ids the server once
 * issued, so a 404 never proves the invoice cannot be paid.
 *
 * When close() does not report isClosed(), the plugin must not issue a new
 * invoice and should route the order to manual review.
 */
final class InvoiceReplacement
{
    /** Stored on the cancelled invoice (the server requires 1..500 characters). */
    public const DEFAULT_REASON = 'Cancelled by the store before issuing a new invoice for this order.';

    /** @var object|callable */
    private $client;

    /**
     * @param object|callable $client A Paymos\Client (or anything exposing invoices()->cancel() and
     *                                ->get()), or a callable returning one. The callable is called only
     *                                when a request is needed; if it throws — the old invoice's
     *                                environment is no longer configured — the invoice is not closed.
     */
    public function __construct($client)
    {
        $this->client = $client;
    }

    /**
     * @param string $invoiceId      The old invoice's id, as the server issued it.
     * @param string $recordedStatus The status the plugin last recorded for it, if any. Pass only a
     *                               status the server reported (a verified webhook, a read, a create
     *                               response): a final one is trusted without a call, since nothing
     *                               leaves a final status on the server (Invoice.IsTerminal).
     * @param string $reason         Stored on the cancelled invoice.
     * @return InvoiceReplacementResult
     */
    public function close($invoiceId, $recordedStatus = '', $reason = self::DEFAULT_REASON)
    {
        $invoiceId = trim((string) $invoiceId);
        if ($invoiceId === '') {
            return InvoiceReplacementResult::blocked('', '', InvoiceReplacementResult::REASON_MISSING_ID);
        }

        $recorded = strtolower(trim((string) $recordedStatus));
        switch (StatusMapper::invoiceAction('', $recorded)) {
            case StatusMapper::ACTION_CANCEL_ORDER:
            case StatusMapper::ACTION_FAIL_ORDER:
                return InvoiceReplacementResult::closed($invoiceId, $recorded, InvoiceReplacementResult::REASON_ENDED_UNPAID);
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return InvoiceReplacementResult::blocked($invoiceId, $recorded, InvoiceReplacementResult::REASON_PAID);
        }

        $cancelFailure = '';
        try {
            $cancelled = $this->client()->invoices()->cancel($invoiceId, (string) $reason);
            if (self::status($cancelled) === 'cancelled') {
                return InvoiceReplacementResult::closed($invoiceId, 'cancelled', InvoiceReplacementResult::REASON_CANCELLED);
            }
        } catch (NotFoundException $e) {
            return InvoiceReplacementResult::blocked($invoiceId, '', InvoiceReplacementResult::REASON_NOT_FOUND, $e->getMessage());
        } catch (\Exception $e) {
            // 409 invoice_cannot_be_cancelled for anything past awaiting_client,
            // or a failed call: the read decides.
            $cancelFailure = $e->getMessage();
        }

        try {
            $invoice = $this->client()->invoices()->get($invoiceId);
        } catch (NotFoundException $e) {
            return InvoiceReplacementResult::blocked($invoiceId, '', InvoiceReplacementResult::REASON_NOT_FOUND, $e->getMessage());
        } catch (\Exception $e) {
            $detail = $cancelFailure !== '' ? $cancelFailure . '; ' . $e->getMessage() : $e->getMessage();
            return InvoiceReplacementResult::blocked($invoiceId, '', InvoiceReplacementResult::REASON_UNREACHABLE, $detail);
        }

        $status = self::status($invoice);
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CANCEL_ORDER:
            case StatusMapper::ACTION_FAIL_ORDER:
                return InvoiceReplacementResult::closed($invoiceId, $status, InvoiceReplacementResult::REASON_ENDED_UNPAID);
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return InvoiceReplacementResult::blocked($invoiceId, $status, InvoiceReplacementResult::REASON_PAID);
        }

        // awaiting_client (the cancel failed), awaiting_payment, confirming,
        // underpaid_waiting, or a status this SDK does not know: payable.
        return InvoiceReplacementResult::blocked($invoiceId, $status, InvoiceReplacementResult::REASON_OPEN, $cancelFailure);
    }

    /**
     * @return object
     */
    private function client()
    {
        if (!(is_object($this->client) && method_exists($this->client, 'invoices')) && is_callable($this->client)) {
            $this->client = call_user_func($this->client);
        }
        if (!is_object($this->client) || !method_exists($this->client, 'invoices')) {
            throw new \UnexpectedValueException('InvoiceReplacement needs a Paymos client.');
        }

        return $this->client;
    }

    /**
     * @param mixed $invoice
     * @return string
     */
    private static function status($invoice)
    {
        return is_array($invoice) && isset($invoice['status']) && is_scalar($invoice['status'])
            ? strtolower(trim((string) $invoice['status']))
            : '';
    }
}
