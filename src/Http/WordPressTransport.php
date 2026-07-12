<?php

declare(strict_types=1);

namespace Paymos\Http;

/**
 * Safe WordPress HTTP API adapter shared by all official WordPress plugins.
 * Redirects are disabled so signed requests and device codes never cross hosts.
 */
final class WordPressTransport implements TransportInterface
{
    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        if (!function_exists('wp_safe_remote_request')) {
            throw new \RuntimeException('The WordPress HTTP API is unavailable.');
        }

        $response = wp_safe_remote_request($url, array(
            'method' => strtoupper((string) $method),
            'timeout' => max(1, (int) $timeoutSeconds),
            'redirection' => 0,
            'sslverify' => true,
            'blocking' => true,
            'headers' => $headers,
            'body' => $body,
            'data_format' => 'body',
            'reject_unsafe_urls' => true,
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException('Paymos API request failed.');
        }

        return new HttpResponse(
            (int) wp_remote_retrieve_response_code($response),
            (string) wp_remote_retrieve_body($response),
            self::headers($response)
        );
    }

    /** @return array<string, string> */
    private static function headers(array $response)
    {
        $source = wp_remote_retrieve_headers($response);
        if (is_object($source) && method_exists($source, 'getAll')) {
            $source = $source->getAll();
        }
        if (!is_array($source)) {
            return array();
        }

        $headers = array();
        foreach ($source as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            if (is_scalar($value)) {
                $headers[strtolower((string) $name)] = (string) $value;
            }
        }
        return $headers;
    }
}
