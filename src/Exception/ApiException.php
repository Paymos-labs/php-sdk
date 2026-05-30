<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * Base for all Paymos API errors.
 *
 * Server error wire format (RFC 7807-style):
 *
 *   {
 *     "status": 400,
 *     "title":  "Bad Request",
 *     "errors": [
 *       { "code": "field_required", "field": "currency", "message": "..." }
 *     ]
 *   }
 *
 * `errors[].code` is the stable machine-readable identifier — switch on it
 * for programmatic handling and localization. `field` is null for non-field
 * errors (NotFound, generic Conflict, etc).
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

    public function __construct($statusCode, $responseBody)
    {
        $this->statusCode = (int) $statusCode;
        $this->responseBody = (string) $responseBody;

        $decoded = json_decode($this->responseBody, true);
        if (is_array($decoded)) {
            if (isset($decoded['title']) && is_string($decoded['title'])) {
                $this->title = $decoded['title'];
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
     */
    public static function fromResponse($statusCode, $responseBody)
    {
        $code = (int) $statusCode;

        switch (true) {
            case $code === 400:
                return new ValidationException($code, $responseBody);
            case $code === 401 || $code === 403:
                return new AuthException($code, $responseBody);
            case $code === 404:
                return new NotFoundException($code, $responseBody);
            case $code === 409:
                return new ConflictException($code, $responseBody);
            case $code === 410:
                return new GoneException($code, $responseBody);
            case $code === 429:
                return new RateLimitException($code, $responseBody);
            case $code === 503:
                return new UnavailableException($code, $responseBody);
            case $code >= 500:
                return new ServerException($code, $responseBody);
            default:
                return new self($code, $responseBody);
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
     * "field_required", "not_found"). Returns the first error's code, or empty string
     * if no structured errors were returned.
     */
    public function errorCode()
    {
        return isset($this->errors[0]) ? $this->errors[0]['code'] : '';
    }

    /**
     * Field name (snake_case) for the first error entry, or null.
     */
    public function field()
    {
        return isset($this->errors[0]) ? $this->errors[0]['field'] : null;
    }

    private function summaryMessage()
    {
        if (count($this->errors) === 0) {
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
