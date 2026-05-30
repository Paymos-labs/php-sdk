<?php

declare(strict_types=1);

namespace Paymos\Http;

final class CurlTransport implements TransportInterface
{
    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('The Paymos PHP SDK default transport requires ext-curl.');
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST => strtoupper((string) $method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $timeoutSeconds,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADER => true,
        ));

        if ($body !== '') {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($curl);
        if ($raw === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('Paymos HTTP error: ' . $error);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);

        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return new HttpResponse($statusCode, $responseBody, $this->parseHeaders($rawHeaders));
    }

    private function formatHeaders(array $headers)
    {
        $formatted = array();

        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        return $formatted;
    }

    private function parseHeaders($rawHeaders)
    {
        $headers = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $rawHeaders);

        foreach ($lines as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            list($name, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}

