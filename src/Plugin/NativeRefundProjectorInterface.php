<?php

declare(strict_types=1);

namespace Paymos\Plugin;

interface NativeRefundProjectorInterface
{
    /**
     * Fresh mapping, connection capability, full original paid total/refunded=0 and saved defaults.
     * Return a review reason on ambiguity/manual changes; null permits the first POST.
     * A replay of an unknown request must remain possible when new submissions are disabled.
     */
    public function preflight(RefundIntent $intent): ?string;

    /**
     * Inspect the exact marker, parent payment, mode, original amount and currency.
     * Native snapshot/refunded-total changes are ambiguous; absent must prove a safe new native write.
     * New-submission gates must not prevent recovery of already accepted cash.
     */
    public function inspect(string $operationId): NativeProjectionResult;

    /**
     * Full: offline original native paid total + marker, saved stock/defaults, scoped mail suppression.
     * Overpayment: cash-only note + marker; no financial refund, order/stock/mail mutation.
     * Recover exact markers; ambiguity requires review. Never send provider HTTP here.
     * Use the store's native transaction, or proven native idempotency (WHMCS exact transid).
     * External notification is a separate claimed effect after commit, never sent here.
     */
    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult;
}
