<?php

declare(strict_types=1);

namespace Paymos\Exception;

/**
 * 400 Bad Request — validator or handler rejected the request payload.
 * Inspect $exception->errors() for per-field details (snake_case fields,
 * stable `code`s like "field_required", "field_invalid_format", etc).
 */
final class ValidationException extends ApiException
{
}
