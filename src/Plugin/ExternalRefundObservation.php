<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/** Cash history for a refund started outside this CMS; never a new cash intent. */
final class ExternalRefundObservation
{
    public static function stored(array $row): VerifiedRefund
    {
        if (!is_string($row['payment_key'] ?? null) || $row['payment_key'] === '' || !is_array($row['observation'] ?? null)
            || !array_key_exists('note_id', $row) || ($row['note_id'] !== null && (!is_string($row['note_id']) || $row['note_id'] === ''))
            || !array_key_exists('review_reason', $row) || ($row['review_reason'] !== null && !is_string($row['review_reason']))) {
            throw new \RuntimeException('refund_external_history_corrupt');
        }
        return VerifiedRefund::fromStorage($row['observation']);
    }

    public static function fold(?array $old, VerifiedRefund $refund, string $paymentKey): array
    {
        if ($paymentKey === '') { throw new \InvalidArgumentException('refund_payment_key_invalid'); }
        if ($old !== null) {
            $pin = self::stored($old);
            if (($old['payment_key'] ?? null) !== $paymentKey) { throw new \RuntimeException('refund_unbound_evidence_conflict'); }
            foreach (array('withdrawalId', 'invoiceId', 'projectId', 'environment', 'mode', 'externalOrderId', 'currency', 'network') as $method) {
                if ($pin->$method() !== $refund->$method()) { throw new \RuntimeException('refund_unbound_evidence_conflict'); }
            }
            if (!RefundRecipientIdentity::same($pin, $refund)) { throw new \RuntimeException('refund_unbound_evidence_conflict'); }
            if (!AmountGuard::amountsEqual($pin->principalDecimal(), $refund->principalDecimal())) {
                throw new \RuntimeException('refund_unbound_evidence_conflict');
            }
        }
        $note = $old['note_id'] ?? null;
        $reason = $refund->mode() === RefundStates::FULL ? 'refund_unbound_full_draft_missing'
            : ($refund->status() === 'completed' ? null : ($note !== null ? 'cash_rewound' : 'refund_unbound_pending'));
        return array('payment_key' => $paymentKey, 'observation' => $refund->toArray(), 'note_id' => $note,
            'review_reason' => $reason);
    }

    public static function needsNote(array $row): bool
    {
        return $row['note_id'] === null && $row['observation']['mode'] === RefundStates::OVERPAYMENT
            && $row['observation']['status'] === 'completed';
    }
}
