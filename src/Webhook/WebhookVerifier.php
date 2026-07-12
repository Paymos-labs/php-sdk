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
 * once at /developers/webhooks (also rotatable from the same screen).
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

        if (!is_int($toleranceSeconds) || $toleranceSeconds < 0) {
            throw new \InvalidArgumentException('Webhook tolerance must be a non-negative integer.');
        }

        $this->secret = $secret;
        $this->toleranceSeconds = $toleranceSeconds;
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

        $this->assertEnvelope($decoded);

        return $decoded;
    }

    /**
     * @return array|null
     */
    private function parseHeader($header)
    {
        $timestamp = null;
        $timestampCount = 0;
        $signatures = array();
        $parts = explode(',', $header);

        foreach ($parts as $part) {
            $keyValue = explode('=', trim($part), 2);
            if (count($keyValue) !== 2) {
                continue;
            }

            if ($keyValue[0] === 't' && ctype_digit($keyValue[1])) {
                $parsedTimestamp = filter_var(
                    $keyValue[1],
                    FILTER_VALIDATE_INT,
                    array('options' => array('min_range' => 0))
                );
                if ($parsedTimestamp === false) {
                    return null;
                }
                $timestamp = $parsedTimestamp;
                $timestampCount++;
                continue;
            }

            if ($keyValue[0] === 'v1' && $keyValue[1] !== '') {
                $signatures[] = $keyValue[1];
            }
        }

        if ($timestamp === null || $timestampCount !== 1 || count($signatures) === 0) {
            return null;
        }

        return array('timestamp' => $timestamp, 'signatures' => $signatures);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function assertEnvelope(array $event)
    {
        if (!isset($event['event_id']) || !is_string($event['event_id']) || trim($event['event_id']) === '') {
            throw new \RuntimeException('Webhook event_id must be a non-empty string.');
        }
        if (!isset($event['event_type']) || !is_string($event['event_type']) || trim($event['event_type']) === '') {
            throw new \RuntimeException('Webhook event_type must be a non-empty string.');
        }
        if (!isset($event['version']) || !is_int($event['version']) || $event['version'] < 1) {
            throw new \RuntimeException('Webhook version must be a positive integer.');
        }
        if (!isset($event['occurred_at']) || !is_int($event['occurred_at']) || $event['occurred_at'] < 0) {
            throw new \RuntimeException('Webhook occurred_at must be a non-negative Unix timestamp.');
        }
        if (!isset($event['data']) || !is_array($event['data'])) {
            throw new \RuntimeException('Webhook data must be a JSON object.');
        }
    }
}
