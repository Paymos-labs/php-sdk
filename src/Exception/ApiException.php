<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * Base for all Paymos API errors.
 *
 * Server error wire format (RFC 9457 "Problem Details"). Two shapes:
 *
 *   Single error (NotFound, Conflict, RateLimited, single-field validation, …)
 *   — flat, no errors[]:
 *   {
 *     "type":   "…/docs/errors/codes#insufficient_balance",
 *     "title":  "Conflict",
 *     "status": 409,
 *     "detail": "Insufficient balance.",
 *     "code":   "insufficient_balance",
 *     "field":  null
 *   }
 *
 *   Multiple validation errors — errors[] breakdown (RFC 9457 §3.1.6):
 *   {
 *     "title":  "Bad Request",
 *     "status": 400,
 *     "detail": "…",
 *     "code":   "validation_failed",
 *     "errors": [ { "code": "field_required", "field": "currency", "message": "…" } ]
 *   }
 *
 * `code` is the stable machine-readable identifier — switch on it for
 * programmatic handling and localization. For a single error it is top-level;
 * for multiple it is the envelope category and the per-field specifics live in
 * errors[]. `field` is null for non-field errors (NotFound, generic Conflict).
 */
class ApiException extends \RuntimeException
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $responseBody;

    /** @var array<int, array{code: string, field: ?string, message: string}> */
    private $errors = array();

    /** @var string|null */
    private $title;

    /** Top-level "code" (single-error envelope), or null. @var string|null */
    private $topCode;

    /** Top-level "field" (single-field validation), or null. @var string|null */
    private $topField;

    /** Top-level "detail" (human-readable description), or null. @var string|null */
    private $detail;

    /** Response headers (lowercased keys), or empty. @var array<string, string> */
    private $headers = array();

    public function __construct($statusCode, $responseBody, array $headers = array())
    {
        $this->statusCode = (int) $statusCode;
        $this->responseBody = (string) $responseBody;
        foreach ($headers as $name => $value) {
            $this->headers[strtolower((string) $name)] = (string) $value;
        }

        $decoded = json_decode($this->responseBody, true);
        if (is_array($decoded)) {
            if (isset($decoded['title']) && is_string($decoded['title'])) {
                $this->title = $decoded['title'];
            }
            // Top-level extension members — present on every RFC 9457 envelope,
            // and the ONLY place code/field/detail live for single-error
            // responses (no errors[]). Without reading these, errorCode()/
            // field() are empty for all 404/409/410/429/503 and single-field
            // 400s, and detail (e.g. "Insufficient balance.") is lost.
            if (isset($decoded['code']) && is_string($decoded['code'])) {
                $this->topCode = $decoded['code'];
            }
            if (isset($decoded['field']) && is_string($decoded['field'])) {
                $this->topField = $decoded['field'];
            }
            if (isset($decoded['detail']) && is_string($decoded['detail'])) {
                $this->detail = $decoded['detail'];
            }
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                foreach ($decoded['errors'] as $err) {
                    if (!is_array($err)) {
                        continue;
                    }
                    $this->errors[] = array(
                        'code' => isset($err['code']) ? (string) $err['code'] : '',
                        'field' => isset($err['field']) && is_string($err['field']) ? $err['field'] : null,
                        'message' => isset($err['message']) ? (string) $err['message'] : '',
                    );
                }
            }
        }

        parent::__construct(
            'Paymos API ' . $this->statusCode . ': ' . $this->summaryMessage(),
            $this->statusCode
        );
    }

    /**
     * Factory: returns the most specific exception subclass for the response code.
     *
     * Response headers are threaded through so callers can read rate-limit hints
     * after the retries are exhausted — e.g. RateLimitException::retryAfterSeconds()
     * reads the Retry-After header the server attached to a 429.
     *
     * @param int                   $statusCode
     * @param string                $responseBody
     * @param array<string, string> $headers
     */
    public static function fromResponse($statusCode, $responseBody, array $headers = array())
    {
        $code = (int) $statusCode;

        switch (true) {
            case $code === 400:
                return new ValidationException($code, $responseBody, $headers);
            case $code === 401 || $code === 403:
                return new AuthException($code, $responseBody, $headers);
            case $code === 404:
                return new NotFoundException($code, $responseBody, $headers);
            case $code === 409:
                return new ConflictException($code, $responseBody, $headers);
            case $code === 410:
                return new GoneException($code, $responseBody, $headers);
            case $code === 429:
                return new RateLimitException($code, $responseBody, $headers);
            case $code === 503:
                return new UnavailableException($code, $responseBody, $headers);
            case $code >= 500:
                return new ServerException($code, $responseBody, $headers);
            default:
                return new self($code, $responseBody, $headers);
        }
    }

    public function statusCode()
    {
        return $this->statusCode;
    }

    public function responseBody()
    {
        return $this->responseBody;
    }

    /**
     * Response headers (keys lowercased), or empty array. Useful for rate-limit
     * hints (Retry-After) that survive past the retry budget.
     *
     * @return array<string, string>
     */
    public function headers()
    {
        return $this->headers;
    }

    /**
     * A single response header by name (case-insensitive), or null if absent.
     *
     * @param string $name
     * @return string|null
     */
    public function header($name)
    {
        $key = strtolower((string) $name);

        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }

    /**
     * Title from server response (e.g. "Bad Request", "Conflict"), or null.
     *
     * @return string|null
     */
    public function title()
    {
        return $this->title;
    }

    /**
     * All structured error entries from the server response. Each is
     * ['code' => string, 'field' => ?string, 'message' => string].
     *
     * @return array<int, array{code: string, field: ?string, message: string}>
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * Short stable error code suitable for switching on (e.g. "insufficient_balance",
     * "field_required", "not_found"). Prefers the first errors[] entry (multi-error
     * envelope), then the top-level "code" (single-error envelope), then empty string.
     */
    public function errorCode()
    {
        if (isset($this->errors[0]) && $this->errors[0]['code'] !== '') {
            return $this->errors[0]['code'];
        }
        return $this->topCode !== null ? $this->topCode : '';
    }

    /**
     * Field name (snake_case) for the offending field. Prefers the first
     * errors[] entry, then the top-level "field" (single-field validation), or null.
     */
    public function field()
    {
        if (isset($this->errors[0])) {
            return $this->errors[0]['field'];
        }
        return $this->topField;
    }

    /**
     * Human-readable description from the server ("detail" member), or null.
     * Present on single-error envelopes (e.g. "Insufficient balance.").
     *
     * @return string|null
     */
    public function detail()
    {
        return $this->detail;
    }

    private function summaryMessage()
    {
        if (count($this->errors) === 0) {
            // Single-error envelope: prefer detail (specific, e.g. "Insufficient
            // balance."), then field-qualified code, then title, then raw body.
            if ($this->detail !== null && $this->detail !== '') {
                $msg = $this->detail;
                if ($this->topField !== null && $this->topField !== '') {
                    $msg = $this->topField . ': ' . $msg;
                }
                return $msg;
            }
            if ($this->topCode !== null && $this->topCode !== '') {
                return $this->topField !== null && $this->topField !== ''
                    ? $this->topField . ': ' . $this->topCode
                    : $this->topCode;
            }
            if ($this->title !== null) {
                return $this->title;
            }
            return $this->responseBody === '' ? '(empty response)' : $this->responseBody;
        }

        $first = $this->errors[0];
        $msg = $first['message'] !== '' ? $first['message'] : ($first['code'] !== '' ? $first['code'] : ($this->title ?? ''));
        if ($first['field'] !== null && $first['field'] !== '') {
            $msg = $first['field'] . ': ' . $msg;
        }
        if (count($this->errors) > 1) {
            $msg .= ' (and ' . (count($this->errors) - 1) . ' more)';
        }
        return $msg;
    }
}
