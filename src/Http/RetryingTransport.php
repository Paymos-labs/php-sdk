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

            // Only retry when it is SAFE for this method+status (429 always;
            // 5xx only for idempotent methods — a non-idempotent POST may have
            // already taken effect server-side).
            if (!$this->policy->shouldRetryMethod($method, $response->statusCode())
                || $attempt >= $this->policy->maxRetries()) {
                return $response;
            }

            $attempt++;
            $backoff = $this->policy->delayMicroseconds($attempt);

            // Respect the server's Retry-After if it asked for longer than our
            // backoff — hammering the API inside the window it told us to wait
            // just earns more 429s.
            $headersLower = $this->lowercaseKeys($response->headers());
            $retryAfter = isset($headersLower['retry-after'])
                ? $this->policy->retryAfterMicroseconds($headersLower['retry-after'])
                : null;

            $delay = $retryAfter !== null ? max($retryAfter, $backoff) : $backoff;
            if ($delay > 0) {
                usleep($delay);
            }
        } while (true);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function lowercaseKeys(array $headers)
    {
        $lower = array();
        foreach ($headers as $name => $value) {
            $lower[strtolower((string) $name)] = $value;
        }

        return $lower;
    }
}
