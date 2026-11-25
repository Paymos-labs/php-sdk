<?php

declare(strict_types=1);

namespace Paymos\Http;

final class RetryingTransport implements TransportInterface
{
    /** @var TransportInterface */
    private $inner;

    /** @var RetryPolicy */
    private $policy;

    /** @var RequestDeadline|null */
    private $deadline;

    /**
     * @param TransportInterface   $inner
     * @param RetryPolicy          $policy
     * @param RequestDeadline|null $deadline Optional total budget across all attempts and reads (BUG-1348).
     */
    public function __construct(TransportInterface $inner, RetryPolicy $policy, $deadline = null)
    {
        if ($deadline !== null && !($deadline instanceof RequestDeadline)) {
            throw new \InvalidArgumentException('deadline must be an instance of RequestDeadline or null.');
        }

        $this->inner = $inner;
        $this->policy = $policy;
        $this->deadline = $deadline;
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        return $this->requestWithHeaderFactory($method, $url, static function () use ($headers) {
            return $headers;
        }, $body, $timeoutSeconds);
    }

    /** Rebuild timestamp-dependent authentication immediately before each physical attempt. */
    public function requestWithHeaderFactory($method, $url, callable $headerFactory, $body, $timeoutSeconds)
    {
        $attempt = 0;

        do {
            $effectiveTimeout = $timeoutSeconds;
            if ($this->deadline !== null) {
                $effectiveTimeout = $this->deadline->attemptTimeoutSeconds($timeoutSeconds);
                if ($effectiveTimeout <= 0.0) {
                    throw new \RuntimeException('Paymos request deadline exceeded.');
                }
            }

            $headers = $this->inner instanceof self ? null : $headerFactory();
            try {
                $response = $this->inner instanceof self
                    ? $this->inner->requestWithHeaderFactory($method, $url, $headerFactory, $body, $effectiveTimeout)
                    : $this->inner->request($method, $url, $headers, $body, $effectiveTimeout);
            } catch (\RuntimeException $error) {
                if (!$this->policy->shouldRetryTransportError($method)
                    || $attempt >= $this->policy->maxRetries()) {
                    throw $error;
                }

                $attempt++;
                $delay = $this->policy->delayMicroseconds($attempt);
                if ($this->deadline !== null) {
                    if (!$this->deadline->canSleepAndRetry($delay)) {
                        throw $error;
                    }
                    $this->deadline->sleepMicroseconds($delay);
                } elseif ($delay > 0) {
                    usleep($delay);
                }
                continue;
            }

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
            if ($this->deadline !== null) {
                // If Retry-After or backoff does not fit inside the remaining callback budget,
                // defer cleanly (return the retryable status) instead of sleeping past the
                // webhook deadline or retrying earlier than Retry-After allows (BUG-1348).
                if (!$this->deadline->canSleepAndRetry($delay)) {
                    return $response;
                }
                $this->deadline->sleepMicroseconds($delay);
            } elseif ($delay > 0) {
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
