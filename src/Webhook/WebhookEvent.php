<?php

declare(strict_types=1);

namespace Paymos\Webhook;

final class WebhookEvent
{
    /** @var array<string, mixed> */
    private $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray()
    {
        return $this->payload;
    }

    public function id()
    {
        return $this->stringAt(array('event_id'));
    }

    public function type()
    {
        return $this->stringAt(array('event_type'));
    }

    public function isInvoiceEvent()
    {
        return strpos(strtolower($this->type()), 'invoice.') === 0;
    }

    public function invoiceId()
    {
        return $this->stringAt(array('data', 'invoice_id'));
    }

    public function projectId()
    {
        return $this->stringAt(array('data', 'project_id'));
    }

    public function status()
    {
        return $this->stringAt(array('data', 'status'));
    }

    public function externalOrderId()
    {
        return $this->stringAt(array('data', 'order', 'external_id'));
    }

    public function orderAmount()
    {
        return $this->stringAt(array('data', 'order', 'amount'));
    }

    public function orderCurrency()
    {
        return $this->stringAt(array('data', 'order', 'currency'));
    }

    /**
     * @return bool|null
     */
    public function isTest()
    {
        $value = $this->valueAt(array('data', 'is_test'));
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === 'true' || $normalized === '1') {
                return true;
            }
            if ($normalized === 'false' || $normalized === '0') {
                return false;
            }
        }

        return (bool) $value;
    }

    /**
     * @param array<int, string> $path
     */
    private function stringAt(array $path)
    {
        $value = $this->valueAt($path);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param array<int, string> $path
     * @return mixed|null
     */
    private function valueAt(array $path)
    {
        $current = $this->payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
