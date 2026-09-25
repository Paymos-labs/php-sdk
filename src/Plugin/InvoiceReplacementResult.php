<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * What InvoiceReplacement::close() learnt about the invoice a plugin wants to
 * replace. Only isClosed() lets a replacement through.
 */
final class InvoiceReplacementResult
{
    /** The plugin cancelled it (or it already was cancelled). */
    public const REASON_CANCELLED = 'cancelled';

    /** The server says it ended without a full payment: expired, cancelled, underpaid. */
    public const REASON_ENDED_UNPAID = 'ended_unpaid';

    /** Paid in full. A second invoice would charge the buyer twice. */
    public const REASON_PAID = 'paid';

    /** Still payable on the server: awaiting_client, awaiting_payment, confirming, underpaid_waiting. */
    public const REASON_OPEN = 'open';

    /** 404 — missing, another merchant's, or another environment's; the server does not say which. */
    public const REASON_NOT_FOUND = 'not_found';

    /** Neither the cancel nor the read answered. */
    public const REASON_UNREACHABLE = 'unreachable';

    /** The plugin has no id for the invoice it wants to replace. */
    public const REASON_MISSING_ID = 'missing_invoice_id';

    /** @var bool */
    private $closed;

    /** @var string */
    private $invoiceId;

    /** @var string */
    private $status;

    /** @var string */
    private $reason;

    /** @var string */
    private $detail;

    /**
     * @param bool   $closed
     * @param string $invoiceId
     * @param string $status
     * @param string $reason
     * @param string $detail
     */
    private function __construct($closed, $invoiceId, $status, $reason, $detail)
    {
        $this->closed = (bool) $closed;
        $this->invoiceId = (string) $invoiceId;
        $this->status = (string) $status;
        $this->reason = (string) $reason;
        $this->detail = (string) $detail;
    }

    /**
     * @return self
     */
    public static function closed($invoiceId, $status, $reason)
    {
        return new self(true, $invoiceId, $status, $reason, '');
    }

    /**
     * @return self
     */
    public static function blocked($invoiceId, $status, $reason, $detail = '')
    {
        return new self(false, $invoiceId, $status, $reason, $detail);
    }

    /**
     * Whether the old invoice can no longer be paid, so a new one may be cut.
     *
     * @return bool
     */
    public function isClosed()
    {
        return $this->closed;
    }

    /** @return string */
    public function invoiceId()
    {
        return $this->invoiceId;
    }

    /**
     * The invoice status as the server last reported it; empty when unknown.
     *
     * @return string
     */
    public function status()
    {
        return $this->status;
    }

    /**
     * One of the REASON_* constants.
     *
     * @return string
     */
    public function reason()
    {
        return $this->reason;
    }

    /**
     * The underlying error message, when a call failed.
     *
     * @return string
     */
    public function detail()
    {
        return $this->detail;
    }

    /**
     * One line for the store's log or an order note (English, like the rest of
     * the plugin's technical notes). Names the invoice and says, in plain
     * words, what state it is in and what the merchant should do. It carries
     * no API status names, HTTP codes or error text; those stay in status()
     * and detail().
     *
     * @return string
     */
    public function summary()
    {
        $invoice = $this->invoiceId !== '' ? 'Paymos invoice ' . $this->invoiceId : 'The previous Paymos invoice';

        switch ($this->reason) {
            case self::REASON_CANCELLED:
                return $invoice . ' is cancelled, so a new invoice can be issued for this order.';
            case self::REASON_ENDED_UNPAID:
                return $invoice . ' has closed without full payment, so a new invoice can be issued for this order.';
            case self::REASON_PAID:
                return $invoice . ' is already paid, so no new invoice was issued.'
                    . ' Check the payment in the Paymos dashboard before asking the buyer to pay again.';
            case self::REASON_OPEN:
                return $invoice . ' ' . $this->openState() . ', so it could not be closed and no new invoice was issued.'
                    . ' Check it in the Paymos dashboard before asking the buyer to pay again.';
            case self::REASON_NOT_FOUND:
                return $invoice . ' could not be found with the store\'s current Paymos connection, so there is no way'
                    . ' to confirm it is closed. No new invoice was issued. Look the invoice up in the Paymos dashboard.';
            case self::REASON_MISSING_ID:
                return 'This order has no saved Paymos invoice ID, so its previous invoice could not be checked.'
                    . ' No new invoice was issued. Find the order\'s invoice in the Paymos dashboard.';
        }

        return $invoice . ' could not be checked, so no new invoice was issued.'
            . ' Open it in the Paymos dashboard to see its state.';
    }

    /**
     * What an invoice that can still take money is doing, for summary().
     *
     * @return string
     */
    private function openState()
    {
        switch ($this->status) {
            case 'awaiting_payment':
                return 'is waiting for the buyer\'s transfer';
            case 'confirming':
                return 'has a payment that is still confirming';
            case 'underpaid_waiting':
                return 'is part paid and waiting for the rest';
        }

        return 'can still be paid';
    }
}
