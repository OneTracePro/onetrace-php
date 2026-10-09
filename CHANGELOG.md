# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.4.0 — 2026-10-09

- Catalog languages: `CatalogItem::translate()` and `CatalogItem::translateCategory()` add translations of the name, link and attributes for other languages of the store; recommendations, widgets and search take `language` (the visitor's language) and return products in that translation.
- `Customer::languageTag()`: a platform locale as a BCP 47 tag.

## 1.3.0 — 2026-10-09

- `search()`: product search for websites — `products()` (results with facets, the order by the visitor's interests) and `suggest()` (products, categories and popular queries while typing).
- `Commerce\Messages::search()`: the `search` event of a results page; the contract schema has the `search` and `search_result_clicked` events.

## 1.2.0 — 2026-10-08

- `OneTrace\Commerce`: messages of the common e-commerce contract for shop plugins and backends — `Customer`, `LineItem`, `Order`, `Messages` (identify with consents, order_completed / paid / cancelled / refunded with deterministic messageIds, product and checkout events), `CatalogItem` for the product feed and `Retry` for queues; the contract as JSON Schema in `resources/ecommerce-events.schema.json`.
- `templates()`: message templates with language versions (list, get, create, update, delete).
- `checkKey()`: type, project and permissions of the write or the secret key.
- Events with `consents` (identify from a sign-up or checkout form) are sent with the secret key even when a write key is set: the platform accepts consents only from servers.

## 1.1.0 — 2026-10-07

- The `viber_id` identity type (`Identity::viberId()`): profiles, segment members, deep links and journey enrollment by the id of the user at the project's Viber bot.

## 1.0.0 — 2026-10-06

First release: the whole public API v1 of OneTrace.pro.

- Events: `track`, `identify`, `page`, `alias`, `batch` (split into requests of up to 500 messages and 1 MB) and a batching buffer; `messageId`, `timestamp` and `context.library` are filled automatically.
- Profiles and consents, Telegram and Viber deep links, product catalog, data catalog, recommendations, website widgets, Web Push subscriptions.
- Segments and members, journeys (drafts, publishing, enrollments, the API trigger), campaigns.
- Retries of network errors, `429` (with `Retry-After`), `5xx` and in-progress idempotent requests; idempotency keys for creating and launching resources.
- Cursor pagination with lazy iteration, typed exceptions (including 402 with the reason), error messages in the chosen language, cURL transport and a PSR-18 adapter.
- PHP 7.4–8.5.
