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

function test_retrying_transport_deadline_defers_long_retry_after_without_sleeping_or_retrying_early_bug1348()
{
    $now = 1000.0;
    $slept = array();
    $clock = static function () use (&$now) { return $now; };
    $sleeper = static function ($us) use (&$now, &$slept) {
        $slept[] = $us;
        $now += $us / 1000000.0;
    };

    $deadline = \Paymos\Http\RequestDeadline::fromNow(6.0, $clock, $sleeper);
    $attempts = 0;
    $timeouts = array();
    $wire = new class($attempts, $timeouts, $now) implements TransportInterface {
        public $attempts;
        public $timeouts;
        public $now;
        public function __construct(&$attempts, &$timeouts, &$now)
        {
            $this->attempts = &$attempts;
            $this->timeouts = &$timeouts;
            $this->now = &$now;
        }
        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            $this->attempts++;
            $this->timeouts[] = $timeoutSeconds;
            $this->now += 0.5;
            return new HttpResponse(429, '{"error":"rate_limited"}', array('Retry-After' => '30'));
        }
    };

    $retrying = new RetryingTransport($wire, new RetryPolicy(2, 150), $deadline);
    $response = $retrying->request('GET', 'https://api.paymos.io/v1/invoices/inv_1', array(), '', 4);

    assertSameValue(429, $response->statusCode(), 'Long Retry-After must return 429 to defer work.');
    assertSameValue(1, $attempts, 'Must not retry earlier than Retry-After when it exceeds callback deadline.');
    assertSameValue(array(), $slept, 'Must not sleep past callback deadline.');
    assertTrueValue(abs($timeouts[0] - 4.0) < 0.01, 'Attempt timeout capped at configured attempt budget.');
}

function test_retrying_transport_deadline_bounds_cumulative_multi_read_and_500_backoff_bug1348()
{
    $now = 2000.0;
    $slept = array();
    $clock = static function () use (&$now) { return $now; };
    $sleeper = static function ($us) use (&$now, &$slept) {
        $slept[] = $us;
        $now += $us / 1000000.0;
    };

    $deadline = \Paymos\Http\RequestDeadline::fromNow(6.0, $clock, $sleeper);
    $calls = array();
    $wire = new class($calls, $now) implements TransportInterface {
        public $calls;
        public $now;
        public function __construct(&$calls, &$now)
        {
            $this->calls = &$calls;
            $this->now = &$now;
        }
        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            $this->calls[] = array('url' => $url, 'timeout' => $timeoutSeconds);
            if (count($this->calls) === 1) {
                $this->now += 3.5;
                return new HttpResponse(200, '{"ok":true}', array());
            }
            if (count($this->calls) === 2) {
                $this->now += 2.0;
                return new HttpResponse(500, '{"error":"upstream"}', array());
            }
            $this->now += 0.2;
            return new HttpResponse(200, '{"recovered":true}', array());
        }
    };

    $retrying = new RetryingTransport($wire, new RetryPolicy(2, 150), $deadline);
    $first = $retrying->request('GET', 'https://api.paymos.io/v1/read-1', array(), '', 4);
    assertSameValue(200, $first->statusCode(), 'First read succeeds.');
    assertTrueValue(abs($calls[0]['timeout'] - 4.0) < 0.01, 'First read gets full attempt timeout.');

    $second = $retrying->request('GET', 'https://api.paymos.io/v1/read-2', array(), '', 4);
    assertSameValue(200, $second->statusCode(), 'Second read retries 500 within remaining shared budget.');
    assertTrueValue(abs($calls[1]['timeout'] - 2.5) < 0.01, 'Second read capped by shared remaining budget.');
    assertSameValue(array(150000), $slept, 'Backoff slept once using deadline sleeper.');
    assertTrueValue(abs($calls[2]['timeout'] - 0.35) < 0.01, 'Retry timeout capped by remaining budget after backoff.');

    // Exhausted budget fails fast before issuing a third read.
    $now += 0.2;
    $threw = false;
    try {
        $retrying->request('GET', 'https://api.paymos.io/v1/read-3', array(), '', 4);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assertTrueValue($threw, 'Exhausted deadline must throw before issuing another read.');
}

function test_retrying_transport_deadline_does_not_sleep_on_transport_error_without_margin_bug1348()
{
    $now = 3000.0;
    $slept = array();
    $clock = static function () use (&$now) { return $now; };
    $sleeper = static function ($us) use (&$now, &$slept) {
        $slept[] = $us;
        $now += $us / 1000000.0;
    };

    $deadline = \Paymos\Http\RequestDeadline::fromNow(1.0, $clock, $sleeper);
    $attempts = 0;
    $wire = new class($attempts, $now) implements TransportInterface {
        public $attempts;
        public $now;
        public function __construct(&$attempts, &$now)
        {
            $this->attempts = &$attempts;
            $this->now = &$now;
        }
        public function request($method, $url, array $headers, $body, $timeoutSeconds)
        {
            $this->attempts++;
            $this->now += 0.9; // Leaves 0.1s (< 150ms backoff + 250ms margin).
            throw new RuntimeException('slow get timed out');
        }
    };

    $retrying = new RetryingTransport($wire, new RetryPolicy(2, 150), $deadline);
    $message = '';
    try {
        $retrying->request('GET', 'https://api.paymos.io/v1/invoices/inv_slow', array(), '', 4);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }
    assertSameValue('slow get timed out', $message, 'Original transport error propagates.');
    assertSameValue(1, $attempts, 'Must not retry when backoff+margin exceeds remaining budget.');
    assertSameValue(array(), $slept, 'Must not sleep when retry cannot fit.');
}
