<?php

declare(strict_types=1);

namespace Paymos\Webhook;

use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;

/**
 * Verifies Paymos webhook signatures.
 *
 * The Paymos webhook delivery service sends each event with:
 *
 *   POST <merchant-supplied-url>
 *   Content-Type: application/json
 *   X-Webhook-Signature: t=<unix-seconds>,v1=<hex-hmac>[,v1=<hex-hmac-prev>]
 *
 *   <compact json payload>
 *
 * Multiple `v1=` entries appear during the secret-rotation grace period (Stripe
 * pattern) — accept the message if ANY of them validates.
 *
 * The webhook secret has the form `whsec_<base62>` and is shown to the merchant
 * once at /dashboard/developers/webhooks (also rotatable from the same screen).
 */
final class WebhookVerifier
{
    /** @var string */
    private $secret;

    /** @var int */
    private $toleranceSeconds;

    public function __construct($secret, $toleranceSeconds = 300)
    {
        if (!is_string($secret) || trim($secret) === '') {
            throw new \InvalidArgumentException('Webhook secret must be a non-empty string.');
        }

        $this->secret = $secret;
        $this->toleranceSeconds = (int) $toleranceSeconds;
    }

    public function verify($signatureHeader, $rawBody, $now = null)
    {
        try {
            $this->assertValid($signatureHeader, $rawBody, $now);

            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    public function assertValid($signatureHeader, $rawBody, $now = null)
    {
        $parsed = $this->parseHeader((string) $signatureHeader);
        if ($parsed === null) {
            throw new SignatureMismatchException('Webhook signature header is missing or malformed.');
        }

        $timestamp = $parsed['timestamp'];
        $signatures = $parsed['signatures'];
        $currentTime = $now === null ? time() : (int) $now;

        if (abs($currentTime - $timestamp) > $this->toleranceSeconds) {
            throw new TimestampSkewException('Webhook timestamp is outside the allowed tolerance.');
        }

        $expected = hash_hmac('sha256', (string) $timestamp . '.' . (string) $rawBody, $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw new SignatureMismatchException('Webhook signature does not match payload.');
    }

    /**
     * @return array
     */
    public function decodeVerifiedPayload($signatureHeader, $rawBody, $now = null)
    {
        $this->assertValid($signatureHeader, $rawBody, $now);

        $decoded = json_decode((string) $rawBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Webhook payload is not valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array|null
     */
    private function parseHeader($header)
    {
        $timestamp = null;
        $signatures = array();
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $keyValue = explode('=', trim($part), 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            if ($keyValue[0] === 't' && ctype_digit($keyValue[1])) {
                $timestamp = (int) $keyValue[1];
                continue;
            }

            if ($keyValue[0] === 'v1' && $keyValue[1] !== '') {
                $signatures[] = $keyValue[1];
            }
        }

        if ($timestamp === null || count($signatures) === 0) {
            return null;
        }

        return array('timestamp' => $timestamp, 'signatures' => $signatures);
    }
}
