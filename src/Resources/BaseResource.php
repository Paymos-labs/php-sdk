<?php

declare(strict_types=1);

namespace Paymos\Resources;

use Paymos\ClientConfig;
use Paymos\Exception\ApiException;
use Paymos\Http\RequestSigner;
use Paymos\Http\TransportInterface;

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
    protected function requestJson($method, $path, array $payload = null, $query = '')
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Unable to encode Paymos request JSON.');
        }

        $timestamp = (string) call_user_func($this->clock);
        $headers = array(
            'Authorization' => RequestSigner::authorizationHeader(
                $this->config->apiKey(),
                $this->config->apiSecret(),
                $timestamp,
                $method,
                $path,
                $query,
                $body
            ),
            'X-Request-Timestamp' => $timestamp,
            'Content-Type' => 'application/json',
        );

        $url = $this->config->baseUrl() . $path . ($query === '' ? '' : '?' . $query);
        $response = $this->transport->request($method, $url, $headers, $body, $this->config->timeoutSeconds());

        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw ApiException::fromResponse($response->statusCode(), $response->body());
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
}
