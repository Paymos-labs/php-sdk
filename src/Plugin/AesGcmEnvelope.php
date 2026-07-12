<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * Versioned authenticated-encryption envelope shared by official PHP plugins.
 * Platform adapters provide installation-specific key material and raw storage.
 */
final class AesGcmEnvelope
{
    private const CIPHER = 'aes-256-gcm';

    /** @param array<string, mixed> $payload */
    public static function seal(array $payload, $keyMaterial, $aad)
    {
        self::assertCryptoAvailable();
        $key = self::key($keyMaterial, $aad);
        $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Paymos protected payload could not be serialized.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            (string) $aad,
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('Paymos protected payload could not be encrypted.');
        }

        $encoded = json_encode(array(
            'version' => 1,
            'algorithm' => 'A256GCM',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ), JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Paymos protected envelope could not be serialized.');
        }
        return $encoded;
    }

    /** @return array<string, mixed> */
    public static function open($encoded, $keyMaterial, $aad)
    {
        self::assertCryptoAvailable();
        $envelope = json_decode((string) $encoded, true);
        if (!is_array($envelope)
            || !isset($envelope['version'], $envelope['algorithm'], $envelope['iv'], $envelope['tag'], $envelope['ciphertext'])
            || (int) $envelope['version'] !== 1
            || $envelope['algorithm'] !== 'A256GCM') {
            throw new \RuntimeException('Stored Paymos protected payload is invalid.');
        }

        $iv = base64_decode((string) $envelope['iv'], true);
        $tag = base64_decode((string) $envelope['tag'], true);
        $ciphertext = base64_decode((string) $envelope['ciphertext'], true);
        if ($iv === false || $tag === false || $ciphertext === false || strlen($iv) !== 12 || strlen($tag) !== 16) {
            throw new \RuntimeException('Stored Paymos protected payload is invalid.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key($keyMaterial, $aad),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            (string) $aad
        );
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Stored Paymos protected payload cannot be decrypted.');
        }

        $payload = json_decode($plaintext, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Stored Paymos protected payload has an invalid schema.');
        }
        return $payload;
    }

    private static function key($material, $aad)
    {
        $material = (string) $material;
        if ($material === '') {
            throw new \RuntimeException('Paymos encryption key material is not configured.');
        }
        return hash_hkdf('sha256', $material, 32, (string) $aad);
    }

    private static function assertCryptoAvailable()
    {
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new \RuntimeException('OpenSSL is required to protect Paymos credentials.');
        }
    }
}
