<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class InvoiceVerificationResult
{
    /** @var bool */
    private $verified;

    /** @var string */
    private $reason;

    /** @var array<string, mixed> */
    private $invoice;

    /**
     * @param array<string, mixed> $invoice
     */
    public function __construct($verified, $reason, array $invoice)
    {
        $this->verified = (bool) $verified;
        $this->reason = (string) $reason;
        $this->invoice = $invoice;
    }

    public function isVerified()
    {
        return $this->verified;
    }

    public function reason()
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice()
    {
        return $this->invoice;
    }
}
