# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-10-06

First release: the whole public API v1 of OneTrace.pro.

- Events: `track`, `identify`, `page`, `alias`, `batch` (split into requests of up to 500 messages and 1 MB) and a batching buffer; `messageId`, `timestamp` and `context.library` are filled automatically.
- Profiles and consents, Telegram and Viber deep links, product catalog, data catalog, recommendations, website widgets, Web Push subscriptions.
- Segments and members, journeys (drafts, publishing, enrollments, the API trigger), campaigns.
- Retries of network errors, `429` (with `Retry-After`), `5xx` and in-progress idempotent requests; idempotency keys for creating and launching resources.
- Cursor pagination with lazy iteration, typed exceptions (including 402 with the reason), error messages in the chosen language, cURL transport and a PSR-18 adapter.
- PHP 7.4–8.5.
