<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class Invoices extends BaseResource
{
    private const PATH = '/v1/invoices';

    /**
     * Sandbox simulation lives on the public (anonymous, CORS) surface,
     * not under /v1/sandbox — see MerchantApi InvoiceEndpoints.
     */
    private const PUBLIC_PATH = '/public/v1/invoices';

    /** Valid sandbox simulation stages accepted by the server. */
    private const SIMULATE_STAGES = array('paid', 'overpaid', 'underpay', 'cancel');

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
     * Sandbox only. Drive an open sandbox invoice to a paid/overpaid/underpaid/
     * cancelled state without real on-chain activity. The server derives the
     * exact amount from the invoice's expected amount — the client only picks
     * the stage. Requires a sandbox (`pk_test_…` / `rk_test_…`) credential and
     * a sandbox invoice; production invoices are rejected.
     *
     * This is the public CORS endpoint
     * `POST /public/v1/invoices/{id}/simulate-payment` with body `{"stage": …}`.
     *
     * @param string $invoiceId Prefixed id (e.g. "inv_…").
     * @param string $stage     One of: paid | overpaid | underpay | cancel.
     * @return array            InvoiceStatusContract.
     */
    public function simulatePayment($invoiceId, $stage)
    {
        if (!is_string($stage) || !in_array($stage, self::SIMULATE_STAGES, true)) {
            throw new \InvalidArgumentException(
                'Invoice simulatePayment stage must be one of: '
                . implode(', ', self::SIMULATE_STAGES) . '.'
            );
        }

        return $this->requestJson(
            'POST',
            self::PUBLIC_PATH . '/' . rawurlencode((string) $invoiceId) . '/simulate-payment',
            array('stage' => $stage)
        );
    }
}
