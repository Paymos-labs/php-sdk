<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class Withdrawals extends BaseResource
{
    private const PATH = '/v1/withdrawals';
    private const SANDBOX_PATH = '/v1/sandbox/withdrawals';

    private const LIST_FILTERS = array(
        'cursor', 'created_from', 'created_to', 'external_order_id', 'limit', 'status',
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
    public function get($withdrawalId)
    {
        return $this->requestJson('GET', self::PATH . '/' . rawurlencode((string) $withdrawalId), null);
    }

    /**
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
     * Cancel a withdrawal. The server requires a non-empty reason
     * (max length 500) — see Application.Withdrawals.CancelWithdrawalFormValidator.
     *
     * @param string $withdrawalId Prefixed id (e.g. "wdr_…").
     * @param string $reason       Free-form reason (1..500 chars).
     * @return array               WithdrawalStatusContract.
     */
    public function cancel($withdrawalId, $reason)
    {
        if (!is_string($reason) || trim($reason) === '') {
            throw new \InvalidArgumentException('Withdrawal cancel requires a non-empty reason.');
        }

        return $this->requestJson(
            'POST',
            self::PATH . '/' . rawurlencode((string) $withdrawalId) . '/cancel',
            array('reason' => $reason)
        );
    }

    /**
     * @return array
     */
    public function simulateCompletion($withdrawalId)
    {
        return $this->requestJson('POST', self::SANDBOX_PATH . '/' . rawurlencode((string) $withdrawalId) . '/simulate-completion', null);
    }
}
