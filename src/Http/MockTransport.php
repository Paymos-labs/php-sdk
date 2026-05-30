<?php

declare(strict_types=1);

namespace Paymos\Http;

final class MockTransport implements TransportInterface
{
    /** @var HttpResponse[] */
    private $responses;

    /** @var array */
    private $requests = array();

    /**
     * @param HttpResponse[] $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = array_values($responses);
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        $this->requests[] = array(
            'method' => (string) $method,
            'url' => (string) $url,
            'headers' => $headers,
            'body' => (string) $body,
            'timeoutSeconds' => (int) $timeoutSeconds,
        );

        if (count($this->responses) === 0) {
            throw new \RuntimeException('MockTransport has no queued response for ' . $method . ' ' . $url);
        }

        return array_shift($this->responses);
    }

    /**
     * @return array
     */
    public function requests()
    {
        return $this->requests;
    }
}
