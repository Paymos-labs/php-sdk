<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\MockTransport;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Version;

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
        new HttpResponse(200, '{"invoice_id":"inv_123","status":"awaiting_client"}', array()),
        new HttpResponse(200, '{"invoice_id":"inv_123","status":"cancelled"}', array()),
        new HttpResponse(200, '{"invoice_id":"inv_123","status":"awaiting_payment"}', array()),
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
    $client->invoices()->confirmPayment('inv_123', 'USDT', 'tron');
    $client->invoices()->simulatePayment('inv_123', 'paid');

    $requests = $transport->requests();
    assertSameValue('GET', $requests[0]['method'], 'Invoice get must use GET.');
    assertSameValue('https://api.paymos.io/v1/invoices/inv_123', $requests[0]['url'], 'Invoice get must use public v1 path.');
    assertSameValue('POST', $requests[1]['method'], 'Invoice cancel must use POST.');
    assertSameValue('https://api.paymos.io/v1/invoices/inv_123/cancel', $requests[1]['url'], 'Invoice cancel must use public v1 path.');
    assertSameValue('{"reason":"merchant requested"}', $requests[1]['body'], 'Invoice cancel must encode the required reason in JSON body.');
    assertSameValue('https://api.paymos.io/v1/invoices/inv_123/confirm-payment', $requests[2]['url'], 'Invoice confirmPayment must use the signed v1 path.');
    assertSameValue('{"currency":"USDT","network":"tron"}', $requests[2]['body'], 'Invoice confirmPayment must send currency and network.');
    assertSameValue('POST', $requests[3]['method'], 'Invoice simulatePayment must use POST.');
    assertSameValue('https://api.paymos.io/v1/sandbox/invoices/inv_123/simulate-payment', $requests[3]['url'], 'Invoice simulatePayment must use the signed sandbox simulate-payment path.');
    assertSameValue('{"stage":"paid"}', $requests[3]['body'], 'Invoice simulatePayment must send the stage in the JSON body.');
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

function test_invoices_list_page_builds_and_signs_exact_rfc3986_query()
{
    $transport = new MockTransport(array(
        new HttpResponse(200, '{"items":[],"next_cursor":null}', array()),
    ));
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () { return 1709000000; }
    );

    $client->invoices()->listPage(array(
        'status' => array('paid', 'expired'),
        'limit' => 2,
        'external_order_id' => 'order 42',
        'created_from' => 1700000000,
    ));

    $request = $transport->requests()[0];
    assertSameValue(
        'https://api.paymos.io/v1/invoices?created_from=1700000000&external_order_id=order%2042&limit=2&status=expired&status=paid',
        $request['url'],
        'Invoice list must sort keys, use RFC 3986 encoding, and repeat status.'
    );
    assertSameValue(
        'HMAC-SHA256 pk_test_key:sjdm/Meto/idr1gO3R1ICvBYBP7PjQCgCPanRpYmEUs=',
        $request['headers']['Authorization'],
        'Invoice list must sign the exact query string appended to the URL.'
    );
}

function test_invoices_list_rejects_unknown_filter_locally()
{
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        new MockTransport(array()),
        static function () { return 1709000000; }
    );

    $threw = false;
    try {
        $client->invoices()->listPage(array('sort_by' => 'amount'));
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Invoice list must reject filters unsupported by the API contract.');

    $threw = false;
    try {
        $client->invoices()->listPage(array('limit' => array(10, 20)));
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Invoice list must reject repeated scalar filters locally.');
}

function test_invoices_resource_passes_products_and_decodes_order_products()
{
    assertTrueValue(
        preg_match('/^\d+\.\d+\.\d+$/', Version::VERSION) === 1
            && version_compare(Version::VERSION, '1.11.0', '>='),
        'PHP SDK Version::VERSION must be a SemVer release >= 1.11.0.'
    );

    $contract = json_decode((string) file_get_contents(__DIR__ . '/../../conformance/contract.json'), true);
    $single = $contract['vectors']['invoices']['create_single_product'];
    $multi = $contract['vectors']['invoices']['create_multi_product'];
    $legacy = $contract['vectors']['invoices']['legacy_without_products'];
    $nullProducts = $contract['vectors']['invoices']['null_products'];

    $transport = new MockTransport(array(
        new HttpResponse(201, (string) json_encode($single['response']), array()),
        new HttpResponse(201, (string) json_encode($multi['response']), array()),
        new HttpResponse(200, (string) json_encode($legacy['response']), array()),
        new HttpResponse(200, (string) json_encode($nullProducts['response']), array()),
    ));
    $client = new Client(
        new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.io', 30),
        $transport,
        static function () { return 1709000000; }
    );

    $singleInvoice = $client->invoices()->create($single['body']);
    $multiInvoice = $client->invoices()->create($multi['body']);
    $legacyInvoice = $client->invoices()->get('inv_legacy_1');
    $nullInvoice = $client->invoices()->get('inv_null_1');

    $requests = $transport->requests();
    assertSameValue(
        'paymos-php/' . Version::VERSION,
        $requests[0]['headers']['User-Agent'],
        'Requests must send User-Agent matching Version::VERSION.'
    );
    assertSameValue(
        $single['body'],
        json_decode($requests[0]['body'], true),
        'Single-item products[] payload must pass through unchanged.'
    );
    assertSameValue(
        $multi['body'],
        json_decode($requests[1]['body'], true),
        'Multi-item products[] payload must pass through unchanged without injecting optional keys.'
    );
    $decodedMultiBody = json_decode($requests[1]['body'], true);
    assertFalseValue(
        array_key_exists('description', $decodedMultiBody['products'][1]),
        'Minimal item must not gain a null description key.'
    );
    foreach (array_slice($requests, 0, 2) as $request) {
        $body = json_decode($request['body'], true);
        foreach ($body['products'] as $product) {
            assertFalseValue(array_key_exists('image_url', $product), 'Product requests must not contain the removed image_url key.');
        }
    }
    foreach (array($singleInvoice, $multiInvoice) as $invoice) {
        foreach ($invoice['order']['products'] as $product) {
            assertFalseValue(array_key_exists('image_url', $product), 'Product responses must not contain the removed image_url key.');
        }
    }

    assertSameValue(
        $single['response']['order']['products'],
        $singleInvoice['order']['products'],
        'Single-item order.products must decode cleanly.'
    );
    assertSameValue(
        $multi['response']['order']['products'],
        $multiInvoice['order']['products'],
        'Multi-item order.products must decode cleanly.'
    );
    assertFalseValue(
        array_key_exists('products', $legacyInvoice['order']),
        'Legacy invoice order without products must decode without products key.'
    );
    assertFalseValue(
        array_key_exists('products', $nullInvoice['order']),
        'Null-products invoice response must decode without products key.'
    );
}
