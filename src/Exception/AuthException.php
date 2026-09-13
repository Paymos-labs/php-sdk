<?php

declare(strict_types=1);

namespace Paymos\Exception;

// NOT final: PermissionException extends it so a 403 stays catchable as AuthException. Every other
// leaf here is final and should stay that way — this one carries a documented subtype.
class AuthException extends ApiException
{
}
