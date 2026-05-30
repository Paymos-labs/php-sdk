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

