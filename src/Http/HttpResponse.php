<?php

declare(strict_types=1);

namespace Paymos\Http;

final class HttpResponse
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $body;

    /** @var array<string, string> */
    private $headers;

    public function __construct($statusCode, $body, array $headers)
    {
        $this->statusCode = (int) $statusCode;
        $this->body = (string) $body;
        $this->headers = $headers;
    }

    public function statusCode()
    {
        return $this->statusCode;
    }

    public function body()
    {
        return $this->body;
    }

    public function headers()
    {
        return $this->headers;
    }
}

