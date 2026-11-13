# Paymos PHP SDK — stablecoin payments client for PHP

Official PHP SDK for the Paymos Merchant API. Accept USDT (11 chains: Tron, Ethereum, BSC, Polygon, Arbitrum, Optimism, TON, Avalanche, Solana, NEAR, Plasma) and USDC (10 chains: Ethereum, BSC, Polygon, Arbitrum, Optimism, Base, Avalanche, Solana, NEAR, Sui) — native settlement, no auto-conversion.

Give each customer a static wallet — one permanent deposit address per network that never changes — and read every confirmed deposit from a resumable feed.

This is the same SDK the [WooCommerce](https://github.com/paymos-labs/woocommerce), [WHMCS](https://github.com/paymos-labs/whmcs), and [OpenCart](https://github.com/paymos-labs/opencart) plugins use under the hood. Drop it into a custom PHP backend and you get the same HMAC signing, webhook verification, and retry logic the official plugins ship.

- Documentation: [paymos.io/docs/server-sdks](https://paymos.io/docs/server-sdks)
- API credentials: [app.paymos.io/developers/api](https://app.paymos.io/developers/api)
- Webhooks dashboard: [app.paymos.io/developers/webhooks](https://app.paymos.io/developers/webhooks)

[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue)](LICENSE)

---

## What this is

A thin, dependency-free client for the public Paymos Merchant API (HMAC-SHA256 authentication, snake_case JSON, webhook signature verification).

- PHP 7.4 / 8.x compatible
- No Composer runtime dependencies (uses ext-curl, ext-hash, ext-json, ext-openssl)
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
(`/developers/api`) and create an API credential. You will
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
    echo $b['currency'] . ': ' . $b['available'] . PHP_EOL;
}
```

Each entry is one coin's balance totalled across every network — a
merchant's balance is network-agnostic; the network is chosen only at
withdrawal time.

---

## Invoices

### Create a fiat-denominated invoice

The customer pays the displayed crypto amount (the network is selected
on the hosted invoice page if not pre-locked):

```php
use Paymos\IdempotencyKey;

// Mint the key once per logical order and persist it BEFORE the first
// call; every retry of this order must reuse the same value.
$key = IdempotencyKey::externalOrderId('order');
$this->saveOrderKey($key);

$invoice = $client->invoices()->create(array(
    'project_id'         => 'prj_xxxxxxxxxxxx',
    'amount'             => '49.95',
    'currency'           => 'USD',                // fiat -> network unlocked
    'external_order_id'  => $key,
    // optional:
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

### List invoices

Fetch one page, or lazily follow the opaque cursor. Repeatable filters such as
`status` are passed as arrays; query keys are deterministically RFC3986-encoded
and the exact query string is covered by HMAC.

```php
$page = $client->invoices()->listPage(array(
    'project_id' => 'prj_xxxxxxxxxxxx',
    'status'     => array('paid', 'paid_over'),
    'limit'      => 50,
));

foreach ($client->invoices()->iterate(array('status' => array('paid')), 10) as $invoice) {
    echo $invoice['invoice_id'] . PHP_EOL;
}
```

The second argument to `iterate()` is a client-side maximum page count.

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
(`/balance`) - the server returns `403 whitelist_required`
otherwise.

### Get / cancel / simulate

```php
$wd = $client->withdrawals()->get('wdr_xxxxxxxxxxxx');

$client->withdrawals()->cancel('wdr_xxxxxxxxxxxx', 'merchant requested');

// Sandbox only:
$client->withdrawals()->simulateCompletion('wdr_xxxxxxxxxxxx');
```

### List withdrawals

```php
$page = $client->withdrawals()->listPage(array(
    'status' => array('created', 'pending_review'),
    'limit'  => 20,
));

foreach ($client->withdrawals()->iterate(array('status' => array('completed')), 10) as $withdrawal) {
    echo $withdrawal['withdrawal_id'] . PHP_EOL;
}
```

---

## Payment channels

A payment channel is one payer's own reusable set of deposit addresses — no
per-order invoice, no expiry. The payer keeps the address; every confirmed
deposit shows up in the feed.

### Create a channel and read its rails

```php
$channel = $client->paymentChannels()->create(array(
    'project_id'  => 'prj_xxxxxxxxxxxx',
    'external_id' => 'customer_42',        // your own stable id for this payer
));

foreach ($channel['networks'] as $rail) {
    if (!isset($rail['address'])) {
        continue;                          // this rail has not provisioned yet
    }

    echo $rail['network'] . ' ' . $rail['address'] . PHP_EOL;

    foreach ($rail['tokens'] as $token) {
        $minimum = isset($token['minimum_deposit']) ? $token['minimum_deposit'] : null;
        echo '  ' . $token['symbol'] . ' min: ' . ($minimum === null ? 'unavailable' : $minimum) . PHP_EOL;
    }
}
```

### Poll the deposit feed

```php
$feed = $client->paymentChannelDeposits()->readPage(array(
    'cursor' => $savedCursor,              // omit (or null) on the very first poll
    'limit'  => 100,
));

foreach ($feed['items'] as $deposit) {
    $pdo->beginTransaction();
    creditDeposit($pdo, $deposit);         // one credit per $deposit['id'], see below
    $pdo->commit();
}

$this->saveCursor($feed['next_cursor']);   // always present, even on an empty page
```

Four things that bite an integrator who guesses:

- **Repeating the same `external_id` returns the same channel.** The pair
  (`project_id`, `external_id`) is the idempotency key: a repeat answers `200`
  with the channel that already exists instead of `201` with a duplicate. Both
  responses are a channel, so you never inspect the status code — calling
  `create()` on every checkout is safe, not a duplicate and not an error.
- **A rail's `address` is absent until that rail finishes provisioning**, and
  never changes once it appears. Absent means "not yet", not "no address" —
  show the payer only the rails that already have one.
- **An absent `minimum_deposit` means "we cannot quote a minimum right now"**,
  not "there is no minimum". Treating absent as `0` is how a merchant accepts a
  deposit that lands below the live minimum and is never credited.
- **`next_cursor` is never empty** — not even on a page with no items. Store it
  and resume from it on the next poll. Do **not** loop until it is null the way
  `invoices()->iterate()` ends a list: the feed has no last page, so that loop
  either spins forever or, worse, stops on the first quiet page and leaves the
  merchant's reconciliation silently behind. `confirmed_from` is only the first
  poll's lower bound — after that the stored cursor is the resume mechanism.

`readPage()` deliberately has no `iterate()` companion for exactly that reason.
`$feed['blocked']` appears only when one position could not be rendered; it
carries `{deposit_id, reason}` and explains a stall rather than causing one, so
log it — without it, being stuck looks exactly like a quiet day.

### Block, unblock, simulate

```php
$client->paymentChannels()->block('pc_xxxxxxxxxxxx');    // returns the updated channel
$client->paymentChannels()->unblock('pc_xxxxxxxxxxxx');

// Sandbox only ('network' is required; stage defaults to 'confirmed'):
$client->paymentChannels()->simulateDeposit('pc_xxxxxxxxxxxx', array(
    'amount'   => '25.00',
    'currency' => 'USDT',
    'network'  => 'TRC20',
));
```

---

## Idempotency

`invoices.create` and `withdrawals.create` use the request's
`external_order_id` as the idempotency key; `paymentChannels.create` uses
(`project_id`, `external_id`) the same way. Calling the same endpoint
again with the same `external_order_id` returns the existing resource
instead of creating a duplicate. Use `IdempotencyKey::externalOrderId('prefix')`
to mint a UUID-v4 backed key:

```php
use Paymos\IdempotencyKey;

$key = IdempotencyKey::externalOrderId('wc');   // "wc_550e8400-e29b-41d4-a716-446655440000"
```

The SDK never retries these POSTs for you, so an unknown outcome — timeout, dropped
connection, crash after send — is resolved by calling again with the **same**
`external_order_id`. Mint the key once per logical order, persist it before the first
call, and reuse it for that order's lifetime. Generating a fresh key for a rerun of the
same order bypasses idempotency and creates a second operation — for withdrawals, a
second payout.

---

## Webhooks

Webhooks are configured at **Developers -> Webhooks**
(`/developers/webhooks`). The dashboard generates a
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
use Paymos\Exception\EventInProgressException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;

$verifier  = new WebhookVerifier('whsec_xxxxxxxxxxxx', 300);
// InMemoryEventStore keeps nothing between requests; see the next section for a
// durable store and for where the order update and the marker commit.
$processor = new WebhookEventProcessor($verifier, new InMemoryEventStore());

$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$rawBody   = file_get_contents('php://input');

try {
    $event = $processor->process($signature, $rawBody);
    // $event === ['event_id' => '...', 'event_type' => '...', 'data' => [...], ...]
} catch (DuplicateEventException $e) {
    http_response_code(200);  // already processed - ack
    exit;
} catch (EventInProgressException $e) {
    http_response_code(409);  // another delivery is still processing it - Paymos retries
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
WordPress / Symfony) implement `CommitAwareEventStoreInterface` against your
database so `event_id` deduplication works across requests.

`WebhookEventProcessor::process()` calls `remember()` **before** your code
applies the event, so `remember()` is a claim, not a record of success. The
store has to keep the two apart:

- the claim is atomic - of two concurrent deliveries of one event exactly one
  gets `true` (a unique key does this; a read followed by a write does not);
- the event is marked committed in the same database transaction as the order
  or balance change, and only when that change succeeded;
- a failed attempt releases its claim, so the retry Paymos sends is processed.

`isCommitted()` lets the processor answer a committed event with
`DuplicateEventException` (answer 2xx) and a claimed but unfinished one with
`EventInProgressException` (answer non-2xx, e.g. 409). Paymos gives each
delivery 10 seconds and retries; acknowledging a retry that lands while the
first attempt is still running would mark the event delivered even if that
attempt then fails.

A PDO store (PostgreSQL syntax):

```php
use Paymos\Webhook\CommitAwareEventStoreInterface;

// CREATE TABLE paymos_webhook_events (
//     event_id   text    PRIMARY KEY,
//     committed  boolean NOT NULL DEFAULT false,
//     claimed_at bigint  NOT NULL
// );
// Delete committed rows older than the deduplication TTL (7 days by default)
// from a periodic job.
final class PdoEventStore implements CommitAwareEventStoreInterface
{
    // Longer than one delivery attempt. A claim older than this was left by a
    // crashed attempt and may be taken over by the next delivery.
    const CLAIM_SECONDS = 60;

    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function remember($eventId, $ttlSeconds)
    {
        $now = time();
        $claim = $this->pdo->prepare(
            'INSERT INTO paymos_webhook_events (event_id, committed, claimed_at) VALUES (?, false, ?)
             ON CONFLICT (event_id) DO UPDATE SET claimed_at = EXCLUDED.claimed_at
             WHERE paymos_webhook_events.committed = false
               AND paymos_webhook_events.claimed_at < ?'
        );
        $claim->execute(array($eventId, $now, $now - self::CLAIM_SECONDS));

        return $claim->rowCount() === 1;
    }

    public function isCommitted($eventId)
    {
        $read = $this->pdo->prepare('SELECT committed FROM paymos_webhook_events WHERE event_id = ?');
        $read->execute(array($eventId));

        return (bool) $read->fetchColumn();
    }

    // Call inside the transaction that applies the event.
    public function commit($eventId)
    {
        $this->pdo->prepare('UPDATE paymos_webhook_events SET committed = true WHERE event_id = ?')
            ->execute(array($eventId));
    }

    // Call after that transaction was rolled back.
    public function release($eventId)
    {
        $this->pdo->prepare('DELETE FROM paymos_webhook_events WHERE event_id = ? AND committed = false')
            ->execute(array($eventId));
    }
}
```

The handler commits the marker together with the effect:

```php
$store     = new PdoEventStore($pdo);
$processor = new WebhookEventProcessor(new WebhookVerifier('whsec_xxxxxxxxxxxx', 300), $store);

// ... process() and the catch blocks from "Verify and process" above ...

$pdo->beginTransaction();
try {
    applyEvent($pdo, $event);             // your order / balance change, see below
    $store->commit($event['event_id']);   // same connection, same transaction
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    $store->release($event['event_id']);
    http_response_code(500);              // Paymos retries the delivery
    exit;
}

http_response_code(200);
```

`event_id` deduplicates **deliveries**, not payments. Paymos creates a separate
event, with its own `event_id`, for every webhook endpoint subscribed to the
event, and every later event about the same invoice or deposit has its own
`event_id` too. `applyEvent()` therefore keys the money on the business
identity: a guarded order transition for invoice events (only an unpaid order
for `$event['data']['order']['external_id']` moves to paid), and the deposit
`id` for deposit events (next section).

### Payment-channel deposit events

The three `payment_channel.deposit.*` events each carry the full deposit shape
(the same snake_case array `paymentChannelDeposits()->get(...)` already
returns) as `data`:

```php
$event = $processor->process($signature, $rawBody);

if ($event['event_type'] === 'payment_channel.deposit.confirmed' && $event['data']['is_final']) {
    creditDeposit($pdo, $event['data']); // inside the handler's transaction above
}
```

The feed and the deposit webhook report the same deposits, so both go through one
`creditDeposit` keyed by the deposit's own `id` (`pcd_...`), never by `event_id`
(`credited_deposits.deposit_id` is the primary key; PostgreSQL syntax):

```php
// Call inside a transaction: the webhook handler's, or one the feed poller opens.
function creditDeposit(\PDO $pdo, array $deposit)
{
    if (!$deposit['is_final'] || $deposit['project_id'] !== MY_PROJECT_ID || $deposit['is_test'] !== MY_IS_TEST) {
        return;
    }

    // A second delivery, a second endpoint's copy, or a re-read feed page
    // inserts nothing, and the balance is not touched again.
    $insert = $pdo->prepare(
        'INSERT INTO credited_deposits (deposit_id, payer, amount) VALUES (?, ?, ?::numeric)
         ON CONFLICT (deposit_id) DO NOTHING'
    );
    $insert->execute(array($deposit['id'], $deposit['payment_channel_external_id'], $deposit['net']));

    if ($insert->rowCount() === 1) {
        $pdo->prepare('UPDATE balances SET amount = amount + ?::numeric WHERE payer = ?')
            ->execute(array($deposit['net'], $deposit['payment_channel_external_id']));
    }
}
```

**`confirming` and `reorged` are advisory and may arrive out of order.** A
`confirming` can land after the `confirmed` for the same deposit, and a
`reorged` can be superseded by a later `confirmed`. Credit only on
`payment_channel.deposit.confirmed` with `is_final` true, and never let an
advisory event regress a deposit you already know is confirmed. Crediting on
`confirming` releases goods against money a reorg can still take back.

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
`errors()` for fields. `errorCode()` and `field()` always read the top-level
request error; they never promote the first field entry:

```json
{
  "type":   "about:blank",
  "status": 400,
  "title":  "Bad Request",
  "detail": "Validation failed.",
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
| 401                   | `AuthException`        |
| 403                   | `PermissionException`  |
| 404                   | `NotFoundException`    |
| 409                   | `ConflictException`    |
| 410                   | `GoneException`        |
| 429                   | `RateLimitException`   |
| 503                   | `UnavailableException` |
| Other 5xx             | `ServerException`      |
| Anything else         | `ApiException`         |

### 401 and 403 are different problems

A **401** means the credential is absent, malformed or unverifiable — fix the key or the signing
clock, and stop retrying.

A **403** means the credential was accepted and something else refused. It covers two very
different situations:

- **the key is wrong for this call** — `payout_key_required`, `payment_key_required`,
  `forbidden`;
- **the key is fine, the account or project state says no** — `whitelist_required`,
  `merchant_suspended`, `widget_inactive`, `terminal_not_enabled`, `not_sandbox`.

A customer entering a payout address that is not whitelisted yet is the second kind: nothing is
wrong with your credentials, and paging the on-call engineer about them is the wrong move. Always
switch on `errorCode()` before deciding what to do.

`PermissionException` extends `AuthException`, so a catch block written before this split keeps
catching 403.

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
| Required extensions | curl, hash, json, openssl |
| API surface         | `/v1/*`    |

The SDK uses no language features beyond PHP 7.4 syntax so it can be
vendored into legacy WooCommerce / OpenCart deployments without
changes.

---

## Support

- Documentation: [paymos.io/docs/quick-start](https://paymos.io/docs/quick-start)
- API credentials: [app.paymos.io/developers/api](https://app.paymos.io/developers/api)
- Webhooks dashboard: [app.paymos.io/developers/webhooks](https://app.paymos.io/developers/webhooks)
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
