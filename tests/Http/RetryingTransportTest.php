<?php

declare(strict_types=1);

use Paymos\Http\HttpResponse;
use Paymos\Http\RetryPolicy;
use Paymos\Http\RetryingTransport;
use Paymos\Http\TransportInterface;

/**
 * Records every call and returns a scripted sequence of responses. The last
 * scripted response repeats once the script is exhausted, so a transport that
 * over-retries still terminates.
 */
final class ScriptedTransport implements TransportInterface
{
    /** @var HttpResponse[] */
    private $script;

    /** @var int */
    public $calls = 0;

    /** @var string[] */
    public $methods = array();

    /** @param HttpResponse[] $script */
    public function __construct(array $script)
    {
        $this->script = $script;
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $index = min($this->calls, count($this->script) - 1);
        $this->calls++;
        $this->methods[] = $method;

        return $this->script[$index];
    }
}

final class FailingThenSuccessTransport implements TransportInterface
{
    /** @var int */
    public $calls = 0;

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->calls++;
        if ($this->calls === 1) {
            throw new \RuntimeException('temporary transport failure');
        }

        return new HttpResponse(200, '{"ok":true}', array());
    }
}

function test_retrying_transport_retries_transport_failure_only_for_idempotent_methods()
{
    $get = new FailingThenSuccessTransport();
    $retrying = new RetryingTransport($get, new RetryPolicy(2, 0));
    $response = $retrying->request('GET', 'https://api.paymos.io/v1/balances', array(), '', 30);
    assertSameValue(2, $get->calls, 'GET transport failure must be retried.');
    assertSameValue(200, $response->statusCode(), 'GET transport retry must return success.');

    $post = new FailingThenSuccessTransport();
    try {
        $retrying = new RetryingTransport($post, new RetryPolicy(2, 0));
        $retrying->request('POST', 'https://api.paymos.io/v1/invoices', array(), '{}', 30);
    } catch (\RuntimeException $error) {
        assertSameValue(1, $post->calls, 'POST transport failure must not be retried.');
        return;
    }

    throw new \RuntimeException('POST transport failure did not surface.');
}

function test_retrying_transport_retries_429_and_returns_success()
{
    $transport = new ScriptedTransport(array(
        new HttpResponse(429, '{"title":"Too Many Requests"}', array()),
        new HttpResponse(200, '{"ok":true}', array()),
    ));
    $retrying = new RetryingTransport($transport, new RetryPolicy(2, 0));

    $response = $retrying->request('GET', 'https://api.paymos.io/v1/balances', array(), '', 30);

    assertSameValue(2, $transport->calls, 'Retrying transport must retry 429 once.');
    assertSameValue(200, $response->statusCode(), 'Retrying transport must return the successful retry response.');
}

function test_retrying_transport_retries_503_on_idempotent_get()
{
    $transport = new ScriptedTransport(array(
        new HttpResponse(503, '{"title":"Service Unavailable"}', array()),
        new HttpResponse(200, '{"ok":true}', array()),
    ));
    $retrying = new RetryingTransport($transport, new RetryPolicy(2, 0));

    $response = $retrying->request('GET', 'https://api.paymos.io/v1/invoices/inv_1', array(), '', 30);

    assertSameValue(2, $transport->calls, '503 on a GET must be retried (idempotent).');
    assertSameValue(200, $response->statusCode(), '503 GET retry must surface the success.');
}

function test_retrying_transport_does_not_retry_503_on_post()
{
    // A non-idempotent POST (cancel/simulate) may have already taken effect
    // server-side on a 503 — retrying could double-act, so it must NOT retry.
    $transport = new ScriptedTransport(array(
        new HttpResponse(503, '{"title":"Service Unavailable"}', array()),
        new HttpResponse(200, '{"ok":true}', array()),
    ));
    $retrying = new RetryingTransport($transport, new RetryPolicy(2, 0));

    $response = $retrying->request('POST', 'https://api.paymos.io/v1/invoices/inv_1/cancel', array(), '{}', 30);

    assertSameValue(1, $transport->calls, '503 on a POST must NOT be retried.');
    assertSameValue(503, $response->statusCode(), '503 POST must surface the original response.');
}

function test_retrying_transport_retries_429_on_post()
{
    // 429 happens before processing — no side effect — so a POST is safe to retry.
    $transport = new ScriptedTransport(array(
        new HttpResponse(429, '{"title":"Too Many Requests"}', array()),
        new HttpResponse(201, '{"id":"inv_1"}', array()),
    ));
    $retrying = new RetryingTransport($transport, new RetryPolicy(2, 0));

    $response = $retrying->request('POST', 'https://api.paymos.io/v1/invoices', array(), '{}', 30);

    assertSameValue(2, $transport->calls, '429 on a POST must be retried (no side effect).');
    assertSameValue(201, $response->statusCode(), '429 POST retry must surface the success.');
}

function test_retrying_transport_stops_after_max_retries()
{
    $transport = new ScriptedTransport(array(
        new HttpResponse(503, '{"title":"Service Unavailable"}', array()),
    ));
    $retrying = new RetryingTransport($transport, new RetryPolicy(2, 0));

    $response = $retrying->request('GET', 'https://api.paymos.io/v1/balances', array(), '', 30);

    assertSameValue(3, $transport->calls, 'Initial attempt + 2 retries = 3 calls, then give up.');
    assertSameValue(503, $response->statusCode(), 'Exhausted retries surface the last response.');
}

function test_retrying_transport_respects_retry_after_header()
{
    // base delay 0 → without Retry-After the retry is ~instant. With Retry-After: 1
    // the transport must wait ~1s before retrying. Measuring the wall clock proves
    // the header (not the backoff) drove the delay.
    $transport = new ScriptedTransport(array(
        new HttpResponse(429, '{"title":"Too Many Requests"}', array('Retry-After' => '1')),
        new HttpResponse(200, '{"ok":true}', array()),
    ));
    $retrying = new RetryingTransport($transport, new RetryPolicy(2, 0));

    $start = microtime(true);
    $response = $retrying->request('GET', 'https://api.paymos.io/v1/balances', array(), '', 30);
    $elapsed = microtime(true) - $start;

    assertSameValue(2, $transport->calls, 'Retry-After case must still retry.');
    assertSameValue(200, $response->statusCode(), 'Retry-After case must return the success.');
    assertTrueValue($elapsed >= 0.9, 'Retry-After: 1 must delay the retry ~1s (got ' . round($elapsed, 3) . 's).');
}

function test_retrying_transport_refreshes_hmac_for_every_physical_attempt()
{
    foreach (array('GET_503', 'GET_transport', 'POST_429', 'nested_GET_503') as $scenario) {
        $wire = new class($scenario) implements TransportInterface {
            public $requests = array();
            private $scenario;
            public function __construct($scenario) { $this->scenario = $scenario; }
            public function request($method, $url, array $headers, $body, $timeoutSeconds)
            {
                $this->requests[] = compact('method', 'url', 'headers', 'body');
                if (count($this->requests) === 1) {
                    if ($this->scenario === 'GET_transport') { throw new RuntimeException('read timeout'); }
                    return new HttpResponse($this->scenario === 'POST_429' ? 429 : 503, '{}', array());
                }
                return new HttpResponse(200, '{"invoice_id":"inv_test"}', array());
            }
        };
        $now = 1700000000;
        $retrying = new RetryingTransport($wire, new RetryPolicy(1, 0));
        if ($scenario === 'nested_GET_503') { $retrying = new RetryingTransport($retrying, new RetryPolicy(1, 0)); }
        $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test', 'sk_test'), $retrying,
            static function () use (&$now) { $current = $now; $now += 511; return $current; });
        if ($scenario === 'POST_429') {
            $client->invoices()->create(array('external_order_id' => 'stable-order', 'amount' => '10.25'));
        } else {
            $client->invoices()->get('inv_test');
        }
        assertSameValue(2, count($wire->requests), 'The failed physical attempt is retried.');
        assertSameValue($wire->requests[0]['body'], $wire->requests[1]['body'], 'Retry preserves exact body/idempotency key.');
        assertSameValue('1700000000', $wire->requests[0]['headers']['X-Request-Timestamp'], 'First timestamp.');
        assertSameValue('1700000511', $wire->requests[1]['headers']['X-Request-Timestamp'], 'Retry refreshes expired timestamp.');
        foreach ($wire->requests as $request) {
            $timestamp = $request['headers']['X-Request-Timestamp'];
            $payload = $timestamp . "\n" . $request['method'] . "\n" . parse_url($request['url'], PHP_URL_PATH)
                . "\n\n" . ($request['body'] === '' ? '' : hash('sha256', $request['body']));
            assertSameValue('HMAC-SHA256 pk_test:' . base64_encode(hash_hmac('sha256', $payload, 'sk_test', true)),
                $request['headers']['Authorization'], 'HMAC is calculated from the timestamp and exact physical request.');
        }
    }
}
