<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/** Normalized evidence from fresh signed refund and invoice reads, never a callback. */
final class VerifiedRefund
{
    /** @var array<string, mixed> */
    private $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromApi(array $refund, array $invoice, \DateTimeImmutable $checkedAt): self
    {
        foreach (array('withdrawal_id', 'invoice_id', 'mode', 'external_order_id', 'status',
            'amount', 'currency', 'network', 'first_sender') as $field) {
            if (!isset($refund[$field]) || !is_string($refund[$field]) || trim($refund[$field]) === '') {
                throw new \UnexpectedValueException('Incomplete refund evidence: ' . $field);
            }
        }
        if (!isset($invoice['invoice_id'], $invoice['project_id'], $invoice['is_test'], $refund['is_test'])
            || $invoice['invoice_id'] !== $refund['invoice_id'] || !is_string($invoice['project_id'])
            || $invoice['project_id'] === '' || !is_bool($invoice['is_test'])
            || $invoice['is_test'] !== $refund['is_test']
            || (isset($refund['project_id']) && $refund['project_id'] !== $invoice['project_id'])
            || (isset($refund['destination_address']) && $refund['destination_address'] !== $refund['first_sender'])
            || !in_array($refund['mode'], array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)
            || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $refund['amount'])
            || AmountGuard::normalizeDecimal($refund['amount']) === '0') {
            throw new \UnexpectedValueException('Inconsistent refund evidence.');
        }
        return new self(array_replace($refund, array('project_id' => $invoice['project_id'],
            'environment' => $invoice['is_test'] ? 'sandbox' : 'live', 'checked_at' => $checkedAt)));
    }

    /** Restore only the store's previously verified snapshot; coordinator still performs fresh GETs. */
    public static function fromStorage(array $data): self
    {
        if (!isset($data['checked_at'], $data['project_id']) || !is_string($data['checked_at'])) {
            throw new \UnexpectedValueException('Incomplete stored refund evidence.');
        }
        return self::fromApi($data, array('invoice_id' => $data['invoice_id'],
            'project_id' => $data['project_id'], 'is_test' => $data['is_test']), new \DateTimeImmutable($data['checked_at']));
    }

    public function toArray(): array
    {
        return array_replace($this->data, array('checked_at' => $this->checkedAt()->format('Y-m-d\TH:i:s.uP')));
    }

    public function withdrawalId(): string { return $this->data['withdrawal_id']; }
    public function invoiceId(): string { return $this->data['invoice_id']; }
    public function projectId(): string { return $this->data['project_id']; }
    public function environment(): string { return $this->data['environment']; }
    public function mode(): string { return $this->data['mode']; }
    public function externalOrderId(): string { return $this->data['external_order_id']; }
    public function status(): string { return $this->data['status']; }
    public function principalDecimal(): string { return $this->data['amount']; }
    public function currency(): string { return $this->data['currency']; }
    public function network(): string { return $this->data['network']; }
    public function recipient(): string { return $this->data['first_sender']; }
    public function checkedAt(): \DateTimeImmutable { return $this->data['checked_at']; }
}
