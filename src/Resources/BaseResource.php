<?php

declare(strict_types=1);

namespace Paymos\Resources;

use Paymos\ClientConfig;
use Paymos\Exception\ApiException;
use Paymos\Http\RequestSigner;
use Paymos\Http\TransportInterface;
use Paymos\Version;

abstract class BaseResource
{
    /** @var ClientConfig */
    private $config;

    /** @var TransportInterface */
    private $transport;

    /** @var callable */
    private $clock;

    public function __construct(ClientConfig $config, TransportInterface $transport, callable $clock)
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->clock = $clock;
    }

    /**
     * @param string $method
     * @param string $path
     * @param array|null $payload
     * @param string $query
     * @return array
     *
     * @throws ApiException
     */
    protected function requestJson($method, $path, ?array $payload = null, $query = '')
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Unable to encode Paymos request JSON.');
        }

        // The server signs ASP.NET Core's Request.QueryString.Value, which
        // includes the leading '?' (or is empty). Sign the exact same string —
        // signing the bare "a=b" while the server expects "?a=b" breaks the
        // signature on any request that carries query parameters.
        $signedQuery = $query === '' ? '' : '?' . ltrim((string) $query, '?');

        $timestamp = (string) call_user_func($this->clock);
        $headers = array(
            'Authorization' => RequestSigner::authorizationHeader(
                $this->config->apiKey(),
                $this->config->apiSecret(),
                $timestamp,
                $method,
                $path,
                $signedQuery,
                $body
            ),
            'X-Request-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'paymos-php/' . Version::VERSION,
        );

        $url = $this->config->baseUrl() . $path . $signedQuery;
        $response = $this->transport->request($method, $url, $headers, $body, $this->config->timeoutSeconds());

        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw ApiException::fromResponse(
                $response->statusCode(),
                $response->body(),
                $response->headers()
            );
        }

        if ($response->body() === '') {
            return array();
        }

        $decoded = json_decode($response->body(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Paymos API returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * Build the exact RFC 3986 query string that is appended to the URL and
     * covered by the HMAC signature. Keys are sorted so equivalent filter maps
     * produce identical requests; array values are emitted as repeated keys.
     *
     * @param array $filters
     * @param string[] $allowedKeys
     * @param string[] $repeatableKeys
     * @return string Query without the leading question mark.
     */
    protected function buildQuery(array $filters, array $allowedKeys, array $repeatableKeys = array())
    {
        $allowed = array_fill_keys($allowedKeys, true);
        $repeatable = array_fill_keys($repeatableKeys, true);
        foreach ($filters as $key => $_value) {
            if (!is_string($key) || !isset($allowed[$key])) {
                throw new \InvalidArgumentException('Unknown Paymos list filter: ' . (string) $key);
            }
        }

        ksort($filters, SORT_STRING);
        $parts = array();
        foreach ($filters as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value) && !isset($repeatable[$key])) {
                throw new \InvalidArgumentException('Paymos list filter must be supplied once: ' . $key);
            }

            $values = is_array($value) ? array_values($value) : array($value);
            if (count($values) === 0) {
                throw new \InvalidArgumentException('Paymos list filter cannot be empty: ' . $key);
            }

            if (isset($repeatable[$key])) {
                sort($values, SORT_STRING);
            }

            foreach ($values as $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new \InvalidArgumentException(
                        'Paymos list filter must contain only string or integer values: ' . $key
                    );
                }
                if ((string) $item === '') {
                    throw new \InvalidArgumentException('Paymos list filter cannot be empty: ' . $key);
                }

                $parts[] = rawurlencode($key) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $parts);
    }
}
