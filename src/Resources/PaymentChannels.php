<?php

declare(strict_types=1);

namespace Paymos\Resources;

/**
 * Payment channels — one reusable deposit address set per payer.
 *
 * Every method returns the raw decoded snake_case array exactly as the API sent
 * it; this SDK has no model layer and the CMS plugins depend on that.
 */
final class PaymentChannels extends BaseResource
{
    private const PATH = '/v1/payment-channels';
    private const SANDBOX_PATH = '/v1/sandbox/payment-channels';

    /** The complete, closed parameter set of the channel list — the server rejects anything else. */
    private const LIST_FILTERS = array(
        'cursor', 'limit', 'status', 'external_id', 'project_id',
    );

    /** The three lifecycle stages the sandbox can drive a simulated deposit to. */
    private const SIMULATE_STAGES = array('confirming', 'reorged', 'confirmed');

    /** The complete body of a sandbox deposit simulation. */
    private const SIMULATE_FIELDS = array('amount', 'currency', 'network', 'stage');

    /**
     * Create — or fetch. The pair (project_id, external_id) IS the idempotency
     * key: repeating it answers 200 with the channel that already exists instead
     * of 201 with a duplicate. Both responses are a channel, so a caller never
     * inspects the status code — safe to call on every checkout.
     *
     * @param array $payload {project_id: string, external_id: string}
     * @return array         PaymentChannelWireContract.
     */
    public function create(array $payload)
    {
        return $this->requestJson('POST', self::PATH, $payload);
    }

    /**
     * @param string $paymentChannelId Prefixed id (e.g. "pc_…").
     * @return array                   PaymentChannelWireContract.
     */
    public function get($paymentChannelId)
    {
        return $this->requestJson('GET', self::PATH . '/' . rawurlencode((string) $paymentChannelId), null);
    }

    /**
     * Fetch one cursor page of channels, newest first. This is an ordinary
     * keyset page: `next_cursor` is null on the LAST page. `status` may be an
     * array and is encoded as a repeated query parameter.
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
     * Lazily iterate channels while following next_cursor. maxPages is a hard
     * client-side guard against accidental unbounded API traversal.
     *
     * Deliberately absent from PaymentChannelDeposits: that endpoint is a feed
     * with no last page, and this loop's "stop when the cursor is empty" rule
     * would halt a merchant's reconciliation on the first quiet minute.
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
     * Stop accepting payments on this channel. Returns the updated channel —
     * this is not a void call; the response reports is_accepting_payments false.
     *
     * @param string $paymentChannelId Prefixed id (e.g. "pc_…").
     * @return array                   PaymentChannelWireContract.
     */
    public function block($paymentChannelId)
    {
        return $this->requestJson(
            'POST',
            self::PATH . '/' . rawurlencode((string) $paymentChannelId) . '/block',
            null
        );
    }

    /**
     * Accept payments again. The mirror of block(), and equally not void.
     *
     * @param string $paymentChannelId Prefixed id (e.g. "pc_…").
     * @return array                   PaymentChannelWireContract.
     */
    public function unblock($paymentChannelId)
    {
        return $this->requestJson(
            'POST',
            self::PATH . '/' . rawurlencode((string) $paymentChannelId) . '/unblock',
            null
        );
    }

    /**
     * Sandbox only. Land a simulated deposit on the channel without real
     * on-chain activity; a production credential gets an error, not a deposit.
     *
     * `network` is REQUIRED — the rail the deposit lands on, e.g. "TRC20".
     * `amount` is a decimal STRING, never a float: IEEE-754 loses stablecoin
     * precision. `stage` is optional, defaults to confirmed on the server, and
     * is omitted from the body entirely when unset — never sent as null or "".
     *
     * @param string $paymentChannelId Prefixed id (e.g. "pc_…").
     * @param array $payload           {amount: string, currency: string, network: string, stage?: string}
     * @return array                   PaymentChannelDepositWireContract.
     */
    public function simulateDeposit($paymentChannelId, array $payload)
    {
        foreach ($payload as $key => $_value) {
            if (!is_string($key) || !in_array($key, self::SIMULATE_FIELDS, true)) {
                throw new \InvalidArgumentException(
                    'Unknown Paymos simulateDeposit field: ' . (string) $key . '.'
                );
            }
        }

        $body = array();
        foreach (array('amount', 'currency', 'network') as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new \InvalidArgumentException(
                    'Payment channel simulateDeposit requires a non-empty string ' . $field . '.'
                );
            }
            $body[$field] = $payload[$field];
        }

        if (isset($payload['stage'])) {
            if (!is_string($payload['stage']) || !in_array($payload['stage'], self::SIMULATE_STAGES, true)) {
                throw new \InvalidArgumentException(
                    'Payment channel simulateDeposit stage must be one of: '
                    . implode(', ', self::SIMULATE_STAGES) . '.'
                );
            }
            $body['stage'] = $payload['stage'];
        }

        return $this->requestJson(
            'POST',
            self::SANDBOX_PATH . '/' . rawurlencode((string) $paymentChannelId) . '/simulate-deposit',
            $body
        );
    }
}
