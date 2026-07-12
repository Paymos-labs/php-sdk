<?php

declare(strict_types=1);

use Paymos\Webhook\WebhookVerifier;

function test_webhook_verifier_accepts_valid_signature()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $verifier = new WebhookVerifier($secret, 300);

    assertTrueValue(
        $verifier->verify($header, $body, $timestamp),
        'Webhook verifier must accept a valid t/v1 signature.'
    );
}

function test_webhook_verifier_accepts_any_matching_rotation_signature()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $valid = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=invalid,v1=' . $valid;

    $verifier = new WebhookVerifier($secret, 300);

    assertTrueValue(
        $verifier->verify($header, $body, $timestamp),
        'Webhook verifier must allow multiple v1 signatures during secret rotation.'
    );
}

function test_webhook_verifier_rejects_stale_timestamp()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","type":"invoice.paid"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $verifier = new WebhookVerifier($secret, 300);

    assertFalseValue(
        $verifier->verify($header, $body, $timestamp + 301),
        'Webhook verifier must reject timestamps outside tolerance.'
    );
}

function test_webhook_verifier_rejects_bad_signature()
{
    $verifier = new WebhookVerifier('whsec_test_secret', 300);

    assertFalseValue(
        $verifier->verify('t=1739281200,v1=bad', '{"event_id":"evt_123"}', 1739281200),
        'Webhook verifier must reject non-matching signatures.'
    );
}

function test_webhook_verifier_rejects_duplicate_timestamps()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123"}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $verifier = new WebhookVerifier($secret, 300);

    assertFalseValue(
        $verifier->verify('t=' . $timestamp . ',t=' . $timestamp . ',v1=' . $signature, $body, $timestamp),
        'Webhook verifier must reject an ambiguous timestamp header.'
    );
}

function test_webhook_verifier_validates_verified_event_envelope()
{
    $secret = 'whsec_test_secret';
    $timestamp = 1739281200;
    $body = '{"event_id":"evt_123","event_type":"invoice.paid","data":{}}';
    $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
    $verifier = new WebhookVerifier($secret, 300);

    try {
        $verifier->decodeVerifiedPayload('t=' . $timestamp . ',v1=' . $signature, $body, $timestamp);
    } catch (\RuntimeException $error) {
        assertTrueValue(strpos($error->getMessage(), 'version') !== false, 'Missing version must be rejected.');
        return;
    }

    throw new \RuntimeException('Malformed verified webhook envelope was accepted.');
}

