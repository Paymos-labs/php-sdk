<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class InvoiceReconciliationResult
{
    /** @var string */
    private $invoiceId;

    /** @var string */
    private $status;

    /** @var string */
    private $action;

    /** @var array<string, mixed> */
    private $invoice;

    /**
     * @param array<string, mixed> $invoice
     */
    public function __construct($invoiceId, $status, $action, array $invoice)
    {
        $this->invoiceId = (string) $invoiceId;
        $this->status = (string) $status;
        $this->action = (string) $action;
        $this->invoice = $invoice;
    }

    public function invoiceId()
    {
        return $this->invoiceId;
    }

    public function status()
    {
        return $this->status;
    }

    public function action()
    {
        return $this->action;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice()
    {
        return $this->invoice;
    }
}
