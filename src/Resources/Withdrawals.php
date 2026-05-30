<?php

declare(strict_types=1);

namespace Paymos\Resources;

final class Withdrawals extends BaseResource
{
    private const PATH = '/v1/withdrawals';
    private const SANDBOX_PATH = '/v1/sandbox/withdrawals';

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
