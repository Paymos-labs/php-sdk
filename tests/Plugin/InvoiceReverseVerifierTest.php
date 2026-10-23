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

function test_invoice_reverse_verifier_accepts_trailing_zero_amount_difference()
{
    // The server trims trailing zeros on the wire: a 100.00 order is "100" in
    // both the webhook and the GET invoice. A plugin passes its snapshot amount
    // "100.00" as expected[]. Reverse-verify must treat these as equal, not
    // reject the paid invoice with amount_mismatch.
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'wc_100', 'amount' => '100', 'currency' => 'USD'),
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
            'order' => array('external_id' => 'wc_100', 'amount' => '100', 'currency' => 'USD'),
        ),
    ));

    $result = (new InvoiceReverseVerifier($client))->verify($event, array(
        'project_id' => 'prj_123',
        'external_order_id' => 'wc_100',
        'amount' => '100.00',
        'currency' => 'usd',
    ));

    assertTrueValue($result->isVerified(), 'snapshot "100.00" must match server-trimmed "100".');
    assertSameValue('', $result->reason(), 'trailing-zero amount difference must not be a mismatch.');
}

function test_invoice_reverse_verifier_rejects_true_amount_mismatch()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'wc_100', 'amount' => '90', 'currency' => 'USD'),
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
            'order' => array('external_id' => 'wc_100', 'amount' => '90', 'currency' => 'USD'),
        ),
    ));

    $result = (new InvoiceReverseVerifier($client))->verify($event, array(
        'project_id' => 'prj_123',
        'external_order_id' => 'wc_100',
        'amount' => '100.00',
        'currency' => 'USD',
    ));

    assertFalseValue($result->isVerified(), 'a real amount difference must still fail.');
    assertSameValue('amount_mismatch', $result->reason(), 'true amount mismatch must be machine-readable.');
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

function sdk_reverse_result(array $invoiceChanges = array(), array $eventChanges = array(), array $expected = array())
{
    $invoice = array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'status' => 'paid', 'is_test' => false,
        'order' => array('external_id' => 'wc_100', 'amount' => '33.104', 'currency' => 'KWD'));
    $payload = array('event_type' => 'invoice.paid', 'data' => $invoice);
    $invoice = array_replace_recursive($invoice, $invoiceChanges);
    $payload = array_replace_recursive($payload, $eventChanges);
    $client = new Client(new ClientConfig('pk_test_abc', 'sk_test_def', 'https://api.paymos.test'),
        new MockTransport(array(new HttpResponse(200, json_encode($invoice), array()))));
    return (new InvoiceReverseVerifier($client))->verify(new WebhookEvent($payload), $expected);
}

function test_invoice_reverse_verifier_requires_complete_typed_identity_and_order()
{
    foreach (array('invoice_id', 'project_id', 'status') as $field) {
        foreach (array('', null, true, 123, array()) as $invalid) {
            assertFalseValue(sdk_reverse_result(array($field => $invalid))->isVerified(), 'invalid API identity/status must fail closed.');
            assertFalseValue(sdk_reverse_result(array(), array('data' => array($field => $invalid)))->isVerified(), 'invalid event identity/status must fail closed.');
        }
    }
    foreach (array('external_id', 'amount', 'currency') as $field) {
        foreach (array('', null, true, 123, array()) as $invalid) {
            assertFalseValue(sdk_reverse_result(array('order' => array($field => $invalid)))->isVerified(), 'invalid API order field must fail closed.');
            assertFalseValue(sdk_reverse_result(array(), array('data' => array('order' => array($field => $invalid))))->isVerified(), 'invalid event order field must fail closed.');
        }
    }
    assertFalseValue(sdk_reverse_result(array('invoice_id' => 'inv_other'))->isVerified(), 'API response must identify the requested invoice.');
    assertFalseValue(sdk_reverse_result(array('order' => array('amount' => '33.10')))->isVerified(), 'event/API amount must agree exactly.');
    assertFalseValue(sdk_reverse_result(array('order' => array('currency' => 'USD')))->isVerified(), 'event/API currency must agree.');
    assertFalseValue(sdk_reverse_result(array(), array(), array('project_id' => ''))->isVerified(), 'explicit empty snapshot must fail closed.');
}

function test_invoice_reverse_verifier_requires_event_type_status_and_environment_consistency()
{
    assertFalseValue(sdk_reverse_result(array('status' => 'expired'), array('data' => array('status' => 'expired')))->isVerified(), 'paid type with expired evidence is contradictory.');
    assertFalseValue(sdk_reverse_result(array(), array('event_type' => 'invoice.unknown'))->isVerified(), 'unknown event cannot authorize payment.');
    assertFalseValue(sdk_reverse_result(array('is_test' => true), array(), array('environment' => 'production'))->isVerified(), 'API test environment must match snapshot.');
    assertFalseValue(sdk_reverse_result(array(), array('data' => array('is_test' => true)), array('environment' => 'production'))->isVerified(), 'event environment must match snapshot.');
    assertFalseValue(sdk_reverse_result(array('is_test' => 'false'), array(), array('environment' => 'production'))->isVerified(), 'truthy string is not boolean environment evidence.');
    assertFalseValue(sdk_reverse_result(array('is_test' => true))->isVerified(), 'contradictory environments cannot verify even without an explicit expected environment.');
    assertTrueValue(sdk_reverse_result(array(), array(), array('environment' => 'production'))->isVerified(), 'complete production evidence must verify.');
    foreach (array('expired', 'underpaid') as $status) {
        assertTrueValue(sdk_reverse_result(array('status' => $status), array('event_type' => 'invoice.paid_late', 'data' => array('status' => $status)))->isVerified(), 'paid_late must retain original terminal unpaid status.');
    }
    assertFalseValue(sdk_reverse_result(array(), array('event_type' => 'invoice.paid_late'))->isVerified(), 'paid_late cannot carry paid status.');
}
