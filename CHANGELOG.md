# Changelog

All notable changes to the Paymos PHP SDK are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

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
