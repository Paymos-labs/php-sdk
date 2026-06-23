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

    /**
     * @param array<string, string> $expected
     */
    public function verify(WebhookEvent $event, array $expected = array())
    {
        if ($event->invoiceId() === '') {
            return new InvoiceVerificationResult(false, 'missing_invoice_id', array());
        }

        $invoice = $this->client->invoices()->get($event->invoiceId());

        $reason = $this->compareScalar($event->status(), $this->field($invoice, array('status')), 'status_mismatch');
        if ($reason !== '') {
            return new InvoiceVerificationResult(false, $reason, $invoice);
        }

        $reason = $this->compareScalar($event->projectId(), $this->field($invoice, array('project_id')), 'project_mismatch');
        if ($reason !== '') {
            return new InvoiceVerificationResult(false, $reason, $invoice);
        }

        $reason = $this->compareScalar($event->externalOrderId(), $this->field($invoice, array('order', 'external_id')), 'external_order_mismatch');
        if ($reason !== '') {
            return new InvoiceVerificationResult(false, $reason, $invoice);
        }

        $reason = $this->compareExpected($expected, $invoice);
        if ($reason !== '') {
            return new InvoiceVerificationResult(false, $reason, $invoice);
        }

        return new InvoiceVerificationResult(true, '', $invoice);
    }

    private function compareScalar($left, $right, $reason)
    {
        $left = trim((string) $left);
        $right = trim((string) $right);

        if ($left === '' || $right === '') {
            return '';
        }

        return $left === $right ? '' : $reason;
    }

    /**
     * @param array<string, string> $expected
     * @param array<string, mixed> $invoice
     */
    private function compareExpected(array $expected, array $invoice)
    {
        $checks = array(
            'project_id' => array('project_id', 'project_mismatch'),
            'external_order_id' => array('order.external_id', 'external_order_mismatch'),
            'amount' => array('order.amount', 'amount_mismatch'),
            'currency' => array('order.currency', 'currency_mismatch'),
        );

        foreach ($checks as $key => $check) {
            if (!isset($expected[$key]) || trim((string) $expected[$key]) === '') {
                continue;
            }

            $path = explode('.', $check[0]);
            $actual = $this->field($invoice, $path);
            $expectedValue = (string) $expected[$key];

            if ($key === 'amount') {
                // The server trims trailing zeros on the wire ("100.00" -> "100"),
                // so a raw string compare would reject most paid invoices. Compare
                // decimal-safe, exactly as AmountGuard does.
                if (!AmountGuard::amountsEqual($expectedValue, $actual)) {
                    return $check[1];
                }
                continue;
            }

            if ($key === 'currency') {
                if (strtoupper(trim($expectedValue)) !== strtoupper(trim($actual))) {
                    return $check[1];
                }
                continue;
            }

            if ($expectedValue !== $actual) {
                return $check[1];
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
