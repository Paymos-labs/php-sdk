<?php

declare(strict_types=1);

use Paymos\Http\RequestSigner;
use Paymos\Exception\ApiException;
use Paymos\Webhook\WebhookVerifier;

function sdk_contract()
{
    $value = json_decode((string) file_get_contents(__DIR__ . '/../conformance/contract.json'), true);
    if (!is_array($value)) {
        throw new RuntimeException('Invalid SDK conformance contract.');
    }
    return $value;
}

function test_shared_conformance_request_signing_vectors()
{
    $vectors = sdk_contract()['vectors'];
    $post = $vectors['post_signing'];
    assertSameValue(
        $post['authorization'],
        RequestSigner::authorizationHeader(
            $post['api_key'], $post['api_secret'], $post['timestamp'], $post['method'],
            $post['path'], $post['query'], $post['body']
        ),
        'PHP SDK must match the shared POST signing vector.'
    );

    $get = $vectors['get_query_signing'];
    assertSameValue(
        $get['signature'],
        RequestSigner::sign(
            $get['api_secret'],
            RequestSigner::stringToSign($get['timestamp'], $get['method'], $get['path'], $get['query'], $get['body'])
        ),
        'PHP SDK must match the shared GET signing vector.'
    );

    // An absent body signs an EMPTY last segment, never sha256("") — and this vector is a POST,
    // so an SDK that shortcuts on the method ("only GET is empty") fails here.
    $empty = $vectors['post_empty_body_signing'];
    assertSameValue(
        $empty['authorization'],
        RequestSigner::authorizationHeader(
            $empty['api_key'], $empty['api_secret'], $empty['timestamp'], $empty['method'],
            $empty['path'], $empty['query'], $empty['body']
        ),
        'PHP SDK must match the shared empty-body POST signing vector.'
    );
}

function test_shared_conformance_rotated_webhook_vector()
{
    $vector = sdk_contract()['vectors']['webhook'];
    $verifier = new WebhookVerifier($vector['secret'], $vector['tolerance_seconds']);
    assertTrueValue(
        $verifier->verify($vector['header'], $vector['raw_body'], $vector['now']),
        'PHP SDK must accept any valid rotated v1 webhook signature.'
    );
}

function test_shared_conformance_signed_ton_webhook_raw_and_friendly_v1_preserve_fields()
{
    $fixtures = sdk_contract()['vectors']['ton_webhooks'];
    assertSameValue(8, count($fixtures['vectors']), 'All raw and friendly schema 1 TON event cases must be present.');
    $verifier = new WebhookVerifier($fixtures['secret']);
    foreach ($fixtures['vectors'] as $vector) {
        $event = $verifier->decodeVerifiedPayload($vector['header'], $vector['raw_body'], $fixtures['now']);
        assertSameValue($vector['event_id'], $event['event_id'], $vector['name']);
        assertSameValue($vector['event_type'], $event['event_type'], $vector['name']);
        assertSameValue(1, $vector['version'], $vector['name']);
        assertSameValue($vector['version'], $event['version'], $vector['name']);
        assertSameValue($fixtures['now'], $event['occurred_at'], $vector['name']);
        foreach ($vector['expect'] as $expected) {
            $value = $event['data'];
            foreach ($expected['path'] as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    throw new RuntimeException('Missing signed webhook field in ' . $vector['name']);
                }
                $value = $value[$key];
            }
            assertSameValue($expected['value'], $value, $vector['name']);
        }
        assertFalseValue($verifier->verify($vector['header'], $vector['raw_body'] . ' ', $fixtures['now']),
            'Even a JSON-valid byte change must invalidate the signature.');
    }
}

function test_shared_conformance_problem_details_uses_top_level_code()
{
    $vector = sdk_contract()['vectors']['problem_details']['multi'];
    $error = ApiException::fromResponse(400, json_encode($vector));

    assertSameValue('validation_failed', $error->errorCode(), 'Top-level code is authoritative.');
    assertSameValue(null, $error->field(), 'Request-level field comes only from the top level.');
    assertSameValue('field_required', $error->errors()[0]['code'], 'Field errors remain available.');
}
