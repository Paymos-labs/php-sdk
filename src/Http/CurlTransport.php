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

        $timeout = max(1, (int) $timeoutSeconds);

        curl_setopt_array($curl, array(
            CURLOPT_CUSTOMREQUEST => strtoupper((string) $method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            // Connect phase gets its own budget so a stalled TLS handshake can't
            // silently eat the entire request timeout.
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_HEADER => true,
            // Always verify TLS. This SDK targets legacy shared hosting where a
            // broken global cainfo can flip verification off by default — pin it on.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Refuse downgrades below TLS 1.2.
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            // Never follow redirects: a 3xx would replay the signed Authorization
            // header to a different path, breaking the signature and leaking the
            // credential. Treat a redirect as the response it is.
            CURLOPT_FOLLOWLOCATION => false,
            // Only ever speak HTTPS, even if a redirect or typo points elsewhere.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
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

