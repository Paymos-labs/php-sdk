<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/** Compares verified refund recipients without changing the stored observations. */
final class RefundRecipientIdentity
{
    public static function same(VerifiedRefund $pinned, VerifiedRefund $observed): bool
    {
        $network = $pinned->network();
        $environment = $pinned->environment();
        if ($network !== $observed->network() || $environment !== $observed->environment()
            || !in_array($environment, array('live', 'sandbox'), true)) {
            return false;
        }

        if ($network !== 'TON' && $network !== 'TON_TESTNET') {
            // Unknown spellings of a TON network cannot bypass TON validation.
            if (strcasecmp($network, 'TON') === 0 || strcasecmp($network, 'TON_TESTNET') === 0) {
                return false;
            }
            return $pinned->recipient() === $observed->recipient();
        }

        $left = self::decodeTon($pinned->recipient());
        $right = self::decodeTon($observed->recipient());
        if ($left === null || $right === null) {
            return false;
        }
        // A marked test-only address is never valid on the live mainnet path.
        if ($network === 'TON' && $environment === 'live' && ($left['test_only'] || $right['test_only'])) {
            return false;
        }
        return $left['workchain'] === $right['workchain'] && $left['account'] === $right['account'];
    }

    /** @return array{workchain:int, account:string, test_only:bool}|null */
    private static function decodeTon(string $address): ?array
    {
        if (preg_match('/\A(0|-?[1-9][0-9]{0,2}):([0-9a-fA-F]{64})\z/D', $address, $match) === 1) {
            $workchain = (int) $match[1];
            if ($workchain < -128 || $workchain > 127) {
                return null;
            }
            $account = hex2bin($match[2]);
            return $account === false ? null : array('workchain' => $workchain, 'account' => $account, 'test_only' => false);
        }

        if (preg_match('/\A[A-Za-z0-9+\/_-]{48}\z/D', $address) !== 1
            || (strpbrk($address, '-_') !== false && strpbrk($address, '+/') !== false)) {
            return null;
        }
        $bytes = base64_decode(strtr($address, '-_', '+/'), true);
        if ($bytes === false || strlen($bytes) !== 36) {
            return null;
        }
        $tag = ord($bytes[0]);
        if (!in_array($tag, array(0x11, 0x51, 0x91, 0xd1), true)) {
            return null;
        }
        $crc = self::crc16(substr($bytes, 0, 34));
        if (ord($bytes[34]) !== ($crc >> 8) || ord($bytes[35]) !== ($crc & 0xff)) {
            return null;
        }
        $workchain = ord($bytes[1]);
        return array(
            'workchain' => $workchain >= 128 ? $workchain - 256 : $workchain,
            'account' => substr($bytes, 2, 32),
            'test_only' => ($tag & 0x80) !== 0,
        );
    }

    private static function crc16(string $value): int
    {
        $crc = 0;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $crc ^= ord($value[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) !== 0 ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xffff;
            }
        }
        return $crc;
    }
}
