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
        $amount = self::normalizeDecimal($invoiceAmount);
        if ($amount === null || $amount === '0' || $amount[0] === '-') {
            return false;
        }
        foreach (array($invoiceCurrency, $currentOrderCurrency, $eventOrderCurrency) as $currency) {
            if (!is_string($currency) || trim($currency) === '') {
                return false;
            }
        }
        return self::amountsEqual($amount, $currentOrderAmount)
            && self::amountsEqual($amount, $eventOrderAmount)
            && strtoupper(trim($invoiceCurrency)) === strtoupper(trim($currentOrderCurrency))
            && strtoupper(trim($invoiceCurrency)) === strtoupper(trim($eventOrderCurrency));
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

    /**
     * Exact equality without a precision limit or an optional-extension branch.
     */
    public static function amountsEqual($a, $b)
    {
        $normalized = self::normalizeDecimal($a);
        return $normalized !== null && $normalized === self::normalizeDecimal($b);
    }

    /**
     * Canonical plain decimal; invalid input never becomes payment evidence.
     * Callers should pass money as strings: a float may already have lost precision.
     *
     * @param mixed $amount
     * @return string|null
     */
    public static function normalizeDecimal($amount)
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return null;
        }
        $value = trim((string) $amount);
        if (!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value)) {
            return null;
        }

        $sign = '';
        if ($value[0] === '+' || $value[0] === '-') {
            $sign = $value[0] === '-' ? '-' : '';
            $value = substr($value, 1);
        }

        if (strpos($value, '.') === false) {
            $int = ltrim($value, '0');
            $int = $int === '' ? '0' : $int;
            return ($int === '0' ? '' : $sign) . $int;
        }

        list($int, $frac) = explode('.', $value, 2);
        $int = ltrim($int, '0');
        $int = $int === '' ? '0' : $int;
        $frac = rtrim($frac, '0');

        $result = $frac === '' ? $int : $int . '.' . $frac;

        // Drop the sign for a canonical zero so "-0" and "0" match.
        return ($result === '0' ? '' : $sign) . $result;
    }
}
