<?php

declare(strict_types=1);

namespace Paymos\Plugin;

/**
 * Canonical credential schema shared by every official CMS plugin.
 */
final class CredentialSet
{
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /**
     * @param array<string, mixed> $source
     * @return array<string, array<string, string>>
     */
    public static function normalize(array $source)
    {
        $normalized = array();
        foreach (array('sandbox', 'live') as $environment) {
            if (!isset($source[$environment]) || !is_array($source[$environment])) {
                continue;
            }

            $values = $source[$environment];
            $hasValue = false;
            foreach (array('api_key', 'api_secret', 'project_id', 'webhook_secret', 'base_url') as $field) {
                if (self::scalar($values, $field) !== '') {
                    $hasValue = true;
                    break;
                }
            }
            if ($hasValue) {
                $normalized[$environment] = self::normalizeEnvironment($environment, $values);
            }
        }

        return $normalized;
    }

    /**
     * @param string $environment
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public static function normalizeEnvironment($environment, array $values)
    {
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \InvalidArgumentException('Paymos environment must be sandbox or live.');
        }

        $result = array(
            'base_url' => self::scalar($values, 'base_url'),
            'api_key' => self::scalar($values, 'api_key'),
            'api_secret' => self::scalar($values, 'api_secret'),
            'project_id' => self::scalar($values, 'project_id'),
            'webhook_secret' => self::scalar($values, 'webhook_secret'),
        );
        if ($result['base_url'] === '') {
            $result['base_url'] = self::DEFAULT_BASE_URL;
        }

        foreach (array('api_key', 'api_secret', 'project_id', 'webhook_secret') as $field) {
            if ($result[$field] === '') {
                throw new \InvalidArgumentException('Paymos credentials are incomplete.');
            }
            if (strlen($result[$field]) > 512 || preg_match('/[\x00-\x1F\x7F]/', $result[$field])) {
                throw new \InvalidArgumentException('Paymos credentials contain an invalid value.');
            }
        }

        $keyPrefix = $environment === 'sandbox' ? 'pk_test_' : 'pk_live_';
        $secretPrefix = $environment === 'sandbox' ? 'sk_test_' : 'sk_live_';
        if (strpos($result['api_key'], $keyPrefix) !== 0) {
            throw new \InvalidArgumentException('Paymos API key has the wrong environment.');
        }
        if (strpos($result['api_secret'], $secretPrefix) !== 0) {
            throw new \InvalidArgumentException('Paymos API secret has the wrong environment.');
        }
        if (strpos($result['project_id'], 'prj_') !== 0) {
            throw new \InvalidArgumentException('Paymos project ID is invalid.');
        }
        if (strpos($result['webhook_secret'], 'whsec_') !== 0) {
            throw new \InvalidArgumentException('Paymos webhook secret is invalid.');
        }
        if (!self::isAllowedBaseUrl($result['base_url'])) {
            throw new \InvalidArgumentException('Paymos API base URL is not trusted.');
        }

        return $result;
    }

    private static function isAllowedBaseUrl($value)
    {
        if (strlen($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        return $scheme === 'https'
            && in_array($host, array('api.paymos.io', 'api-dev.paymos.io'), true)
            && ($port === null || $port === 443);
    }

    private static function scalar(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key])
            ? trim((string) $source[$key])
            : '';
    }
}
