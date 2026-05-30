<?php

declare(strict_types=1);

namespace Paymos\Exception;

/** 404 Not Found — invoice/withdrawal/project does not exist or is not visible to this caller. */
final class NotFoundException extends ApiException
{
}
