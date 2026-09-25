<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * A plugin wanted to replace an order's invoice and InvoiceReplacement could
 * not prove the old one closed. No new invoice was issued; the order belongs
 * in manual review. The message is written for the buyer on purpose — some
 * platforms (CS-Cart) show checkout exceptions to the buyer — so it says what
 * to do and nothing about the invoice; the details are in result().
 */
final class InvoiceReplacementBlockedException extends \RuntimeException
{
    /** @var InvoiceReplacementResult */
    private $result;

    public function __construct(InvoiceReplacementResult $result)
    {
        parent::__construct('The store needs to review this order before payment can continue. Please contact the store.');
        $this->result = $result;
    }

    /**
     * @return InvoiceReplacementResult
     */
    public function result()
    {
        return $this->result;
    }
}
