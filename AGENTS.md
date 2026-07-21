# AGENTS.md — Paymos PHP SDK

Zero-dependency PHP 7.4+ client for the Paymos Merchant API. Every CMS plugin builds on
this SDK; the crypto-critical paths (request signing, webhook verification, event dedup, status
mapping, amount guarding, reverse verification) live **here and only here**. If you are fixing one
of those in a plugin, you are fixing it in the wrong place.

## The Paymos wire contract (get these wrong and it silently breaks)

Base URL is `https://api.paymos.io`. Sandbox and live differ by credentials, not by host.

**Two different signature algorithms. Never share verification code between them.**

| | Canonical string | Encoding | Header |
|---|---|---|---|
| Outgoing API call | `{ts}\n{METHOD}\n{path}\n{query}\n{sha256hex(body)}` | **base64** | `Authorization: HMAC-SHA256 {keyId}:{sig}` |
| Incoming webhook | `{ts}.{rawBody}` | **hex** | `X-Webhook-Signature: t={ts},v1={sig}` |

- `{query}` is signed **with the leading `?`** (`?a=b`, not `a=b`); empty string when there is no query.
- `{sha256hex(body)}` is the empty string for an empty body — not the hash of `""`.
- The webhook header is `X-Webhook-Signature`. `X-Paymos-Signature` does not exist; if you see it, it is a bug.
- During secret rotation one header carries **two** `v1=` values. Try each; accept if any matches.
- Requests older than **5 minutes** are rejected. Verify webhook timestamps against the same window.
- Always verify the signature against the **raw request body**, before any JSON parse/re-encode.

**Exactly 8 invoice webhook events exist:**
`invoice.awaiting_payment`, `invoice.confirming`, `invoice.underpaid_waiting`, `invoice.paid`,
`invoice.paid_over`, `invoice.underpaid`, `invoice.expired`, `invoice.cancelled`.

**These do NOT exist. Do not handle, emit, or document them:** `invoice.created`,
`invoice.token_selected`, and the status `new`. Invoice creation fires no webhook. The buyer
choosing a token is `confirm-payment`, whose event is `invoice.confirming` (or none).

**9 invoice statuses:** `awaiting_client`, `awaiting_payment`, `confirming`, `underpaid_waiting`,
`paid`, `paid_over`, `underpaid`, `expired`, `cancelled`. Final: `paid`, `paid_over`,
`underpaid`, `expired`, `cancelled`.

**Other rules that bite:**

- **Amounts are decimal strings, never floats.** `"11.05"`. Compare as strings or with bcmath; a float round-trip loses money.
- **Invoice TTL is server-side config.** There is no lifetime/expiry request field. Never add a setting claiming to control it.
- `external_order_id` is the **idempotency key** — same value returns the original invoice (200 instead of 201).
- **Never send `merchant_id`.** The server derives it from `project_id`.
- Use a **Payment** API key. Payout keys require an IP allowlist and will fail here.
- **Reverse-verify before fulfilling.** After the signature passes on a terminal event, re-fetch the invoice from the API and re-check amount and status. Signature alone is not sufficient.
- Errors are RFC 9457 `application/problem+json`. Switch on the stable `code` field, never on `title`/`detail` text. Each error's `type` deep-links to https://paymos.io/docs/errors/codes.
- Only `awaiting_client` invoices can be cancelled; anything later returns 409 `invoice_cannot_be_cancelled`.

## Credentials

Credentials arrive through the one-time Connect flow and are stored encrypted by the host
platform. **Never** ask the merchant to paste an API secret into a settings field you add,
never log a secret or a raw webhook body, and never commit one. The published package contains
no credentials.

## Docs

Every page is available as raw Markdown — append `.md` to any URL, e.g.
https://paymos.io/docs/webhooks/verify.md. Machine-readable index: https://paymos.io/llms.txt.
Prefer these over fetching the rendered HTML.

## Verify your change

```bash
composer test        # php tests/run.php
composer analyse     # phpstan, level per phpstan.neon
composer check       # both
```

Run `composer check` before finishing. If it fails, fix it — do not report success.

## Using it

```php
$config = new \Paymos\ClientConfig($apiKey, $apiSecret);   // base URL defaults to https://api.paymos.io
$client = new \Paymos\Client($config);

$invoice = $client->invoices()->create([
    'project_id'        => $projectId,
    'amount'            => '49.90',      // string, never float
    'currency'          => 'USDT',
    'external_order_id' => (string) $orderId,   // idempotency key
]);
```

Resources: `invoices()` (`create`, `get`, `listPage`, `iterate`, `cancel`, `confirmPayment`,
`simulatePayment`), `withdrawals()`, `balances()`, `system()`.

Webhooks: `\Paymos\Webhook\WebhookVerifier` (`verify`, `assertValid`, `decodeVerifiedPayload`),
`MultiEnvironmentWebhookVerifier` when sandbox and live secrets coexist, and an
`EventStoreInterface` for dedup — `X-Webhook-Id` is stable across retries, so store it and drop
repeats.

Errors surface as typed exceptions under `\Paymos\Exception\` (`ValidationException`,
`AuthException`, `ConflictException`, `RateLimitException`, `SignatureMismatchException`,
`TimestampSkewException`, …), each carrying the envelope's `code`.

## Do not

- Do not reimplement signing, verification, dedup or status mapping in a plugin — call the SDK.
- Do not add a runtime dependency. This package is intentionally dependency-free so it can be vendored into CMS plugins.
- Do not change the wire behaviour without updating `conformance/` — the golden vectors are shared with the seven other language SDKs and are verified byte-for-byte across all of them.
