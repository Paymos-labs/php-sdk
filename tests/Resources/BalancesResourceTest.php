<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;

function test_balances_resource_gets_all_balances()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, '[{"currency":"USDT","network":"TRC20","available":"10.00"}]', array()),
    ));
    $client = new Client(
        new ClientConfig('rk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () {
            return 1709000000;
        }
    );

    $balances = $client->balances()->get();
    $requests = $transport->requests();

    assertSameValue('USDT', $balances[0]['currency'], 'Balances resource must decode list response.');
    assertSameValue('GET', $requests[0]['method'], 'Balances resource must use GET.');
    assertSameValue('https://api.paymos.io/v1/balances', $requests[0]['url'], 'Balances resource must use public v1 path.');
}
