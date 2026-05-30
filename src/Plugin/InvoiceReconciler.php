<?php

declare(strict_types=1);

namespace Paymos\Plugin;

use Paymos\Client;

final class InvoiceReconciler
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @param array<int, string> $invoiceIds
     * @return InvoiceReconciliationResult[]
     */
    public function reconcile(array $invoiceIds)
    {
        $results = array();

        foreach ($invoiceIds as $invoiceId) {
            $invoiceId = (string) $invoiceId;
            if (trim($invoiceId) === '') {
                continue;
            }

            $invoice = $this->client->invoices()->get($invoiceId);
            $status = isset($invoice['status']) && is_scalar($invoice['status'])
                ? (string) $invoice['status']
                : '';
            $resolvedInvoiceId = isset($invoice['invoice_id']) && is_scalar($invoice['invoice_id'])
                ? (string) $invoice['invoice_id']
                : $invoiceId;

            $results[] = new InvoiceReconciliationResult(
                $resolvedInvoiceId,
                $status,
                StatusMapper::invoiceAction('', $status),
                $invoice
            );
        }

        return $results;
    }
}
