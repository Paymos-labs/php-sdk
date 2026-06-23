<?php

declare(strict_types=1);

use Paymos\Exception\ApiException;
use Paymos\Exception\AuthException;
use Paymos\Exception\ConflictException;
use Paymos\Exception\GoneException;
use Paymos\Exception\NotFoundException;
use Paymos\Exception\RateLimitException;
use Paymos\Exception\ServerException;
use Paymos\Exception\UnavailableException;
use Paymos\Exception\ValidationException;

function test_api_exception_parses_paymos_error_envelope()
{
    $body = '{"status":400,"title":"Bad Request","errors":[{"code":"field_required","field":"currency","message":"Field is required."}]}';
    $ex = ApiException::fromResponse(400, $body);

    assertTrueValue($ex instanceof ValidationException, '400 must yield ValidationException.');
    assertSameValue('field_required', $ex->errorCode(), 'errorCode() must return errors[0].code.');
    assertSameValue('currency', $ex->field(), 'field() must return errors[0].field.');
    assertSameValue('Bad Request', $ex->title(), 'title() must return server-supplied title.');
    assertSameValue(1, count($ex->errors()), 'errors() must contain one entry.');
    assertSameValue(400, $ex->statusCode(), 'statusCode() reflects HTTP status.');
}

function test_api_exception_factory_maps_status_codes_to_subclasses()
{
    $cases = array(
        array(401, 'AuthException'),
        array(403, 'AuthException'),
        array(404, 'NotFoundException'),
        array(409, 'ConflictException'),
        array(410, 'GoneException'),
        array(429, 'RateLimitException'),
        array(503, 'UnavailableException'),
        array(500, 'ServerException'),
        array(502, 'ServerException'),
    );

    foreach ($cases as list($code, $expectedClass)) {
        $ex = ApiException::fromResponse($code, '{"status":' . $code . ',"title":"x","errors":[]}');
        $fqn = 'Paymos\\Exception\\' . $expectedClass;
        assertTrueValue($ex instanceof $fqn, $code . ' must produce ' . $fqn . ' but got ' . get_class($ex));
        assertTrueValue($ex instanceof ApiException, $code . ' subclass must extend ApiException');
    }
}

function test_api_exception_falls_back_when_body_is_not_json()
{
    $ex = ApiException::fromResponse(500, '<html>Internal error</html>');
    assertTrueValue($ex instanceof ServerException, 'Non-JSON 5xx still classifies as ServerException.');
    assertSameValue('', $ex->errorCode(), 'No errors[] yields empty errorCode().');
    assertSameValue(null, $ex->title(), 'No title in body → null.');
    assertTrueValue(strpos($ex->getMessage(), 'Paymos API 500') === 0, 'Message starts with Paymos API <code>.');
}

function test_api_exception_handles_empty_body()
{
    $ex = ApiException::fromResponse(404, '');
    assertTrueValue($ex instanceof NotFoundException, '404 with empty body still yields NotFoundException.');
    assertSameValue('', $ex->errorCode(), 'Empty body → empty errorCode().');
}

function test_api_exception_parses_flat_single_error_envelope()
{
    // Single-error RFC 9457 envelope is FLAT — code/field/detail live at the top
    // level, there is no errors[]. errorCode()/detail() must read them; field()
    // is null for a non-field conflict like insufficient_balance.
    $body = '{"type":"https://paymos.io/docs/errors/codes#insufficient_balance",'
        . '"title":"Conflict","status":409,"detail":"Insufficient balance.",'
        . '"code":"insufficient_balance","field":null}';
    $ex = ApiException::fromResponse(409, $body);

    assertTrueValue($ex instanceof ConflictException, '409 must yield ConflictException.');
    assertSameValue('insufficient_balance', $ex->errorCode(), 'errorCode() must read the top-level code.');
    assertSameValue('Insufficient balance.', $ex->detail(), 'detail() must read the top-level detail.');
    assertSameValue(null, $ex->field(), 'field() must be null for a non-field conflict.');
    assertSameValue(0, count($ex->errors()), 'flat single-error envelope has no errors[].');
    assertTrueValue(strpos($ex->getMessage(), 'Insufficient balance.') !== false, 'message must surface the detail.');
}

function test_rate_limit_exception_reads_retry_after_delta_seconds()
{
    $ex = ApiException::fromResponse(
        429,
        '{"status":429,"title":"Too Many Requests","code":"rate_limited"}',
        array('Retry-After' => '7')
    );

    assertTrueValue($ex instanceof RateLimitException, '429 must yield RateLimitException.');
    assertSameValue('7', $ex->header('retry-after'), 'header() must read Retry-After case-insensitively.');
    assertSameValue(7, $ex->retryAfterSeconds(), 'retryAfterSeconds() must parse delta-seconds.');
}

function test_rate_limit_exception_reads_retry_after_http_date()
{
    // HTTP-date form: 30 seconds in the future relative to a fixed "now".
    $now = 1_700_000_000;
    $future = gmdate('D, d M Y H:i:s', $now + 30) . ' GMT';
    $ex = ApiException::fromResponse(429, '{}', array('retry-after' => $future));

    assertSameValue(30, $ex->retryAfterSeconds($now), 'retryAfterSeconds() must parse an HTTP-date into a delta.');
}

function test_rate_limit_exception_retry_after_absent_is_null()
{
    $ex = ApiException::fromResponse(429, '{}', array());

    assertSameValue(null, $ex->retryAfterSeconds(), 'no Retry-After header → null.');
}
