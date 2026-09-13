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
 * Top-level `code` is always the authoritative request-level identifier. For
 * validation failures, per-field specifics remain available in errors[].
 * `field` is null for non-field errors (NotFound, generic Conflict).
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

    /** @var string|null */
    private $type;

    /** @var int|null */
    private $problemStatus;

    /** Authoritative top-level request error code, or null. @var string|null */
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
        if ($this->isCanonicalProblem($decoded)) {
            $this->type = $decoded['type'];
            if (isset($decoded['title']) && is_string($decoded['title'])) {
                $this->title = $decoded['title'];
            }
            $this->problemStatus = $decoded['status'];
            // Top-level extension members are authoritative for the request.
            // errors[] remains a field-level breakdown only.
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
            case $code === 401:
                return new AuthException($code, $responseBody, $headers);
            case $code === 403:
                return new PermissionException($code, $responseBody, $headers);
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

    public function type()
    {
        return $this->type;
    }

    public function problemStatus()
    {
        return $this->problemStatus;
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
     * Stable request-level error code. Per-field errors remain available through errors().
     */
    public function errorCode()
    {
        return $this->topCode !== null ? $this->topCode : '';
    }

    /**
     * Request-level field from the top-level extension, or null.
     */
    public function field()
    {
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
        if ($this->detail !== null && $this->detail !== '') {
            return $this->detail;
        }
        if ($this->topCode !== null && $this->topCode !== '') {
            return $this->topCode;
        }
        if ($this->title !== null) {
            return $this->title;
        }
        return $this->responseBody === '' ? '(empty response)' : $this->responseBody;
    }

    private function isCanonicalProblem($value)
    {
        return is_array($value)
            && isset($value['type'], $value['title'], $value['status'], $value['detail'], $value['code'])
            && is_string($value['type'])
            && is_string($value['title'])
            && is_int($value['status'])
            && $value['status'] === $this->statusCode
            && is_string($value['detail'])
            && is_string($value['code']);
    }
}
