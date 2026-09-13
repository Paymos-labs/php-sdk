<?php

namespace Paymos\Exception;

/**
 * A 403: the credential was accepted, something else refused.
 *
 * Deliberately EXTENDS AuthException so existing catch blocks keep working, and deliberately its
 * own class because the remedy is usually not "fix the key": 403 carries wrong-key-type
 * (payout_key_required, payment_key_required, forbidden) next to plain business
 * state (whitelist_required, merchant_suspended, widget_inactive, terminal_not_enabled,
 * not_sandbox). An unwhitelisted payout address reported as an authentication failure sends the
 * on-call engineer to rotate perfectly good credentials. Switch on errorCode() for the remedy.
 */
final class PermissionException extends AuthException
{
}
