<?php

declare(strict_types=1);

namespace Paymos\Exception;

/** 5xx — generic server-side failure. RetryingTransport already retries 5xx. */
final class ServerException extends ApiException
{
}
