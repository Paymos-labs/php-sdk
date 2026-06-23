# Paymos PHP SDK — stablecoin payments client for PHP

Official PHP SDK for the Paymos Merchant API. Accept USDT (11 chains: Tron, Ethereum, BSC, Polygon, Arbitrum, Optimism, TON, Avalanche, Solana, NEAR, Plasma) and USDC (10 chains: Ethereum, BSC, Polygon, Arbitrum, Optimism, Base, Avalanche, Solana, NEAR, Sui) — native settlement, no auto-conversion.

This is the same SDK the [WooCommerce](https://github.com/paymos-labs/woocommerce), [WHMCS](https://github.com/paymos-labs/whmcs), and [OpenCart](https://github.com/paymos-labs/opencart) plugins use under the hood. Drop it into a custom PHP backend and you get the same HMAC signing, webhook verification, and retry logic the official plugins ship.

- Documentation: [paymos.io/docs/quick-start](https://paymos.io/docs/quick-start)
- API reference: [paymos.io/dashboard/developers/api](https://paymos.io/dashboard/developers/api)
- Webhooks: [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks)

[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue)](LICENSE)

---

## What this is

A thin, dependency-free client for the public Paymos Merchant API (HMAC-SHA256 authentication, snake_case JSON, webhook signature verification).

- PHP 7.4 / 8.x compatible
- No Composer runtime dependencies (uses ext-curl, ext-hash, ext-json)
- Pluggable transport (cURL by default, mock for tests)
- Built-in retry with exponential backoff and `Retry-After` support (429 on any method; 5xx only on idempotent methods)
- Webhook signature verification with secret-rotation support, Stripe-style multi-signature grace period

---

## Installation

```bash
composer require paymos/php-sdk
```

Or vendor the `src/` directory directly into a plugin (e.g. WooCommerce,
OpenCart) and register `Paymos\` -> `src/` with your autoloader.

---

## Quick start

### 1. Get your credentials

In the Paymos dashboard go to **Developers -> API Keys**
(`/dashboard/developers/api`) and create an API credential. You will
receive two strings:

| Field        | Format                                      | Notes                                |
| ------------ | ------------------------------------------- | ------------------------------------ |
| **API Key**  | `pk_test_...` / `pk_live_...` (Payment)     | Sent in the `Authorization` header   |
|              | `rk_test_...` / `rk_live_...` (Payout)      |                                      |
| **API Secret** | `sk_test_...` / `sk_live_...`             | Used to compute the HMAC signature.  |
|              |                                             | Never sent over the wire.            |

The `_test_` / `_live_` segment identifies the environment - there is no
separate `X-Environment` header. Sandbox-only endpoints under
`/v1/sandbox/...` reject `_live_` keys with HTTP 403.

### 2. Bootstrap the client

```php
use Paymos\Client;
use Paymos\ClientConfig;

$client = new Client(new ClientConfig(
    'pk_test_REPLACE_WITH_YOUR_KEY',     // API Key
    'sk_test_REPLACE_WITH_YOUR_SECRET',  // API Secret
    'https://api.paymos.io',             // Base URL (omit for default)
    30                                   // Request timeout (seconds)
));
```

### 3. First request

```php
$balances = $client->balances()->get();
foreach ($balances as $b) {
    echo $b['currency'] . ' / ' . $b['network'] . ': ' . $b['available'] . PHP_EOL;
}
```

---

## Invoices

### Create a fiat-denominated invoice

The customer pays the displayed crypto amount (the network is selected
on the hosted invoice page if not pre-locked):

```php
use Paymos\IdempotencyKey;

$invoice = $client->invoices()->create(array(
    'project_id'         => 'prj_xxxxxxxxxxxx',
    'amount'             => '49.95',
    'currency'           => 'USD',                // fiat -> network unlocked
    'external_order_id'  => IdempotencyKey::externalOrderId('order'),
    // optional:
    // 'allow_multiple_payments' => false,
    // 'customer_fee_percent'    => 0,            // 0..100
    // 'client_id'               => 'cust_42',
));

echo $invoice['payment_url'];   // hosted invoice page
echo $invoice['invoice_id'];    // "inv_..."
```

### Create a crypto-locked invoice

Pre-select both currency and network:

```php
$invoice = $client->invoices()->create(array(
    'project_id'        => 'prj_xxxxxxxxxxxx',
    'amount'            => '10.00',
    'currency'          => 'USDT',
    'network'           => 'tron',                // network locked
    'external_order_id' => 'order-7f3c',
));
```

### Get an invoice

```php
$invoice = $client->invoices()->get('inv_xxxxxxxxxxxx');
$status  = $invoice['status'];                    // "awaiting_client" | "confirming" | "paid" | ...
$paid    = $invoice['payment']['paid'] ?? null;   // string decimal, or null
```

### Cancel an invoice

A non-empty reason (max 500 chars) is **required** by the server:

```php
$client->invoices()->cancel('inv_xxxxxxxxxxxx', 'customer abandoned checkout');
```

### Sandbox: simulate a payment

In sandbox you can drive an invoice to a terminal state without any real
on-chain activity. This call requires a `pk_test_...` / `rk_test_...` key.
`simulatePayment` takes a **stage string** — the server computes the amount:

| Stage        | Result                                          |
| ------------ | ----------------------------------------------- |
| `'paid'`     | invoice fully paid (`invoice.paid`)             |
| `'overpaid'` | invoice paid above the requested amount (`invoice.paid_over`) |
| `'underpay'` | partial payment, then final underpayment (`invoice.underpaid`) |
| `'cancel'`   | invoice cancelled (`invoice.cancelled`)         |

```php
$client->invoices()->simulatePayment('inv_xxxxxxxxxxxx', 'paid');
```

---

## Withdrawals

### Create a withdrawal

```php
$wd = $client->withdrawals()->create(array(
    'destination_address' => 'TRX...whitelisted...address',
    'network'             => 'tron',
    'currency'            => 'USDT',
    'amount'              => '50.00',
    'external_order_id'   => 'payout_2026_05_01_001',
));
echo $wd['withdrawal_id'];  // "wdr_..."
```

The destination must already be on the merchant's whitelist
(`/dashboard/whitelist`) - the server returns `403 whitelist_required`
otherwise.

### Get / cancel / simulate

```php
$wd = $client->withdrawals()->get('wdr_xxxxxxxxxxxx');

$client->withdrawals()->cancel('wdr_xxxxxxxxxxxx', 'merchant requested');

// Sandbox only:
$client->withdrawals()->simulateCompletion('wdr_xxxxxxxxxxxx');
```

---

## Idempotency

Both `invoices.create` and `withdrawals.create` use the request's
`external_order_id` as the idempotency key. Calling the same endpoint
again with the same `external_order_id` returns the existing resource
instead of creating a duplicate. Use `IdempotencyKey::externalOrderId('prefix')`
to mint a UUID-v4 backed key:

```php
use Paymos\IdempotencyKey;

$key = IdempotencyKey::externalOrderId('wc');   // "wc_550e8400-e29b-41d4-a716-446655440000"
```

---

## Webhooks

Webhooks are configured at **Developers -> Webhooks**
(`/dashboard/developers/webhooks`). The dashboard generates a
`whsec_...` secret, supports rotation with a grace period, and shows
a delivery log + manual replay for each event.

### Wire format

The server delivers each event as:

```
POST <your-url>
Content-Type: application/json
X-Webhook-Signature: t=<unix-seconds>,v1=<hex-hmac>[,v1=<hex-hmac-prev>]

{
  "event_id":   "evt_...",
  "event_type": "invoice.paid",
  "version":    1,
  "occurred_at": 1709000000,
  "data":       { ... InvoiceStatusContract ... }
}
```

Multiple `v1=` entries appear during the secret-rotation grace period
(Stripe pattern). The SDK accepts the message if any of them validates.

### Verify and process

```php
use Paymos\Webhook\InMemoryEventStore;
use Paymos\Webhook\WebhookEventProcessor;
use Paymos\Webhook\WebhookVerifier;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;

$verifier  = new WebhookVerifier('whsec_xxxxxxxxxxxx', 300);
$processor = new WebhookEventProcessor($verifier, new InMemoryEventStore());

$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$rawBody   = file_get_contents('php://input');

try {
    $event = $processor->process($signature, $rawBody);
    // $event === ['event_id' => '...', 'event_type' => '...', 'data' => [...], ...]
} catch (DuplicateEventException $e) {
    http_response_code(200);  // already processed - ack
    exit;
} catch (SignatureMismatchException $e) {
    http_response_code(401);
    exit;
} catch (TimestampSkewException $e) {
    http_response_code(401);
    exit;
}

// Map the event to a precise business action and update your order/payout state.
use Paymos\Plugin\StatusMapper;

if (strpos($event['event_type'], 'invoice.') === 0) {
    $action = StatusMapper::invoiceAction($event['event_type']);

    switch ($action) {
        case StatusMapper::ACTION_CONFIRMING:
            // On-chain transfer detected, waiting for confirmations.
            break;
        case StatusMapper::ACTION_AWAITING_PAYMENT:
            // Partial payment received, waiting for the rest.
            break;
        case StatusMapper::ACTION_PAYMENT_COMPLETE:
            // Terminal: invoice paid (or paid_over) - mark order paid, fulfil.
            break;
        case StatusMapper::ACTION_FAIL_ORDER:
            // Terminal: invoice underpaid past deadline.
            break;
        case StatusMapper::ACTION_CANCEL_ORDER:
            // Terminal: invoice expired or cancelled.
            break;
        case StatusMapper::ACTION_IGNORE:
            // Unrecognized / future invoice event - no state change.
            break;
    }
} else {
    $action = StatusMapper::withdrawalAction($event['event_type']);

    switch ($action) {
        case StatusMapper::ACTION_PROCESSING:
            // Withdrawal broadcast on-chain.
            break;
        case StatusMapper::ACTION_COMPLETED:
            // Terminal success.
            break;
        case StatusMapper::ACTION_FAILED:
            // Terminal failure - reversed back to balance.
            break;
        case StatusMapper::ACTION_CANCELLED:
            // Cancelled before broadcast.
            break;
        case StatusMapper::ACTION_IGNORE:
            // Informational event (withdrawal.created).
            break;
    }
}

http_response_code(200);
```

### Replace `InMemoryEventStore` in production

`InMemoryEventStore` resets on every PHP request - it is only
useful inside one CLI process or for tests. In a real plugin (Laravel /
WordPress / Symfony) implement `EventStoreInterface` against your
database, Redis, or filesystem cache so `event_id` deduplication works
across requests.

```php
use Paymos\Webhook\EventStoreInterface;

final class WordPressEventStore implements EventStoreInterface
{
    public function remember($eventId, $ttlSeconds)
    {
        $key = 'paymos_evt_' . $eventId;
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, 1, (int) $ttlSeconds);
        return true;
    }
}
```

---

## StatusMapper

`Paymos\Plugin\StatusMapper` maps webhook event types to plugin-side
actions. It is a pure static helper and contains no I/O.

| Method                                          | Returns                                                    |
| ----------------------------------------------- | ---------------------------------------------------------- |
| `invoiceAction($eventType, $status = null)`     | `ACTION_CONFIRMING` / `ACTION_AWAITING_PAYMENT` / `ACTION_PAYMENT_COMPLETE` / `ACTION_FAIL_ORDER` / `ACTION_CANCEL_ORDER` / `ACTION_IGNORE` |
| `withdrawalAction($eventType, $status = null)`  | `ACTION_PROCESSING` / `ACTION_COMPLETED` / `ACTION_FAILED` / `ACTION_CANCELLED` / `ACTION_IGNORE` |
| `paymentAction($eventType, $status = null)`     | Legacy coarse mapper — collapses all mid-flight invoice events to `ACTION_PROCESSING`. Prefer `invoiceAction()` for new code. |

Pass `$eventType` from the webhook payload's `event_type` field. The
optional `$status` is a fallback for legacy callers that only have the
invoice/withdrawal status string.

---

## Error handling

Every non-2xx response raises `Paymos\Exception\ApiException` (or a
subclass). The server uses RFC 9457 "Problem Details" in two shapes.

A **single** error is flat — `code`/`field`/`detail` live at the top level,
read them with `errorCode()`, `field()` and `detail()`:

```json
{
  "type":   "https://paymos.io/docs/errors/codes#insufficient_balance",
  "title":  "Conflict",
  "status": 409,
  "detail": "Insufficient balance.",
  "code":   "insufficient_balance",
  "field":  null
}
```

**Multiple** validation errors add an `errors[]` breakdown — iterate
`errors()` (and `errorCode()`/`field()` return the first entry):

```json
{
  "status": 400,
  "title":  "Bad Request",
  "code":   "validation_failed",
  "errors": [
    { "code": "field_required", "field": "currency", "message": "Field is required." }
  ]
}
```

```php
use Paymos\Exception\ApiException;
use Paymos\Exception\ConflictException;
use Paymos\Exception\GoneException;
use Paymos\Exception\NotFoundException;
use Paymos\Exception\RateLimitException;
use Paymos\Exception\UnavailableException;
use Paymos\Exception\ValidationException;

try {
    $client->invoices()->create($payload);
} catch (ValidationException $e) {
    foreach ($e->errors() as $err) {
        // $err = ['code' => '...', 'field' => '...|null', 'message' => '...']
    }
} catch (NotFoundException $e) {
    // 404
} catch (ConflictException $e) {
    // 409 - e.g. insufficient_balance. $e->errorCode() / $e->detail() (flat envelope).
} catch (GoneException $e) {
    // 410 - resource is in a terminal state (cancel after Paid, etc.)
} catch (RateLimitException $e) {
    // 429 - SDK retries automatically (any method); surfaces only after RetryPolicy
    // is exhausted. $e->retryAfterSeconds() gives the server's Retry-After hint.
} catch (UnavailableException $e) {
    // 503 - upstream / transient. Retried only on idempotent methods (GET/HEAD);
    // a 503 on a POST surfaces immediately (it may already have taken effect).
} catch (ApiException $e) {
    // any other API error
}
```

The HTTP status -> exception class mapping (see
`Paymos\Exception\ApiException::fromResponse`):

| Status                | Class                  |
| --------------------- | ---------------------- |
| 400                   | `ValidationException`  |
| 401, 403              | `AuthException`        |
| 404                   | `NotFoundException`    |
| 409                   | `ConflictException`    |
| 410                   | `GoneException`        |
| 429                   | `RateLimitException`   |
| 503                   | `UnavailableException` |
| Other 5xx             | `ServerException`      |
| Anything else         | `ApiException`         |

### Retries

`RetryingTransport` retries with exponential backoff (default: 2 retries,
150 ms base), honoring the server's `Retry-After` header when it asks for
longer than the computed backoff. Retry safety is method-aware:

- **429** is retried for any method — rate limiting happens before the
  request is processed, so no side effect occurred.
- **5xx** is retried only for idempotent methods (`GET`/`HEAD`/`OPTIONS`).
  A 5xx on a non-idempotent `POST` (cancel / simulate) is **not** retried —
  it may already have taken effect server-side. Invoice/withdrawal creation
  is additionally idempotency-keyed by `external_order_id`.

Override by constructing the client with a custom transport:

```php
use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\CurlTransport;
use Paymos\Http\RetryPolicy;
use Paymos\Http\RetryingTransport;

$client = new Client(
    new ClientConfig('pk_test_...', 'sk_test_...'),
    new RetryingTransport(new CurlTransport(), new RetryPolicy(/* maxRetries */ 4, /* baseMs */ 250))
);
```

---

## How HMAC signing works

Every authenticated request carries two headers:

```
X-Request-Timestamp: <unix-seconds>
Authorization:       HMAC-SHA256 <apiKey>:<base64-signature>
```

The signed payload is:

```
<timestamp>\n<METHOD>\n<path>\n<query>\n<bodyHash>
```

where `bodyHash` is the lowercase hex of `sha256(body)` (or the empty
string for requests without a body), and the signature is
`base64(HMAC-SHA256(secret, payload))`.

Anti-replay: the server rejects requests whose timestamp is more than
five minutes off its own clock - keep the host clock NTP-synced.

The SDK does this for you in `Paymos\Http\RequestSigner` and
`Paymos\Resources\BaseResource::requestJson`. You should not need to
sign requests by hand, but the helpers are public so you can build
ad-hoc tooling against the same scheme.

---

## Testing

The SDK ships with a tiny xUnit-style runner. To run the test suite
against a clean PHP 7.4 image:

```bash
docker run --rm -v "$(pwd):/sdk" -w /sdk php:7.4-cli php tests/run.php
```

You can plug a `Paymos\Http\MockTransport` into the client to avoid
real HTTP in your own tests:

```php
use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\MockTransport;
use Paymos\Http\HttpResponse;

$transport = new MockTransport(array(
    new HttpResponse(200, '{"invoice_id":"inv_123","status":"awaiting_client"}', array()),
));
$client = new Client(new ClientConfig('pk_test_a', 'sk_test_b'), $transport);
$client->invoices()->get('inv_123');

print_r($transport->requests());  // captured method/url/headers/body
```

---

## Compatibility

| Component           | Version    |
| ------------------- | ---------- |
| PHP                 | 7.4 - 8.3+ |
| Required extensions | curl, hash, json |
| API surface         | `/v1/*`    |

The SDK uses no language features beyond PHP 7.4 syntax so it can be
vendored into legacy WooCommerce / OpenCart deployments without
changes.

---

## Support

- Documentation: [paymos.io/docs/quick-start](https://paymos.io/docs/quick-start)
- API reference: [paymos.io/dashboard/developers/api](https://paymos.io/dashboard/developers/api)
- Webhooks dashboard: [paymos.io/dashboard/developers/webhooks](https://paymos.io/dashboard/developers/webhooks)
- Authentication deep-dive: [paymos.io/docs/authentication](https://paymos.io/docs/authentication)
- Webhook verification: [paymos.io/docs/webhooks/verify](https://paymos.io/docs/webhooks/verify)
- Webhook retry schedule: [paymos.io/docs/webhooks/retry](https://paymos.io/docs/webhooks/retry)
- Error catalog: [paymos.io/docs/errors](https://paymos.io/docs/errors)
- Sandbox guide: [paymos.io/docs/testing](https://paymos.io/docs/testing)
- Status: [paymos.io/status](https://paymos.io/status)
- Issues: [github.com/paymos-labs/php-sdk/issues](https://github.com/paymos-labs/php-sdk/issues)
- Email: [support@paymos.io](mailto:support@paymos.io)

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) — or browse the public release history at [paymos.io/changelog](https://paymos.io/changelog).

---

## License

MIT — see [LICENSE](LICENSE).
