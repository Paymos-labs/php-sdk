<?php

declare(strict_types=1);

namespace Paymos\Plugin;

final class AmountGuard
{
    public static function isSafeToComplete(
        $invoiceAmount,
        $invoiceCurrency,
        $currentOrderAmount,
        $currentOrderCurrency,
        $eventOrderAmount = '',
        $eventOrderCurrency = ''
    ) {
        $invoiceAmount = trim((string) $invoiceAmount);
        $invoiceCurrency = strtoupper(trim((string) $invoiceCurrency));

        if ($invoiceAmount === '' || $invoiceCurrency === '') {
            return true;
        }

        if (self::normalizeAmount($invoiceAmount) !== self::normalizeAmount($currentOrderAmount)
            || $invoiceCurrency !== strtoupper(trim((string) $currentOrderCurrency))) {
            return false;
        }

        $eventOrderAmount = trim((string) $eventOrderAmount);
        if ($eventOrderAmount !== '' && self::normalizeAmount($eventOrderAmount) !== self::normalizeAmount($invoiceAmount)) {
            return false;
        }

        $eventOrderCurrency = trim((string) $eventOrderCurrency);
        if ($eventOrderCurrency !== '' && strtoupper($eventOrderCurrency) !== $invoiceCurrency) {
            return false;
        }

        return true;
    }

    public static function mismatchSummary(
        $invoiceAmount,
        $invoiceCurrency,
        $currentOrderAmount,
        $currentOrderCurrency,
        $eventOrderAmount = '',
        $eventOrderCurrency = ''
    ) {
        return sprintf(
            'Paymos payment was received, but the order amount changed. Invoice snapshot: %s %s. Current order: %s %s. Webhook order: %s %s. Manual review required.',
            trim((string) $invoiceAmount) === '' ? 'n/a' : (string) $invoiceAmount,
            trim((string) $invoiceCurrency) === '' ? 'n/a' : strtoupper((string) $invoiceCurrency),
            (string) $currentOrderAmount,
            strtoupper((string) $currentOrderCurrency),
            trim((string) $eventOrderAmount) === '' ? 'n/a' : (string) $eventOrderAmount,
            trim((string) $eventOrderCurrency) === '' ? 'n/a' : strtoupper((string) $eventOrderCurrency)
        );
    }

    private static function normalizeAmount($amount)
    {
        $value = trim((string) $amount);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '.') === false) {
            return ltrim($value, '+');
        }

        $value = rtrim(rtrim($value, '0'), '.');
        return $value === '' ? '0' : ltrim($value, '+');
    }
}
