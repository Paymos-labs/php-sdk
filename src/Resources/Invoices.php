<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class Invoices extends BaseResource
{
    private const PATH = '/v1/invoices';
    private const SANDBOX_PATH = '/v1/sandbox/invoices';

    /**
     * @param array $payload
     * @return array
     */
    public function create(array $payload)
    {
        return $this->requestJson('POST', self::PATH, $payload);
    }

    /**
     * @return array
     */
    public function get($invoiceId)
    {
        return $this->requestJson('GET', self::PATH . '/' . rawurlencode((string) $invoiceId), null);
    }

    /**
     * Cancel an invoice. The server requires a non-empty reason
     * (max length 500) — see Application.Invoices.CancelInvoiceFormValidator.
     *
     * @param string $invoiceId Prefixed id (e.g. "inv_…").
     * @param string $reason    Free-form reason (1..500 chars).
     * @return array            InvoiceStatusContract.
     */
    public function cancel($invoiceId, $reason)
    {
        if (!is_string($reason) || trim($reason) === '') {
            throw new \InvalidArgumentException('Invoice cancel requires a non-empty reason.');
        }

        return $this->requestJson(
            'POST',
            self::PATH . '/' . rawurlencode((string) $invoiceId) . '/cancel',
            array('reason' => $reason)
        );
    }

    /**
     * @param array $payload
     * @return array
     */
    public function simulatePayment($invoiceId, array $payload)
    {
        return $this->requestJson('POST', self::SANDBOX_PATH . '/' . rawurlencode((string) $invoiceId) . '/simulate-payment', $payload);
    }
}
