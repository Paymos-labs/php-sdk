<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/** Immutable persisted snapshot. State changes go through RefundIntentStoreInterface. */
final class RefundIntent
{
    /** @var array<string, mixed> */
    private $data;

    public function __construct(array $data)
    {
        foreach (array('operation_id', 'request_token', 'installation_id', 'platform', 'native_payment_key',
            'invoice_id', 'project_id', 'environment', 'mode') as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '') {
                throw new \InvalidArgumentException('Missing refund intent field: ' . $field);
            }
        }
        if (!in_array($data['mode'], array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)
            || !in_array($data['environment'], array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('Invalid refund mode or environment.');
        }
        $key = 'pmrf:' . $data['platform'] . ':' . $data['installation_id'] . ':' . $data['operation_id'];
        if (strlen($key) > 200 || (isset($data['external_order_id']) && $data['external_order_id'] !== $key)) {
            throw new \InvalidArgumentException('Refund external_order_id must identify the immutable operation.');
        }
        $data['external_order_id'] = $key;
        $draft = isset($data['full_draft']) ? $data['full_draft'] : null;
        if ($data['mode'] === RefundStates::FULL) {
            if (!is_array($draft) || !isset($draft['amount_minor'], $draft['currency'])
                || !is_string($draft['amount_minor']) || !preg_match('/^[1-9][0-9]*$/D', $draft['amount_minor'])
                || !is_string($draft['currency']) || trim($draft['currency']) === '') {
                throw new \InvalidArgumentException('Full refund requires the original native paid total and draft.');
            }
        } elseif ($draft !== null) {
            throw new \InvalidArgumentException('Overpayment must not contain a native financial draft.');
        }
        $data['full_draft'] = $draft;
        $encoded = json_encode(array($data['invoice_id'], $data['mode'], $key, $draft), JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \InvalidArgumentException('Invalid refund intent payload.');
        }
        $hash = hash('sha256', $encoded);
        if (isset($data['payload_hash']) && $data['payload_hash'] !== $hash) {
            throw new \InvalidArgumentException('Refund intent payload changed.');
        }
        $data['payload_hash'] = $hash;
        if (isset($data['observation']) && is_array($data['observation'])) {
            $data['observation'] = VerifiedRefund::fromStorage($data['observation']);
        }
        if (isset($data['projection']) && is_array($data['projection'])) {
            $data['projection'] = NativeProjectionResult::fromStorage($data['projection']);
        }
        $this->data = $data + array('withdrawal_id' => null, 'observation' => null,
            'cash_state' => RefundStates::NOT_SUBMITTED, 'projection_state' => RefundStates::PROJECTION_NONE,
            'review_reason' => null, 'projection' => null, 'effects' => array());
    }

    public function operationId(): string { return $this->data['operation_id']; }
    public function paymentKey(): string { return $this->data['native_payment_key']; }
    public function invoiceId(): string { return $this->data['invoice_id']; }
    public function projectId(): string { return $this->data['project_id']; }
    public function environment(): string { return $this->data['environment']; }
    public function mode(): string { return $this->data['mode']; }
    public function externalOrderId(): string { return $this->data['external_order_id']; }
    public function withdrawalId(): ?string { return $this->data['withdrawal_id']; }
    public function cashState(): string { return $this->data['cash_state']; }
    public function projectionState(): string { return $this->data['projection_state']; }
    public function fullDraft(): ?array { return $this->data['full_draft']; }
    public function observation(): ?VerifiedRefund { return $this->data['observation']; }
    /** Plain persisted values; no PHP object serialization is required by a CMS. */
    public function toArray(): array
    {
        $data = $this->data;
        if ($data['observation'] !== null) {
            $data['observation'] = $data['observation']->toArray();
        }
        if ($data['projection'] !== null) {
            $data['projection'] = $data['projection']->toArray();
        }
        return $data;
    }
}
