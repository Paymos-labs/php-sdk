<?php

declare(strict_types=1);

namespace Paymos\Plugin;

use Paymos\Client;
use Paymos\Webhook\WebhookEvent;

final class InvoiceReverseVerifier
{
    /** @var Client */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $expected */
    public function verify(WebhookEvent $event, array $expected = array())
    {
        // Preserve wire types; convenience scalar accessors deliberately coerce.
        $payload = $event->toArray();
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
        $reason = $this->validateEvidence($data, 'event');
        if ($reason !== '') {
            return new InvoiceVerificationResult(false, $reason, array());
        }
        $invoice = $this->client->invoices()->get($data['invoice_id']);
        $reason = $this->validateEvidence($invoice, 'api');
        if ($reason !== '') {
            return new InvoiceVerificationResult(false, $reason, $invoice);
        }
        foreach (array('invoice_id' => 'invoice_mismatch', 'project_id' => 'project_mismatch', 'status' => 'status_mismatch') as $key => $failure) {
            if ($data[$key] !== $invoice[$key]) {
                return new InvoiceVerificationResult(false, $failure, $invoice);
            }
        }
        $statuses = array(
            'invoice.awaiting_payment' => array('awaiting_payment'),
            'invoice.confirming' => array('confirming'),
            'invoice.underpaid_waiting' => array('underpaid_waiting'),
            'invoice.paid' => array('paid'),
            'invoice.paid_over' => array('paid_over'),
            'invoice.underpaid' => array('underpaid'),
            'invoice.expired' => array('expired'),
            'invoice.cancelled' => array('cancelled'),
            // Late payment is a fact beside the original terminal unpaid status.
            'invoice.paid_late' => array('expired', 'underpaid'),
        );
        $type = isset($payload['event_type']) && is_string($payload['event_type']) ? $payload['event_type'] : '';
        if (!isset($statuses[$type]) || !in_array($invoice['status'], $statuses[$type], true)) {
            return new InvoiceVerificationResult(false, 'event_type_status_mismatch', $invoice);
        }
        if (array_key_exists('is_test', $invoice) || array_key_exists('is_test', $data)) {
            if (!isset($invoice['is_test'], $data['is_test']) || !is_bool($invoice['is_test']) || !is_bool($data['is_test'])
                || $invoice['is_test'] !== $data['is_test']) {
                return new InvoiceVerificationResult(false, 'environment_mismatch', $invoice);
            }
        }
        $reason = $this->compareOrder($data['order'], $invoice['order']);
        if ($reason === '') {
            $reason = $this->compareExpected($expected, $data, $invoice);
        }
        return new InvoiceVerificationResult($reason === '', $reason, $invoice);
    }

    /** @param array<string, mixed> $evidence */
    private function validateEvidence(array $evidence, $source)
    {
        foreach (array('invoice_id', 'project_id', 'status') as $key) {
            if (!isset($evidence[$key]) || !is_string($evidence[$key]) || trim($evidence[$key]) === '') {
                return $source . '_missing_or_invalid_' . $key;
            }
        }
        if (!isset($evidence['order']) || !is_array($evidence['order'])) {
            return $source . '_missing_or_invalid_order';
        }
        foreach (array('external_id', 'amount', 'currency') as $key) {
            if (!isset($evidence['order'][$key]) || !is_string($evidence['order'][$key]) || trim($evidence['order'][$key]) === '') {
                return $source . '_missing_or_invalid_order_' . $key;
            }
        }
        $amount = AmountGuard::normalizeDecimal($evidence['order']['amount']);
        if ($amount === null || $amount === '0' || $amount[0] === '-') {
            return $source . '_invalid_order_amount';
        }
        return '';
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compareOrder(array $left, array $right)
    {
        if ($left['external_id'] !== $right['external_id']) {
            return 'external_order_mismatch';
        }
        if (!AmountGuard::amountsEqual($left['amount'], $right['amount'])) {
            return 'amount_mismatch';
        }
        return strtoupper(trim($left['currency'])) === strtoupper(trim($right['currency'])) ? '' : 'currency_mismatch';
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $data @param array<string, mixed> $invoice */
    private function compareExpected(array $expected, array $data, array $invoice)
    {
        $actual = array('invoice_id' => $invoice['invoice_id'], 'project_id' => $invoice['project_id'],
            'external_order_id' => $invoice['order']['external_id'], 'amount' => $invoice['order']['amount'], 'currency' => $invoice['order']['currency']);
        $reasons = array('invoice_id' => 'invoice_mismatch', 'project_id' => 'project_mismatch',
            'external_order_id' => 'external_order_mismatch', 'amount' => 'amount_mismatch', 'currency' => 'currency_mismatch');
        foreach ($actual as $key => $value) {
            if (!array_key_exists($key, $expected)) {
                continue;
            }
            if (!is_string($expected[$key]) || trim($expected[$key]) === '') {
                return 'missing_or_invalid_expected_' . $key;
            }
            if ($key === 'amount') {
                $matches = AmountGuard::amountsEqual($expected[$key], $value);
            } elseif ($key === 'currency') {
                $matches = strtoupper(trim($expected[$key])) === strtoupper(trim($value));
            } else {
                $matches = $expected[$key] === $value;
            }
            if (!$matches) {
                return $reasons[$key];
            }
        }
        if (array_key_exists('environment', $expected)) {
            if (!in_array($expected['environment'], array('live', 'production', 'sandbox'), true)) {
                return 'missing_or_invalid_expected_environment';
            }
            $isTest = $expected['environment'] === 'sandbox';
            if (!isset($invoice['is_test'], $data['is_test']) || !is_bool($invoice['is_test']) || !is_bool($data['is_test'])
                || $invoice['is_test'] !== $isTest || $data['is_test'] !== $isTest) {
                return 'environment_mismatch';
            }
        }
        return '';
    }
}
