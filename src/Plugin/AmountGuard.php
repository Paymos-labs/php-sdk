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

        if (!self::amountsEqual($invoiceAmount, $currentOrderAmount)
            || $invoiceCurrency !== strtoupper(trim((string) $currentOrderCurrency))) {
            return false;
        }

        $eventOrderAmount = trim((string) $eventOrderAmount);
        if ($eventOrderAmount !== '' && !self::amountsEqual($eventOrderAmount, $invoiceAmount)) {
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

    /**
     * Decimal-safe equality for two money strings. Prefers bcmath (exact,
     * scale 8 — covers every Paymos amount precision); falls back to comparing
     * fully canonicalized strings when ext-bcmath is unavailable.
     *
     * Both paths treat "50", "50.00", "050.00", "+50" and "50.0" as equal —
     * leading zeros, a leading "+", and trailing fractional zeros are all
     * insignificant. The earlier normalizer trimmed only trailing zeros and a
     * leading "+", so "50.00" vs "050.00" was a false mismatch that pushed a
     * legitimately-paid order into manual review.
     */
    public static function amountsEqual($a, $b)
    {
        $a = trim((string) $a);
        $b = trim((string) $b);

        if (function_exists('bccomp') && self::isNumericAmount($a) && self::isNumericAmount($b)) {
            return bccomp($a, $b, 8) === 0;
        }

        return self::normalizeAmount($a) === self::normalizeAmount($b);
    }

    /**
     * Whether a string is a plain decimal amount bcmath can compare safely
     * (optional sign, digits, optional single fractional part). Guards against
     * feeding bccomp junk like "" or "abc", which it would silently read as 0.
     */
    private static function isNumericAmount($value)
    {
        return (bool) preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/', (string) $value);
    }

    /**
     * Canonical form for fallback string comparison: strip a leading "+",
     * insignificant leading zeros in the integer part, and trailing zeros in the
     * fractional part (then a dangling dot). "050.00" → "50", "50.0" → "50",
     * ".5" → "0.5", "" → "".
     */
    private static function normalizeAmount($amount)
    {
        $value = trim((string) $amount);
        if ($value === '') {
            return '';
        }

        $sign = '';
        if ($value !== '' && ($value[0] === '+' || $value[0] === '-')) {
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
