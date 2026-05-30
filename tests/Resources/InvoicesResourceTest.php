<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\MockTransport;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;

final class CapturingTransport implements TransportInterface
{
    /** @var array */
    public $requests = array();

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->requests[] = array(
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeoutSeconds' => $timeoutSeconds,
        );

        return new HttpResponse(201, '{"invoice_id":"inv_123","payment_url":"https://paymos.io/pay/inv_123"}', array());
    }
}

function test_invoices_resource_gets_cancels_and_simulates_payment()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, '{"invoice_id":"inv_123","status":"new"}', array()),
        new HttpResponse(200, '{"invoice_id":"inv_123","status":"cancelled"}', array()),
        new HttpResponse(200, '{"invoice_id":"inv_123","status":"paid"}', array()),
    ));
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () {
            return 1709000000;
        }
    );

    $client->invoices()->get('inv_123');
    $client->invoices()->cancel('inv_123', 'merchant requested');
    $client->invoices()->simulatePayment('inv_123', array('amount' => '10.00', 'currency' => 'USDT', 'network' => 'tron'));

    $requests = $transport->requests();
    assertSameValue('GET', $requests[0]['method'], 'Invoice get must use GET.');
    assertSameValue('https://api.paymos.io/v1/invoices/inv_123', $requests[0]['url'], 'Invoice get must use public v1 path.');
    assertSameValue('POST', $requests[1]['method'], 'Invoice cancel must use POST.');
    assertSameValue('https://api.paymos.io/v1/invoices/inv_123/cancel', $requests[1]['url'], 'Invoice cancel must use public v1 path.');
    assertSameValue('{"reason":"merchant requested"}', $requests[1]['body'], 'Invoice cancel must encode the required reason in JSON body.');
    assertSameValue('POST', $requests[2]['method'], 'Invoice simulatePayment must use POST.');
    assertSameValue('https://api.paymos.io/v1/sandbox/invoices/inv_123/simulate-payment', $requests[2]['url'], 'Invoice simulatePayment must use sandbox v1 path.');
}

function test_invoices_cancel_rejects_empty_reason()
{
    $transport = new Paymos\Http\MockTransport(array());
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () { return 1709000000; }
    );

    $threw = false;
    try {
        $client->invoices()->cancel('inv_123', '');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Invoice cancel must reject empty reason locally.');
}

function test_invoices_resource_creates_signed_invoice_request()
{
    $transport = new CapturingTransport();
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () {
            return 1709000000;
        }
    );

    $result = $client->invoices()->create(array(
        'project_id' => 'prj_123',
        'amount' => '10.00',
        'currency' => 'USDT',
        'network' => 'TRC20',
        'external_order_id' => 'order-123',
    ));

    assertSameValue('inv_123', $result['invoice_id'], 'Invoices resource must decode successful JSON response.');
    assertSameValue('POST', $transport->requests[0]['method'], 'Invoices create must use POST.');
    assertSameValue('https://api.paymos.io/v1/invoices', $transport->requests[0]['url'], 'Invoices create must use API v1 path under base URL.');
    assertSameValue('1709000000', $transport->requests[0]['headers']['X-Request-Timestamp'], 'Invoices create must pass request timestamp.');
    assertTrueValue(
        strpos($transport->requests[0]['headers']['Authorization'], 'HMAC-SHA256 pk_test_key:') === 0,
        'Invoices create must send HMAC Authorization header.'
    );
    assertSameValue(
        '{"project_id":"prj_123","amount":"10.00","currency":"USDT","network":"TRC20","external_order_id":"order-123"}',
        $transport->requests[0]['body'],
        'Invoices create must keep money amounts as JSON strings.'
    );
}
