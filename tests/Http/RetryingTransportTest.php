<?php

declare(strict_types=1);

use Paymos\Http\HttpResponse;
use Paymos\Http\RetryPolicy;
use Paymos\Http\RetryingTransport;
use Paymos\Http\TransportInterface;

final class FlakyTransport implements TransportInterface
{
    /** @var int */
    public $calls = 0;

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->calls++;

        if ($this->calls === 1) {
            return new HttpResponse(429, '{"title":"Too Many Requests"}', array());
        }

        return new HttpResponse(200, '{"ok":true}', array());
    }
}

function test_retrying_transport_retries_429_and_returns_success()
{
    $flaky = new FlakyTransport();
    $transport = new RetryingTransport($flaky, new RetryPolicy(2, 0));

    $response = $transport->request('GET', 'https://api.paymos.io/v1/balances', array(), '', 30);

    assertSameValue(2, $flaky->calls, 'Retrying transport must retry 429 once.');
    assertSameValue(200, $response->statusCode(), 'Retrying transport must return the successful retry response.');
}
