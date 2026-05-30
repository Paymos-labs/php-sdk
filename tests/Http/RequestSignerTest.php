<?php

declare(strict_types=1);

use Paymos\Http\RequestSigner;

function test_request_signer_builds_canonical_string_with_body_hash()
{
    $body = '{"project_id":"prj_123","amount":"10.00","currency":"USDT"}';
    $expected = '1709000000' . "\n" .
        'POST' . "\n" .
        '/v1/invoices' . "\n" .
        '' . "\n" .
        hash('sha256', $body);

    assertSameValue(
        $expected,
        RequestSigner::stringToSign('1709000000', 'post', '/v1/invoices', '', $body),
        'Request signer must hash non-empty request bodies and uppercase the method.'
    );
}

function test_request_signer_builds_canonical_string_without_body_hash_for_get()
{
    $expected = '1709000000' . "\n" .
        'GET' . "\n" .
        '/v1/invoices/inv_123' . "\n" .
        '' . "\n" .
        '';

    assertSameValue(
        $expected,
        RequestSigner::stringToSign('1709000000', 'GET', '/v1/invoices/inv_123', '', ''),
        'GET without body must leave bodyHash empty instead of hashing an empty string.'
    );
}

function test_request_signer_returns_base64_hmac_signature()
{
    $stringToSign = "1709000000\nPOST\n/v1/invoices\n\nabc123";
    $secret = 'sk_test_secret';
    $expected = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

    assertSameValue(
        $expected,
        RequestSigner::sign($secret, $stringToSign),
        'Request signer must return Base64(HMAC-SHA256(secret, string_to_sign)).'
    );
}

function test_request_signer_formats_authorization_header()
{
    $header = RequestSigner::authorizationHeader(
        'pk_test_key',
        'sk_test_secret',
        '1709000000',
        'POST',
        '/v1/invoices',
        '',
        '{"amount":"10.00"}'
    );

    assertTrueValue(
        strpos($header, 'HMAC-SHA256 pk_test_key:') === 0,
        'Authorization header must include scheme, API key, and signature.'
    );
}
