<?php

declare(strict_types=1);

namespace Paymos\Resources;

/**
 * The confirmed payment-channel deposit FEED — not a list.
 *
 * Positions in the feed are global across all merchants, so this merchant's
 * filter skips many of them. An empty page therefore still hands back a cursor
 * PAST what was skipped: `next_cursor` is non-nullable and present on every
 * page, including an empty one. There is no last page — money keeps arriving.
 *
 * Store the cursor from every poll and resume from it. `confirmed_from` is an
 * initial-sync lower bound for the very first poll only; using it to resume
 * re-reads or skips whole spans.
 *
 * Every method returns the raw decoded snake_case array exactly as the API sent
 * it; this SDK has no model layer and the CMS plugins depend on that.
 *
 * This exact wire shape is also the `data` payload of all three
 * payment_channel.deposit.* webhook events (`.confirming`, `.reorged`,
 * `.confirmed`) — the same absent-not-null rule for optional members applies
 * there too. `confirming` and `reorged` are ADVISORY and may arrive out of
 * order: a `confirming` can land after the `confirmed` for the same deposit,
 * and a `reorged` can be superseded by a later `confirmed`. Credit a merchant
 * only on `payment_channel.deposit.confirmed` with `is_final` true, and never
 * let an advisory event regress a deposit already known to be confirmed —
 * crediting on `confirming` releases goods against money a reorg can still take
 * back. See "Payment-channel deposit events" in README.md.
 */
final class PaymentChannelDeposits extends BaseResource
{
    private const PATH = '/v1/payment-channel-deposits';

    /** The complete, closed parameter set of the feed — the server rejects anything else. */
    private const LIST_FILTERS = array(
        'cursor', 'limit', 'project_id', 'payment_channel_id', 'confirmed_from',
    );

    /**
     * @param string $depositId Prefixed id (e.g. "pcd_…").
     * @return array            PaymentChannelDepositWireContract.
     */
    public function get($depositId)
    {
        return $this->requestJson('GET', self::PATH . '/' . rawurlencode((string) $depositId), null);
    }

    /**
     * Read ONE page of the feed.
     *
     * Deliberately not called listPage(), and deliberately without an iterate()
     * generator: a loop that stops when the cursor is empty would either run
     * forever or — worse — halt on the first quiet page and leave a merchant's
     * reconciliation silently behind. Poll this yourself and persist the cursor.
     *
     * `blocked` appears only when one position could not be rendered; it carries
     * {deposit_id, reason} and explains a stall rather than causing one. Surface
     * it: without it, being stuck looks exactly like a quiet day.
     *
     * @param array $filters
     * @return array{items: array, next_cursor: string, blocked?: array{deposit_id: string, reason: string}}
     */
    public function readPage(array $filters = array())
    {
        return $this->requestJson(
            'GET',
            self::PATH,
            null,
            $this->buildQuery($filters, self::LIST_FILTERS)
        );
    }
}
