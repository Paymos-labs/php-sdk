<?php

declare(strict_types=1);

namespace Paymos\Http;

final class RequestSigner
{
    public static function stringToSign($timestamp, $method, $path, $query, $body)
    {
        $bodyHash = $body === '' ? '' : hash('sha256', (string) $body);

        return (string) $timestamp . "\n" .
            strtoupper((string) $method) . "\n" .
            (string) $path . "\n" .
            (string) $query . "\n" .
            $bodyHash;
    }

    public static function sign($apiSecret, $stringToSign)
    {
        return base64_encode(hash_hmac('sha256', (string) $stringToSign, (string) $apiSecret, true));
    }

    public static function authorizationHeader($apiKey, $apiSecret, $timestamp, $method, $path, $query, $body)
    {
        $stringToSign = self::stringToSign($timestamp, $method, $path, $query, $body);
        $signature = self::sign($apiSecret, $stringToSign);

        return 'HMAC-SHA256 ' . $apiKey . ':' . $signature;
    }
}

