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
