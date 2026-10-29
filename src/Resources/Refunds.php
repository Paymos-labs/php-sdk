<?php

declare(strict_types=1);

namespace Paymos\Resources;

/** Read-only server snapshots, in the same snake_case arrays as the other resources. */
final class Refunds extends BaseResource
{
    private const PATH = '/v1/refunds';
    private const LIST_FILTERS = array('invoice_id', 'status', 'created_from', 'created_to', 'cursor', 'limit');

    /** @return array Server-calculated amounts are decimal strings. */
    public function create(string $invoiceId, string $mode, string $externalOrderId): array
    {
        if (func_num_args() !== 3) {
            throw new \InvalidArgumentException('Refund create accepts only invoiceId, mode and externalOrderId.');
        }
        if (trim($invoiceId) === '' || trim($externalOrderId) === '' || strlen($externalOrderId) > 200) {
            throw new \InvalidArgumentException('Refund invoiceId and externalOrderId are required; key maximum is 200 bytes.');
        }
        if (!in_array($mode, array('full', 'overpayment'), true)) {
            throw new \InvalidArgumentException('Refund mode must be full or overpayment.');
        }

        return $this->requestJson('POST', self::PATH, array(
            'invoice_id' => $invoiceId,
            'mode' => $mode,
            'external_order_id' => $externalOrderId,
        ));
    }

    public function simulate(string $refundId, string $status): array
    {
        return $this->requestJson('POST', '/v1/sandbox/refunds/' . rawurlencode($refundId) . '/simulate',
            array('status' => $status));
    }

    public function get(string $refundId): array
    {
        return $this->requestJson('GET', self::PATH . '/' . rawurlencode($refundId));
    }

    /** @return array{items: array, next_cursor: string|null} */
    public function listPage(array $filters = array()): array
    {
        return $this->requestJson('GET', self::PATH, null,
            $this->buildQuery($filters, self::LIST_FILTERS, array('status')));
    }

    /** @return \Generator */
    public function iterate(array $filters = array(), $maxPages = 100)
    {
        if (!is_int($maxPages) || $maxPages < 1) {
            throw new \InvalidArgumentException('maxPages must be a positive integer.');
        }
        if (isset($filters['cursor']) && !is_string($filters['cursor'])) {
            throw new \InvalidArgumentException('cursor must be a string.');
        }
        $cursor = isset($filters['cursor']) ? $filters['cursor'] : null;
        $seen = array();
        for ($pageNumber = 0; $pageNumber < $maxPages; $pageNumber++) {
            if ($cursor === null) {
                unset($filters['cursor']);
            } else {
                $filters['cursor'] = $cursor;
                $seen[$cursor] = true;
            }
            $page = $this->listPage($filters);
            foreach ($page['items'] as $item) {
                yield $item;
            }
            $next = isset($page['next_cursor']) && is_string($page['next_cursor'])
                && $page['next_cursor'] !== '' ? $page['next_cursor'] : null;
            if ($next === null) {
                return;
            }
            if (isset($seen[$next])) {
                throw new \RuntimeException('Paymos API returned the same pagination cursor twice.');
            }
            $cursor = $next;
        }
    }
}
