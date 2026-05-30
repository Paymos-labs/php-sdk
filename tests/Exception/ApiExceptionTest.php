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
