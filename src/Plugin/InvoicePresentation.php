<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * Shared checkout line-item normalization for PHP CMS plugins.
 *
 * Enforces the Merchant API `products[]` contract (1..50 items, all-or-nothing
 * fallback to amount-only when any product item or the collection is invalid).
 */
final class InvoicePresentation
{
    public const MAX_ITEMS = 50;
    private const MAX_TITLE_SCALARS = 200;
    private const MAX_DESCRIPTION_SCALARS = 2000;
    private const MAX_INT32_STRING = '2147483647';

    /**
     * Normalize one product line item or return null when required fields are invalid.
     *
     * Missing or invalid optional `description` values are omitted
     * from the returned array rather than failing the line item.
     *
     * @param string|null     $title
     * @param string|null     $description
     * @param int|string|null $quantity
     * @param string|null     $unitAmount
     * @return array<string, mixed>|null
     */
    public static function normalizeProduct(
        ?string $title,
        ?string $description,
        $quantity,
        ?string $unitAmount
    ): ?array {
        $normalizedTitle = self::normalizeTitle($title);
        if ($normalizedTitle === null) {
            return null;
        }

        $normalizedQuantity = self::normalizeQuantity($quantity);
        if ($normalizedQuantity === null) {
            return null;
        }

        $normalizedUnitAmount = self::normalizeUnitAmount($unitAmount);
        if ($normalizedUnitAmount === null) {
            return null;
        }

        $item = array(
            'title' => $normalizedTitle,
            'quantity' => $normalizedQuantity,
            'unit_amount' => $normalizedUnitAmount,
        );

        $normalizedDescription = self::normalizeDescription($description);
        if ($normalizedDescription !== null) {
            $item['description'] = $normalizedDescription;
        }

        return $item;
    }

    /**
     * Normalize a cart of 1..MAX_ITEMS products using an all-or-nothing rule.
     *
     * Returns null when empty, above MAX_ITEMS, or if any candidate is non-array
     * or fails required field normalization so the plugin falls back to amount-only.
     *
     * @param iterable<mixed> $candidates
     * @return array<int, array<string, mixed>>|null
     */
    public static function normalizeProducts(iterable $candidates): ?array
    {
        $items = array();
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                return null;
            }
            if (count($items) >= self::MAX_ITEMS) {
                return null;
            }

            $title = isset($candidate['title']) && is_string($candidate['title'])
                ? $candidate['title']
                : null;
            $description = isset($candidate['description']) && is_string($candidate['description'])
                ? $candidate['description']
                : null;
            $quantity = array_key_exists('quantity', $candidate) ? $candidate['quantity'] : null;
            $unitAmount = isset($candidate['unit_amount']) && is_string($candidate['unit_amount'])
                ? $candidate['unit_amount']
                : null;

            $normalized = self::normalizeProduct($title, $description, $quantity, $unitAmount);
            if ($normalized === null) {
                return null;
            }

            $items[] = $normalized;
        }

        return count($items) === 0 ? null : $items;
    }

    private static function normalizeTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $clean = str_replace("\0", '', $title);
        if (!self::isValidUtf8($clean)) {
            return null;
        }

        $clean = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = str_replace("\0", '', $clean);
        $collapsed = preg_replace('/[\r\n\t]+/u', ' ', $clean);
        if (!is_string($collapsed)) {
            return null;
        }

        $trimmed = self::trimUnicode($collapsed);
        if ($trimmed === null || $trimmed === '') {
            return null;
        }

        $chars = self::splitUtf8WithinLimit($trimmed, self::MAX_TITLE_SCALARS);
        if ($chars === null || count($chars) > self::MAX_TITLE_SCALARS) {
            return null;
        }

        return $trimmed;
    }

    private static function normalizeDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $clean = str_replace("\0", '', $description);
        if (!self::isValidUtf8($clean)) {
            return null;
        }

        $withBreaks = preg_replace('/<br\s*\/?>|<\/(?:p|div|li)>/iu', "\n", $clean);
        if (!is_string($withBreaks)) {
            return null;
        }

        $decoded = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(array("\0", "\r\n", "\r"), array('', "\n", "\n"), $decoded);
        $trimmed = self::trimUnicode($decoded);
        if ($trimmed === null || $trimmed === '') {
            return null;
        }

        $chars = self::splitUtf8WithinLimit($trimmed, self::MAX_DESCRIPTION_SCALARS);
        if ($chars === null) {
            return null;
        }
        if (count($chars) > self::MAX_DESCRIPTION_SCALARS) {
            $truncated = implode('', array_slice($chars, 0, self::MAX_DESCRIPTION_SCALARS));
            $retrimmed = preg_replace('/\s+\z/u', '', $truncated);
            if (!is_string($retrimmed) || $retrimmed === '') {
                return null;
            }
            return $retrimmed;
        }

        return $trimmed;
    }

    /**
     * @param int|string|null $quantity
     */
    private static function normalizeQuantity($quantity): ?int
    {
        if (is_int($quantity)) {
            return $quantity >= 1 && $quantity <= 2147483647 ? $quantity : null;
        }

        if (!is_string($quantity)) {
            return null;
        }

        if (preg_match('/\A[1-9][0-9]{0,9}\z/', $quantity) !== 1) {
            return null;
        }

        $len = strlen($quantity);
        if ($len === 10 && strcmp($quantity, self::MAX_INT32_STRING) > 0) {
            return null;
        }

        return (int) $quantity;
    }

    private static function normalizeUnitAmount(?string $unitAmount): ?string
    {
        if ($unitAmount === null || strpos($unitAmount, "\0") !== false) {
            return null;
        }

        $trimmed = trim($unitAmount);
        if (strlen($trimmed) > 39) {
            return null;
        }

        if (preg_match('/\A(?:0|[1-9][0-9]{0,19})(?:\.[0-9]{1,18})?\z/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    private static function isValidUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private static function trimUnicode(string $value): ?string
    {
        $trimmed = preg_replace('/\A\s+|\s+\z/u', '', $value);
        return is_string($trimmed) ? $trimmed : null;
    }

    /**
     * Split into at most `$maxScalars + 1` elements (the last holding any remainder)
     * so lengths above `$maxScalars` are detected and prefix slices stay O(maxScalars).
     *
     * @return array<int, string>|null
     */
    private static function splitUtf8WithinLimit(string $value, int $maxScalars): ?array
    {
        $chars = preg_split('//u', $value, $maxScalars + 1, PREG_SPLIT_NO_EMPTY);
        return is_array($chars) ? $chars : null;
    }
}
