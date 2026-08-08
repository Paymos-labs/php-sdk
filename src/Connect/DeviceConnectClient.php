<?php

declare(strict_types=1);

namespace Paymos\Connect;

use Paymos\Http\CurlTransport;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\CredentialSet;

/**
 * OAuth device bootstrap for official CMS plugins. The OAuth token is not used
 * for Merchant API calls: poll() extracts the existing HMAC credentials and the
 * plugin stores them locally using its platform-specific encrypted store.
 */
final class DeviceConnectClient
{
    public const CLIENT_ID = 'paymos-cms';
    public const SCOPE = 'cms:bootstrap';

    /** @var string */
    private $connectBaseUrl;

    /** @var TransportInterface */
    private $transport;

    /** @var int */
    private $timeoutSeconds;

    public function __construct(
        $connectBaseUrl = 'https://app.paymos.io',
        ?TransportInterface $transport = null,
        $timeoutSeconds = 15
    ) {
        $this->connectBaseUrl = $this->normalizeConnectBaseUrl($connectBaseUrl);
        $this->transport = $transport === null ? new CurlTransport() : $transport;
        $this->timeoutSeconds = max(1, (int) $timeoutSeconds);
    }

    /**
     * @param string|null $returnUrl Admin page to send the merchant back to after approval.
     *                               Paymos honours it only when it shares an origin with the
     *                               approved store URL, so an unusable value is simply dropped.
     * @return array<string, mixed>
     */
    public function start($cms, $sourceUrl, $returnUrl = null)
    {
        $cms = trim((string) $cms);
        $sourceUrl = rtrim(trim((string) $sourceUrl), '/');
        if ($cms === '' || !$this->isHttpsUrl($sourceUrl)) {
            throw new \InvalidArgumentException('Paymos CMS and HTTPS store URL are required.');
        }

        $returnUrl = trim((string) $returnUrl);
        if ($returnUrl !== '' && !$this->isHttpsUrl($returnUrl)) {
            $returnUrl = '';
        }

        $response = $this->formRequest('/connect/cms/device', array(
            'client_id' => self::CLIENT_ID,
            'scope' => self::SCOPE,
        ));
        $payload = $this->decodeJson($response->body());
        if ($response->statusCode() < 200 || $response->statusCode() >= 300) {
            throw new \RuntimeException('Paymos connection could not be started.');
        }

        foreach (array('device_code', 'user_code', 'verification_uri_complete', 'expires_in') as $required) {
            if (!isset($payload[$required]) || !is_scalar($payload[$required])) {
                throw new \RuntimeException('Paymos returned an invalid device authorization response.');
            }
        }

        $verificationUrl = (string) $payload['verification_uri_complete'];
        $separator = strpos($verificationUrl, '?') === false ? '?' : '&';
        $verificationUrl .= $separator
            . 'cms=' . rawurlencode($cms)
            . '&source_url=' . rawurlencode($sourceUrl);
        if ($returnUrl !== '') {
            $verificationUrl .= '&return_url=' . rawurlencode($returnUrl);
        }

        return array(
            'device_code' => (string) $payload['device_code'],
            'user_code' => (string) $payload['user_code'],
            'verification_url' => $verificationUrl,
            'expires_in' => (int) $payload['expires_in'],
            'interval' => isset($payload['interval']) ? max(1, (int) $payload['interval']) : 5,
            'started_at' => time(),
            'cms' => $cms,
            'source_url' => $sourceUrl,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function poll($deviceCode)
    {
        $deviceCode = trim((string) $deviceCode);
        if ($deviceCode === '') {
            throw new \InvalidArgumentException('Paymos device code is required.');
        }

        $response = $this->formRequest('/connect/token', array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id' => self::CLIENT_ID,
        ));
        $payload = $this->decodeJson($response->body());

        if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
            if (!isset($payload['paymos_config']) || !is_array($payload['paymos_config'])) {
                throw new \RuntimeException('Paymos returned no CMS configuration.');
            }
            $config = $payload['paymos_config'];
            if (!isset($config['environments']) || !is_array($config['environments'])) {
                throw new \RuntimeException('Paymos returned an invalid CMS configuration.');
            }

            return array(
                'status' => 'connected',
                'plugin' => isset($config['plugin']) ? (string) $config['plugin'] : '',
                'source_url' => isset($config['source_url']) ? (string) $config['source_url'] : '',
                'credentials' => CredentialSet::normalize($config['environments']),
            );
        }

        $error = isset($payload['error']) && is_scalar($payload['error'])
            ? (string) $payload['error']
            : 'connection_failed';
        if (in_array($error, array('authorization_pending', 'slow_down', 'access_denied', 'expired_token'), true)) {
            return array('status' => $error);
        }

        // Pass the server's own reason through. Swallowing it turns an actionable
        // message — "Maximum 10 webhook endpoints per environment reached", say —
        // into "Paymos connection failed", and the merchant has nothing to act on.
        $description = isset($payload['error_description']) && is_scalar($payload['error_description'])
            ? trim((string) $payload['error_description'])
            : '';

        throw new \RuntimeException(
            $description === ''
                ? 'Paymos connection failed.'
                : 'Paymos connection failed: ' . $description
        );
    }

    private function formRequest($path, array $fields)
    {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        return $this->transport->request(
            'POST',
            $this->connectBaseUrl . $path,
            array(
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            $body,
            $this->timeoutSeconds
        );
    }

    private function decodeJson($body)
    {
        $payload = json_decode((string) $body, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Paymos returned invalid JSON.');
        }
        return $payload;
    }

    private function normalizeConnectBaseUrl($value)
    {
        $value = rtrim(trim((string) $value), '/');
        if (!$this->isHttpsUrl($value)) {
            throw new \InvalidArgumentException('Paymos Connect base URL must use HTTPS.');
        }
        $parts = parse_url($value);
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Paymos Connect base URL is invalid.');
        }
        return $value;
    }

    private function isHttpsUrl($value)
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);
        return is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && strtolower((string) $parts['scheme']) === 'https';
    }
}
