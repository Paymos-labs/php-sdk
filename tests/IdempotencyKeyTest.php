<?php

declare(strict_types=1);

use Paymos\IdempotencyKey;

function test_idempotency_key_generates_php74_safe_uuid_external_order_id()
{
    $key = IdempotencyKey::externalOrderId('wc');

    assertTrueValue(
        preg_match('/^wc_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key) === 1,
        'IdempotencyKey must generate a UUID v4 external order id with the requested prefix.'
    );
}
