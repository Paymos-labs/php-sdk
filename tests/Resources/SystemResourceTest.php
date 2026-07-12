<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;

function test_system_time_uses_the_v1_time_route()
{
    $transport = new MockTransport(array(new HttpResponse(200, '{"server_time":1700000000}', array())));
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret'),
        $transport,
        static function () { return 1700000000; }
    );

    $result = $client->system()->time();
    assertSameValue(1700000000, $result['server_time'], 'System time must decode server_time.');
    assertSameValue('https://api.paymos.io/v1/time', $transport->requests()[0]['url'], 'System time must use /v1/time.');
}
