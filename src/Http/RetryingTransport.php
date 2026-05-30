<?php

declare(strict_types=1);

namespace Paymos\Http;

final class RetryingTransport implements TransportInterface
{
    /** @var TransportInterface */
    private $inner;

    /** @var RetryPolicy */
    private $policy;

    public function __construct(TransportInterface $inner, RetryPolicy $policy)
    {
        $this->inner = $inner;
        $this->policy = $policy;
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $attempt = 0;

        do {
            $response = $this->inner->request($method, $url, $headers, $body, $timeoutSeconds);

            if (!$this->policy->shouldRetry($response->statusCode()) || $attempt >= $this->policy->maxRetries()) {
                return $response;
            }

            $attempt++;
            $delay = $this->policy->delayMicroseconds($attempt);
            if ($delay > 0) {
                usleep($delay);
            }
        } while (true);
    }
}
