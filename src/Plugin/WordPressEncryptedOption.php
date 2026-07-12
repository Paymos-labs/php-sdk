<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/** Authenticated encrypted WordPress option used by official Paymos plugins. */
final class WordPressEncryptedOption
{
    /** @return array<string, mixed> */
    public static function load($optionKey, $aad)
    {
        $encoded = get_option((string) $optionKey, '');
        if (!is_string($encoded) || $encoded === '') {
            return array();
        }
        return AesGcmEnvelope::open($encoded, self::keyMaterial(), (string) $aad);
    }

    /** @param array<string, mixed> $payload */
    public static function save($optionKey, $aad, array $payload)
    {
        $encoded = AesGcmEnvelope::seal($payload, self::keyMaterial(), (string) $aad);
        $updated = update_option((string) $optionKey, $encoded, false);
        $stored = get_option((string) $optionKey, null);
        if ($updated === false && (!is_string($stored) || !hash_equals($encoded, $stored))) {
            throw new \RuntimeException('Encrypted Paymos data could not be saved.');
        }
    }

    public static function delete($optionKey)
    {
        delete_option((string) $optionKey);
        if (get_option((string) $optionKey, null) !== null) {
            throw new \RuntimeException('Encrypted Paymos data could not be deleted.');
        }
    }

    public static function keyMaterial()
    {
        if (!function_exists('wp_salt')) {
            throw new \RuntimeException('WordPress security salts are required to protect Paymos data.');
        }
        $material = (string) wp_salt('auth') . '|' . (string) wp_salt('secure_auth');
        if ($material === '|') {
            throw new \RuntimeException('WordPress security salts are not configured.');
        }
        return $material;
    }
}
