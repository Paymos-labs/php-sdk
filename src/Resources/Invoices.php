<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class Invoices extends BaseResource
{
    private const PATH = '/v1/invoices';

    /** Signed sandbox simulator base — symmetric with withdrawals()->simulateCompletion(). */
    private const SANDBOX_PATH = '/v1/sandbox/invoices';

    /** Valid sandbox simulation stages accepted by the server. */
    private const SIMULATE_STAGES = array('paid', 'overpaid', 'underpay', 'cancel');

    private const LIST_FILTERS = array(
        'cursor', 'created_from', 'created_to', 'external_order_id',
        'limit', 'project_id', 'status',
    );

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
     * Fetch one cursor page. `status` may be an array and is encoded as a
     * repeated query parameter.
     *
     * @param array $filters
     * @return array{items: array, next_cursor: string|null}
     */
    public function listPage(array $filters = array())
    {
        return $this->requestJson(
            'GET',
            self::PATH,
            null,
            $this->buildQuery($filters, self::LIST_FILTERS, array('status'))
        );
    }

    /**
     * Lazily iterate list items while following next_cursor. maxPages is a
     * hard client-side guard against accidental unbounded API traversal.
     *
     * @param array $filters
     * @param int $maxPages
     * @return \Generator
     */
    public function iterate(array $filters = array(), $maxPages = 100)
    {
        if (!is_int($maxPages) || $maxPages < 1) {
            throw new \InvalidArgumentException('maxPages must be a positive integer.');
        }

        if (isset($filters['cursor']) && !is_string($filters['cursor'])) {
            throw new \InvalidArgumentException('cursor must be a string.');
        }
        $cursor = isset($filters['cursor']) ? $filters['cursor'] : null;
        for ($pageNumber = 0; $pageNumber < $maxPages; $pageNumber++) {
            if ($cursor === null) {
                unset($filters['cursor']);
            } else {
                $filters['cursor'] = $cursor;
            }

            $page = $this->listPage($filters);
            $items = isset($page['items']) && is_array($page['items']) ? $page['items'] : array();
            foreach ($items as $item) {
                yield $item;
            }

            $next = isset($page['next_cursor']) && is_string($page['next_cursor'])
                && $page['next_cursor'] !== '' ? $page['next_cursor'] : null;
            if ($next === null) {
                return;
            }
            if ($next === $cursor) {
                throw new \RuntimeException('Paymos API returned the same pagination cursor twice.');
            }
            $cursor = $next;
        }
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
     * Confirm the payment currency and network for a white-label checkout.
     *
     * @param string $invoiceId
     * @param string $currency
     * @param string $network
     * @return array
     */
    public function confirmPayment($invoiceId, $currency, $network)
    {
        if (!is_string($currency) || trim($currency) === '' || !is_string($network) || trim($network) === '') {
            throw new \InvalidArgumentException('currency and network must be non-empty strings.');
        }

        return $this->requestJson(
            'POST',
            self::PATH . '/' . rawurlencode((string) $invoiceId) . '/confirm-payment',
            array('currency' => $currency, 'network' => $network)
        );
    }

    /**
     * Sandbox only. Drive an open sandbox invoice to a paid/overpaid/underpaid/
     * cancelled state without real on-chain activity. The server derives the
     * exact amount from the invoice's expected amount — the client only picks
     * the stage. Requires a sandbox (`pk_test_…` / `rk_test_…`) credential and
     * a sandbox invoice; production invoices are rejected.
     *
     * Signed, sandbox-only route
     * `POST /v1/sandbox/invoices/{id}/simulate-payment` with body `{"stage": …}` —
     * every request this SDK sends is HMAC-signed, and the server gates the route
     * to sandbox keys (Env=Sandbox).
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
            self::SANDBOX_PATH . '/' . rawurlencode((string) $invoiceId) . '/simulate-payment',
            array('stage' => $stage)
        );
    }
}
