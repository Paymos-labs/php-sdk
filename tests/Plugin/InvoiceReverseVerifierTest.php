<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Webhook\WebhookEvent;

function test_invoice_reverse_verifier_accepts_matching_api_invoice()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'wc_100',
                'amount' => '49.95',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_abc', 'sk_test_def', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });
    $event = new WebhookEvent(array(
        'event_id' => 'evt_123',
        'event_type' => 'invoice.paid',
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'wc_100', 'amount' => '49.95', 'currency' => 'USD'),
        ),
    ));

    $result = (new InvoiceReverseVerifier($client))->verify($event);

    assertTrueValue($result->isVerified(), 'matching API invoice must verify.');
    assertSameValue('', $result->reason(), 'verified result must not carry a failure reason.');
    assertSameValue('paid', $result->invoice()['status'], 'verified result must expose fetched invoice.');
}

function test_invoice_reverse_verifier_rejects_status_mismatch()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'order' => array('external_id' => 'wc_100', 'amount' => '49.95', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_abc', 'sk_test_def', 'https://api.paymos.test'), $transport);
    $event = new WebhookEvent(array(
        'event_id' => 'evt_123',
        'event_type' => 'invoice.paid',
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'wc_100', 'amount' => '49.95', 'currency' => 'USD'),
        ),
    ));

    $result = (new InvoiceReverseVerifier($client))->verify($event);

    assertFalseValue($result->isVerified(), 'API status mismatch must fail reverse verification.');
    assertSameValue('status_mismatch', $result->reason(), 'status mismatch must be machine-readable.');
}
