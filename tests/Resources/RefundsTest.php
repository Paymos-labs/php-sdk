<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Exception\ConflictException;
use Paymos\Exception\NotFoundException;
use Paymos\Exception\ValidationException;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;

function refund_test_client(array $responses, &$transport = null)
{
    $transport = new MockTransport($responses);
    return new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test', 30),
        $transport, static function () { return 1709000000; });
}

function refund_test_wire($id = 'wdr_over', $mode = 'overpayment', $amount = '20', $key = 'refund-order-2', $status = 'created')
{
    return array('withdrawal_id' => $id, 'invoice_id' => 'inv_74BPZFhr9qy9Uz2fbRkdJX', 'mode' => $mode,
        'external_order_id' => $key, 'amount' => $amount, 'fee' => '0.1', 'currency' => 'USDT',
        'network' => 'TRC20', 'status' => $status, 'first_sender' => 'TFirstSender',
        'destination_address' => 'TFirstSender', 'is_test' => true, 'created_at' => 1709000000);
}

function refund_test_response(array $wire, $status = 200)
{
    return new HttpResponse($status, json_encode($wire), array('Cache-Control' => 'private, no-store'));
}

function refund_test_problem($status, $code, array $extra = array())
{
    return new HttpResponse($status, json_encode(array('type' => 'about:blank', 'title' => 'Refund error',
        'status' => $status, 'detail' => 'Refund rejected.', 'code' => $code) + $extra),
        array('Content-Type' => 'application/problem+json'));
}

function refund_assert_signed(array $request)
{
    $url = parse_url($request['url']);
    $query = isset($url['query']) ? '?' . $url['query'] : '';
    $hash = $request['body'] === '' ? '' : hash('sha256', $request['body']);
    $message = "1709000000\n" . $request['method'] . "\n" . $url['path'] . "\n" . $query . "\n" . $hash;
    assertSameValue('HMAC-SHA256 pk_test_key:' . base64_encode(hash_hmac('sha256', $message, 'sk_test_secret', true)),
        $request['headers']['Authorization'], 'HMAC covers the exact path, query and body.');
    assertSameValue('1709000000', $request['headers']['X-Request-Timestamp'], 'Timestamp is sent.');
}

function test_refunds_simulate_uses_signed_terminal_status_and_preserves_current_shape()
{
    $address = 'UQDKbjIcfM6ezt8KjKJJLshZJJSqX7XOA4ff-W72r5gqPuwA';
    foreach (array('completed', 'failed', 'cancelled') as $status) {
        $wire = array_replace(refund_test_wire('wdr_1', 'full', '20', 'key', $status), array(
            'is_final' => true, 'network' => 'TON', 'destination_address' => $address,
            'first_sender' => $address, 'type' => 'refund', 'project_id' => 'prj_1'));
        $client = refund_test_client(array(refund_test_response($wire)), $transport);
        $result = $client->refunds()->simulate('wdr/1 ?', $status);
        assertSameValue($wire, $result, 'Current refund metadata and TON addresses survive decoding.');
        $request = $transport->requests()[0];
        assertSameValue('POST', $request['method'], 'Simulation is POST.');
        assertSameValue('https://api.paymos.test/v1/sandbox/refunds/wdr%2F1%20%3F/simulate', $request['url'], 'Refund identifier is one encoded path segment.');
        assertSameValue(array('status' => $status), json_decode($request['body'], true), 'Exactly the requested status is sent.');
        refund_assert_signed($request);
    }
}

function test_refunds_create_matches_both_shared_vectors_and_keeps_readonly_strings()
{
    $vectors = sdk_contract()['vectors']['refunds'];
    foreach (array('create', 'overpayment') as $name) {
        $vector = $vectors[$name];
        $body = $vector['body'];
        $amount = $name === 'create' ? '120' : '20';
        $wire = refund_test_wire('wdr_' . $name, $body['mode'], $amount, $body['external_order_id']);
        $client = refund_test_client(array(refund_test_response($wire, 201)), $transport);
        $actual = $client->refunds()->create($body['invoice_id'], $body['mode'], $body['external_order_id']);
        assertSameValue($wire, $actual, 'The decoded server snapshot is retained without money conversion.');
        foreach ($vectors['wire']['required_fields'] as $field) {
            assertTrueValue(array_key_exists($field, $actual), 'Refund response includes ' . $field);
        }
        assertSameValue($amount, $actual['amount'], 'Amount remains an exact decimal string.');
        $request = $transport->requests()[0];
        assertSameValue($vector['method'], $request['method'], 'Create is POST.');
        assertSameValue('https://api.paymos.test' . $vector['path'], $request['url'], 'Create uses the refund route.');
        assertSameValue($body, json_decode($request['body'], true), 'Only invoice, mode and key are sent.');
        refund_assert_signed($request);
        $actual['amount'] = '1';
        assertSameValue($amount, $wire['amount'], 'Editing the caller copy cannot update server evidence.');
    }
}

function test_refunds_overpayment_then_full_and_same_key_replay()
{
    $over = refund_test_wire();
    $full = refund_test_wire('wdr_full', 'full', '100', 'refund-order-full');
    $client = refund_test_client(array(refund_test_response($over, 201), refund_test_response($over),
        refund_test_response(array_replace($over, array('status' => 'completed'))), refund_test_response($full, 201)), $transport);
    $first = $client->refunds()->create($over['invoice_id'], 'overpayment', $over['external_order_id']);
    $repeat = $client->refunds()->create($over['invoice_id'], 'overpayment', $over['external_order_id']);
    assertSameValue($first, $repeat, '201 and 200 expose the same refund ID and amount.');
    assertSameValue('completed', $client->refunds()->get($over['withdrawal_id'])['status'], 'Fresh GET observes completion.');
    assertSameValue('100', $client->refunds()->create($over['invoice_id'], 'full', 'refund-order-full')['amount'],
        'After over20 the server supplies full100, without client subtraction.');
    assertSameValue($transport->requests()[0]['body'], $transport->requests()[1]['body'], 'Replay preserves the exact body.');
    foreach ($transport->requests() as $request) { refund_assert_signed($request); }
}

function test_refunds_changed_mode_is_structured_409()
{
    $wire = refund_test_wire('wdr_over', 'overpayment', '20', 'same-key');
    $client = refund_test_client(array(refund_test_response($wire, 201), refund_test_problem(409, 'idempotency_conflict')), $transport);
    $client->refunds()->create($wire['invoice_id'], 'overpayment', 'same-key');
    try {
        $client->refunds()->create($wire['invoice_id'], 'full', 'same-key');
        throw new RuntimeException('Expected mode conflict.');
    } catch (ConflictException $error) {
        assertSameValue(409, $error->statusCode(), 'Conflict retains HTTP status.');
        assertSameValue('idempotency_conflict', $error->errorCode(), 'Business code is retained.');
        assertSameValue('Refund rejected.', $error->detail(), 'RFC 9457 detail is retained.');
    }
    $first = json_decode($transport->requests()[0]['body'], true);
    $changed = json_decode($transport->requests()[1]['body'], true);
    assertSameValue($first['external_order_id'], $changed['external_order_id'], 'The conflict uses the same key.');
    assertSameValue('full', $changed['mode'], 'Changed mode reaches the server as supplied.');
}

function test_refunds_foreign_and_missing_ids_are_indistinguishable_404()
{
    $client = refund_test_client(array(refund_test_problem(404, 'not_found'), refund_test_problem(404, 'not_found')), $transport);
    foreach (array('wdr_foreign', 'wdr_missing') as $id) {
        try {
            $client->refunds()->get($id);
            throw new RuntimeException('Expected 404.');
        } catch (NotFoundException $error) {
            assertSameValue(404, $error->problemStatus(), 'Problem status is retained.');
            assertSameValue('not_found', $error->errorCode(), 'No ownership information is exposed.');
        }
    }
    foreach ($transport->requests() as $request) { refund_assert_signed($request); }
}

function test_refunds_list_signs_invoice_status_period_cursor_and_preserves_precision()
{
    $amount = '12345678901234567890.123456789012345678';
    $wire = refund_test_wire('wdr_exact', 'full', $amount);
    $client = refund_test_client(array(refund_test_response(array('items' => array($wire), 'next_cursor' => 'next+/='))), $transport);
    $page = $client->refunds()->listPage(array('invoice_id' => $wire['invoice_id'],
        'status' => array('signed', 'created'), 'created_from' => '2026-10-01T00:00:00Z',
        'created_to' => '2026-11-01T00:00:00+03:00', 'cursor' => 'opaque+/=', 'limit' => 100));
    assertSameValue($amount, $page['items'][0]['amount'], 'No float conversion or rounding.');
    assertSameValue('next+/=', $page['next_cursor'], 'Opaque cursor is preserved.');
    assertSameValue('https://api.paymos.test/v1/refunds?created_from=2026-10-01T00%3A00%3A00Z'
        . '&created_to=2026-11-01T00%3A00%3A00%2B03%3A00&cursor=opaque%2B%2F%3D'
        . '&invoice_id=inv_74BPZFhr9qy9Uz2fbRkdJX&limit=100&status=created&status=signed',
        $transport->requests()[0]['url'], 'Period uses RFC 3339 and status uses repeated keys.');
    refund_assert_signed($transport->requests()[0]);
}

function test_refunds_iterator_reads_100_100_5_without_changing_scope()
{
    $responses = array();
    foreach (array(100, 100, 5) as $index => $size) {
        $items = array();
        for ($i = 0; $i < $size; $i++) { $items[] = refund_test_wire('wdr_' . ($index * 100 + $i)); }
        $responses[] = refund_test_response(array('items' => $items, 'next_cursor' => $index < 2 ? 'page' . ($index + 1) : null));
    }
    $client = refund_test_client($responses, $transport);
    $items = iterator_to_array($client->refunds()->iterate(array('invoice_id' => 'inv_one', 'limit' => 100)), false);
    assertSameValue(205, count($items), 'All three pages are read.');
    assertSameValue(205, count(array_unique(array_column($items, 'withdrawal_id'))), 'No duplicate or skipped items.');
    assertSameValue(3, count($transport->requests()), 'Stops on null cursor.');
    foreach ($transport->requests() as $request) {
        assertTrueValue(strpos($request['url'], 'invoice_id=inv_one&limit=100') !== false, 'Scope filters survive pagination.');
        refund_assert_signed($request);
    }
}

function test_refunds_invalid_cursor_and_multi_validation_keep_problem_details()
{
    $client = refund_test_client(array(refund_test_problem(400, 'pagination_cursor_invalid', array('field' => 'cursor')),
        refund_test_problem(400, 'validation_failed', array('errors' => array(
            array('code' => 'field_required', 'field' => 'mode', 'message' => 'Required.'),
            array('code' => 'field_required', 'field' => 'invoice_id', 'message' => 'Required.'))))));
    foreach (array('pagination_cursor_invalid', 'validation_failed') as $code) {
        try {
            $client->refunds()->listPage(array('cursor' => 'invalid'));
            throw new RuntimeException('Expected validation error.');
        } catch (ValidationException $error) {
            assertSameValue($code, $error->errorCode(), 'Top-level code stays authoritative.');
            if ($code === 'validation_failed') {
                assertSameValue(2, count($error->errors()), 'All field errors survive.');
            } else {
                assertSameValue('cursor', $error->field(), 'Flat field survives.');
            }
        }
    }
}

function test_refunds_reject_money_arguments_invalid_modes_and_unknown_filters_before_http()
{
    $client = refund_test_client(array(), $transport);
    $invalidCalls = array(
        static function () use ($client) { $client->refunds()->create('inv', 'partial', 'key'); },
        static function () use ($client) { $client->refunds()->create('inv', 'full', 'key', '20'); },
        static function () use ($client) { $client->refunds()->create('inv', 'full', ''); },
    );
    foreach (array('amount', 'currency', 'network', 'address', 'parts', 'period') as $filter) {
        $invalidCalls[] = static function () use ($client, $filter) { $client->refunds()->listPage(array($filter => '1')); };
    }
    foreach ($invalidCalls as $call) {
        $threw = false;
        try { $call(); } catch (InvalidArgumentException $error) { $threw = true; }
        assertTrueValue($threw, 'Invalid inputs are rejected locally.');
    }
    assertSameValue(array(), $transport->requests(), 'Invalid requests never reach HTTP.');
}

function test_refunds_get_escapes_id_and_reads_current_state_without_client_cache()
{
    $client = refund_test_client(array(refund_test_response(refund_test_wire()),
        refund_test_response(refund_test_wire('wdr_over', 'overpayment', '20', 'refund-order-2', 'completed'))), $transport);
    assertSameValue('created', $client->refunds()->get('wdr /?')['status'], 'First read is pending.');
    assertSameValue('completed', $client->refunds()->get('wdr /?')['status'], 'Second read is fresh.');
    assertSameValue('https://api.paymos.test/v1/refunds/wdr%20%2F%3F', $transport->requests()[0]['url'], 'ID is one path segment.');
}

function test_refunds_iterator_rejects_cursor_cycles()
{
    $page = array('items' => array(), 'next_cursor' => 'loop');
    $client = refund_test_client(array(refund_test_response($page), refund_test_response($page)));
    $threw = false;
    try { iterator_to_array($client->refunds()->iterate()); } catch (RuntimeException $error) { $threw = true; }
    assertTrueValue($threw, 'A broken cursor must not cause an endless read.');
}
