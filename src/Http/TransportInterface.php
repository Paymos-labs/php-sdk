<?php

declare(strict_types=1);

namespace Paymos\Http;

interface TransportInterface
{
    /**
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string $body
     * @param int $timeoutSeconds
     */
    public function request($method, $url, array $headers, $body, $timeoutSeconds);
}

