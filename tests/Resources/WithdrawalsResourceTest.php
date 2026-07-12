<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;

function test_withdrawals_resource_uses_current_v1_contract_paths()
{
    $transport = new MockTransport(array(
        new HttpResponse(201, '{"withdrawal_id":"wd_123"}', array()),
        new HttpResponse(200, '{"withdrawal_id":"wd_123"}', array()),
        new HttpResponse(200, '{"withdrawal_id":"wd_123","status":"cancelled"}', array()),
        new HttpResponse(200, '{"withdrawal_id":"wd_123","status":"completed"}', array()),
    ));
    $client = new Client(
        new ClientConfig('rk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () {
            return 1709000000;
        }
    );

    $client->withdrawals()->create(array(
        'destination_address' => '0x1111111111111111111111111111111111111111',
        'network' => 'ethereum',
        'currency' => 'USDT',
        'amount' => '10.00',
        'external_order_id' => 'payout-123',
    ));
    $client->withdrawals()->get('wd_123');
    $client->withdrawals()->cancel('wd_123', 'merchant requested');
    $client->withdrawals()->simulateCompletion('wd_123');

    $requests = $transport->requests();
    assertSameValue('https://api.paymos.io/v1/withdrawals', $requests[0]['url'], 'Withdrawal create must use public v1 path.');
    assertSameValue(
        '{"destination_address":"0x1111111111111111111111111111111111111111","network":"ethereum","currency":"USDT","amount":"10.00","external_order_id":"payout-123"}',
        $requests[0]['body'],
        'Withdrawal create must keep money amounts as JSON strings.'
    );
    assertSameValue('https://api.paymos.io/v1/withdrawals/wd_123', $requests[1]['url'], 'Withdrawal get must use public v1 path.');
    assertSameValue('https://api.paymos.io/v1/withdrawals/wd_123/cancel', $requests[2]['url'], 'Withdrawal cancel must use public v1 path.');
    assertSameValue('{"reason":"merchant requested"}', $requests[2]['body'], 'Withdrawal cancel must encode reason body.');
    assertSameValue('https://api.paymos.io/v1/sandbox/withdrawals/wd_123/simulate-completion', $requests[3]['url'], 'Withdrawal sandbox simulate must use sandbox v1 path.');
}

function test_withdrawals_cancel_rejects_empty_reason()
{
    $transport = new MockTransport(array());
    $client = new Client(
        new ClientConfig('rk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () { return 1709000000; }
    );

    $threw = false;
    try {
        $client->withdrawals()->cancel('wd_123', '');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Withdrawal cancel must reject empty reason locally.');
}

function test_withdrawals_iterator_follows_cursor_without_changing_filters()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, '{"items":[{"withdrawal_id":"wdr_1"}],"next_cursor":"opaque+/="}', array()),
        new HttpResponse(200, '{"items":[{"withdrawal_id":"wdr_2"}],"next_cursor":null}', array()),
    ));
    $client = new Client(
        new ClientConfig('rk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () { return 1709000000; }
    );

    $items = iterator_to_array($client->withdrawals()->iterate(array(
        'status' => array('created'),
        'limit' => 1,
    ), 2), false);

    assertSameValue('wdr_1', $items[0]['withdrawal_id'], 'Iterator must yield the first page.');
    assertSameValue('wdr_2', $items[1]['withdrawal_id'], 'Iterator must follow next_cursor.');
    $requests = $transport->requests();
    assertSameValue(
        'https://api.paymos.io/v1/withdrawals?limit=1&status=created',
        $requests[0]['url'],
        'First iterator request must contain stable filters.'
    );
    assertSameValue(
        'https://api.paymos.io/v1/withdrawals?cursor=opaque%2B%2F%3D&limit=1&status=created',
        $requests[1]['url'],
        'Next iterator request must add the RFC 3986 encoded cursor and preserve filters.'
    );
}
