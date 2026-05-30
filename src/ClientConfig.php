<?php

declare(strict_types=1);

namespace Paymos;

/**
 * Configuration for the Paymos\Client.
 *
 * The credentials are issued at /dashboard/developers/api in the Paymos
 * dashboard. Wire format:
 *
 *   API Key       — pk_test_<base62>   (Payment, Sandbox)
 *                   pk_live_<base62>   (Payment, Production)
 *                   rk_test_<base62>   (Payout,  Sandbox)
 *                   rk_live_<base62>   (Payout,  Production)
 *
 *   API Secret    — sk_test_<base62>   (Sandbox)
 *                   sk_live_<base62>   (Production)
 *
 * The environment (sandbox vs production) is encoded in the key itself —
 * there is no separate header. Sandbox-only endpoints under /v1/sandbox/…
 * still require a *_test_* key and reject *_live_* keys with HTTP 403.
 */
final class ClientConfig
{
    /** @var string */
    private $apiKey;

    /** @var string */
    private $apiSecret;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeoutSeconds;

    public function __construct($apiKey, $apiSecret, $baseUrl = 'https://api.paymos.io', $timeoutSeconds = 30)
    {
        $this->apiKey = $this->requireNonEmptyString($apiKey, 'apiKey');
        $this->apiSecret = $this->requireNonEmptyString($apiSecret, 'apiSecret');
        $this->baseUrl = rtrim($this->requireNonEmptyString($baseUrl, 'baseUrl'), '/');
        $this->timeoutSeconds = (int) $timeoutSeconds;

        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be greater than zero.');
        }
    }

    /**
     * True when the configured API key targets the sandbox environment
     * (i.e. starts with `pk_test_` or `rk_test_`).
     */
    public function isSandbox()
    {
        return strncmp($this->apiKey, 'pk_test_', 8) === 0
            || strncmp($this->apiKey, 'rk_test_', 8) === 0;
    }

    public function apiKey()
    {
        return $this->apiKey;
    }

    public function apiSecret()
    {
        return $this->apiSecret;
    }

    public function baseUrl()
    {
        return $this->baseUrl;
    }

    public function timeoutSeconds()
    {
        return $this->timeoutSeconds;
    }

    private function requireNonEmptyString($value, $name)
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException($name . ' must be a non-empty string.');
        }

        return $value;
    }
}

