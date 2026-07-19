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

function test_shared_conformance_problem_details_uses_top_level_code()
{
    $vector = sdk_contract()['vectors']['problem_details']['multi'];
    $error = ApiException::fromResponse(400, json_encode($vector));

    assertSameValue('validation_failed', $error->errorCode(), 'Top-level code is authoritative.');
    assertSameValue(null, $error->field(), 'Request-level field comes only from the top level.');
    assertSameValue('field_required', $error->errors()[0]['code'], 'Field errors remain available.');
}
