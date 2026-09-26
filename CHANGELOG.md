# Changelog

All notable changes to the Paymos PHP SDK are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.4.4] - 2026-09-26

- docs(plugins): минимум OpenCart 4.0.2.0 во всех текстах; понятные строки SDK при заблокированной замене счёта
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку
- fix(woocommerce): BUG-163 второй счёт не выпускается, пока сервер держит первый открытым
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- fix(sdk-conformance): восемь копий контракта разошлись с каноном — 403 снова permission
- docs(sdk): quickstart mints the idempotency key once and persists it — a rerun must not create a second operation
- fix(sdk): HTTPS обязателен, а 403 перестал притворяться ошибкой ключа

- **`InvoiceReplacement::close()`.** Closes an order's invoice before a plugin cuts a replacement, so the old one cannot be paid beside the new one. It cancels the invoice (`POST /v1/invoices/{id}/cancel`, which the server accepts only in `awaiting_client`), and when that does not come back `cancelled` it reads the invoice and lets the replacement through only for `expired`, `cancelled` or `underpaid`. Paid, still payable, unreadable and 404 answers are not closed: the server answers 404 alike for a missing invoice and one the caller's credentials may not read. A final status the plugin already recorded from the server is trusted without a call. The client may be passed as a factory, called only when a request is needed. Returns `InvoiceReplacementResult` (`isClosed()`, `status()`, `reason()`, `summary()`); `InvoiceReplacementBlockedException` carries a blocked result with a buyer-safe message.
- **403 is no longer reported as an authentication failure.** `PermissionException` (extends `AuthException`, so existing catch blocks keep working) now carries every 403. `AuthException` is no longer `final` for that reason.
- **Base URL must use HTTPS.** Plain HTTP is accepted only against a loopback host, which is what a local integration test points at. The API secret signs every request, so a plaintext base URL put that credential on the wire.
- **`StatusMapper::isFinalStatus()`.** Answers whether an invoice status is one of the five the server never transitions out of (`paid`, `paid_over`, `underpaid`, `expired`, `cancelled`), so a plugin can recognise an event that arrives after a final one as an out-of-order redelivery.
- **`InvoiceRenewal::isRequired()`.** Tells a plugin when an order needs a new invoice instead of the one already cut: the invoice ended unpaid (`underpaid`, `expired`, `cancelled`) or nobody started it before its `expires_at`. A paid invoice never needs one. The server answers a repeated `external_order_id` with the existing invoice whatever its state, so without this check a returning buyer is sent to an expired checkout.
- **`InvoiceRenewal::isRequired()` allows for clock skew.** An unstarted invoice counts as past its deadline only once `expires_at` is `InvoiceRenewal::CLOCK_SKEW_SECONDS` (300, the webhook timestamp tolerance) behind the caller's clock: the deadline is the server's, and until it passes there the buyer can still pick a network and pay. The deadline is not fixed at creation — confirming a network moves it to `now + PaymentTtl` and sends no webhook — so an `expires_at` a plugin stored is a reason to read the invoice back from the server, never to replace it.
- **An event still being processed is no longer reported as a duplicate.** `remember()` answers false both for an event already committed and for one only locked by a delivery that has not finished, and both became `DuplicateEventException` — which plugins answer 200. Paymos gives a delivery 10 seconds and retries, so a retry landing while the first attempt was still running was acknowledged, and the event was lost if that attempt then failed. A store implementing the new `CommitAwareEventStoreInterface` (`isCommitted()`) now gets `EventInProgressException` for the second case, to be answered non-2xx.

## [1.4.3] - 2026-09-25

- docs(plugins): минимум OpenCart 4.0.2.0 во всех текстах; понятные строки SDK при заблокированной замене счёта
- fix(plugins): BUG-166 старый счёт закрывается на сервере до выпуска нового; открытый, оплаченный или 404 — в ручную проверку

- **`InvoiceReplacement::close()`.** Closes an order's invoice before a plugin cuts a replacement, so the old one cannot be paid beside the new one. It cancels the invoice (`POST /v1/invoices/{id}/cancel`, which the server accepts only in `awaiting_client`), and when that does not come back `cancelled` it reads the invoice and lets the replacement through only for `expired`, `cancelled` or `underpaid`. Paid, still payable, unreadable and 404 answers are not closed: the server answers 404 alike for a missing invoice and one the caller's credentials may not read. A final status the plugin already recorded from the server is trusted without a call. The client may be passed as a factory, called only when a request is needed. Returns `InvoiceReplacementResult` (`isClosed()`, `status()`, `reason()`, `summary()`); `InvoiceReplacementBlockedException` carries a blocked result with a buyer-safe message.
- **403 is no longer reported as an authentication failure.** `PermissionException` (extends `AuthException`, so existing catch blocks keep working) now carries every 403. `AuthException` is no longer `final` for that reason.
- **Base URL must use HTTPS.** Plain HTTP is accepted only against a loopback host, which is what a local integration test points at. The API secret signs every request, so a plaintext base URL put that credential on the wire.
- **`StatusMapper::isFinalStatus()`.** Answers whether an invoice status is one of the five the server never transitions out of (`paid`, `paid_over`, `underpaid`, `expired`, `cancelled`), so a plugin can recognise an event that arrives after a final one as an out-of-order redelivery.
- **`InvoiceRenewal::isRequired()`.** Tells a plugin when an order needs a new invoice instead of the one already cut: the invoice ended unpaid (`underpaid`, `expired`, `cancelled`) or nobody started it before its `expires_at`. A paid invoice never needs one. The server answers a repeated `external_order_id` with the existing invoice whatever its state, so without this check a returning buyer is sent to an expired checkout.
- **`InvoiceRenewal::isRequired()` allows for clock skew.** An unstarted invoice counts as past its deadline only once `expires_at` is `InvoiceRenewal::CLOCK_SKEW_SECONDS` (300, the webhook timestamp tolerance) behind the caller's clock: the deadline is the server's, and until it passes there the buyer can still pick a network and pay. The deadline is not fixed at creation — confirming a network moves it to `now + PaymentTtl` and sends no webhook — so an `expires_at` a plugin stored is a reason to read the invoice back from the server, never to replace it.
- **An event still being processed is no longer reported as a duplicate.** `remember()` answers false both for an event already committed and for one only locked by a delivery that has not finished, and both became `DuplicateEventException` — which plugins answer 200. Paymos gives a delivery 10 seconds and retries, so a retry landing while the first attempt was still running was acknowledged, and the event was lost if that attempt then failed. A store implementing the new `CommitAwareEventStoreInterface` (`isCommitted()`) now gets `EventInProgressException` for the second case, to be answered non-2xx.

## [1.4.2] - 2026-09-25

- fix(woocommerce): BUG-163 второй счёт не выпускается, пока сервер держит первый открытым
- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ

- **403 is no longer reported as an authentication failure.** `PermissionException` (extends `AuthException`, so existing catch blocks keep working) now carries every 403. `AuthException` is no longer `final` for that reason.
- **Base URL must use HTTPS.** Plain HTTP is accepted only against a loopback host, which is what a local integration test points at. The API secret signs every request, so a plaintext base URL put that credential on the wire.
- **`StatusMapper::isFinalStatus()`.** Answers whether an invoice status is one of the five the server never transitions out of (`paid`, `paid_over`, `underpaid`, `expired`, `cancelled`), so a plugin can recognise an event that arrives after a final one as an out-of-order redelivery.
- **`InvoiceRenewal::isRequired()`.** Tells a plugin when an order needs a new invoice instead of the one already cut: the invoice ended unpaid (`underpaid`, `expired`, `cancelled`) or nobody started it before its `expires_at`. A paid invoice never needs one. The server answers a repeated `external_order_id` with the existing invoice whatever its state, so without this check a returning buyer is sent to an expired checkout.
- **`InvoiceRenewal::isRequired()` allows for clock skew.** An unstarted invoice counts as past its deadline only once `expires_at` is `InvoiceRenewal::CLOCK_SKEW_SECONDS` (300, the webhook timestamp tolerance) behind the caller's clock: the deadline is the server's, and until it passes there the buyer can still pick a network and pay. The deadline is not fixed at creation — confirming a network moves it to `now + PaymentTtl` and sends no webhook — so an `expires_at` a plugin stored is a reason to read the invoice back from the server, never to replace it.
- **An event still being processed is no longer reported as a duplicate.** `remember()` answers false both for an event already committed and for one only locked by a delivery that has not finished, and both became `DuplicateEventException` — which plugins answer 200. Paymos gives a delivery 10 seconds and retries, so a retry landing while the first attempt was still running was acknowledged, and the event was lost if that attempt then failed. A store implementing the new `CommitAwareEventStoreInterface` (`isCommitted()`) now gets `EventInProgressException` for the second case, to be answered non-2xx.

## [1.4.1] - 2026-09-13

- fix(sdk-conformance): восемь копий контракта разошлись с каноном — 403 снова permission
- docs(sdk): quickstart mints the idempotency key once and persists it — a rerun must not create a second operation
- fix(sdk): HTTPS обязателен, а 403 перестал притворяться ошибкой ключа

- **403 is no longer reported as an authentication failure.** `PermissionException` (extends `AuthException`, so existing catch blocks keep working) now carries every 403. `AuthException` is no longer `final` for that reason.
- **Base URL must use HTTPS.** Plain HTTP is accepted only against a loopback host, which is what a local integration test points at. The API secret signs every request, so a plaintext base URL put that credential on the wire.

## [1.4.0] - 2026-08-27

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(sdk): the READMEs say static wallets, not payment channels
- feat(sdk): payment-channel webhooks and the flow in every README
- feat(php-sdk): payment channels, and a feed reader that cannot stop early
- feat(sdk-conformance): declare the payment-channel operations

## [1.3.2] - 2026-08-08

- fix(sdk): let the merchant see why the connection was refused

## [1.3.1] - 2026-08-07

- fix(cms-connect): open the approval tab and return the merchant to the store
- docs(api): pin the empty-body signing rule and fix the published POST vector

## [1.3.0] - 2026-08-03

- feat: finalize CMS integration and localization tooling

## [1.2.1] - 2026-08-02

- fix(ecosystem): recover SDK releases

## [1.2.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents

## [1.1.1] - 2026-07-19

- fix: align SDK problem details semantics

## [1.1.0] - 2026-07-12

- feat(sdk): harden official package release gates
- docs(php-sdk): align dashboard routes
- build(php-sdk): configure WordPress static analysis
- build(php-sdk): add static analysis gate
- feat(php-sdk): harden retries and webhook contracts
- test(sdks): align webhook conformance envelope
- feat(sdk): add official Merchant API clients
- feat(cms): provision official plugins securely
- feat(merchant-api): add secure cursor list endpoints
- refactor(api): move anonymous confirm/simulate to PublicApi

## [1.0.1] - 2026-06-22

### Changed
- `AmountGuard` compares amounts as decimals (server-trimmed `"100"` now matches a stored `"100.00"`), hardening every plugin's reconciler and mismatch checks.
- `StatusMapper` covers the full server invoice/withdrawal event set with no phantom statuses.
- Richer retry handling: dedicated `RetryPolicy`, expanded `RetryingTransport`, and `CurlTransport` tweaks.

### Fixed
- `ApiException` / `RateLimitException` / `UnavailableException` parse the error envelope and rate-limit headers more robustly.
- `Invoices` and `BaseResource` aligned with the current Paymos Merchant API surface.

## [1.0.0] - 2026-05-30

### Added
- Initial public release.
- PHP 7.4 / 8.x compatible, no Composer runtime dependencies.
- HMAC-SHA256 authenticated `Client` with retry on 429 / 5xx.
- Resources: `invoices`, `withdrawals`, `balances`.
- Pluggable transport (`CurlTransport`, `MockTransport`, `RetryingTransport`).
- Webhook verification (`WebhookVerifier`, `WebhookEventProcessor`) with
  Stripe-style multi-signature secret-rotation grace period.
- `EventStoreInterface` + `InMemoryEventStore` for `event_id` deduplication.
- `StatusMapper` for invoice and withdrawal event-to-action mapping.
- Structured error envelopes via `ApiException` + 8 typed subclasses.
- xUnit-style test runner against `php:7.4-cli`.
