<?php

declare(strict_types=1);

namespace Paymos\Http;

final class RetryPolicy
{
    /** @var int */
    private $maxRetries;

    /** @var int */
    private $baseDelayMilliseconds;

    public function __construct($maxRetries, $baseDelayMilliseconds)
    {
        $this->maxRetries = max(0, (int) $maxRetries);
        $this->baseDelayMilliseconds = max(0, (int) $baseDelayMilliseconds);
    }

    public static function default()
    {
        return new self(2, 150);
    }

    public function maxRetries()
    {
        return $this->maxRetries;
    }

    public function delayMicroseconds($attempt)
    {
        if ($this->baseDelayMilliseconds <= 0) {
            return 0;
        }

        return (int) ($this->baseDelayMilliseconds * 1000 * pow(2, max(0, (int) $attempt - 1)));
    }

    public function shouldRetry($statusCode)
    {
        $statusCode = (int) $statusCode;

        return $statusCode === 429 || $statusCode >= 500;
    }
}
