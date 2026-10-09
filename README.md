# OneTrace.pro PHP library

[![CI](https://github.com/OneTracePro/onetrace-php/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/onetracepro/onetrace-php.svg)](https://packagist.org/packages/onetracepro/onetrace-php)
[![License](https://img.shields.io/packagist/l/onetracepro/onetrace-php.svg)](LICENSE)

Server-side integration with [OneTrace.pro](https://onetrace.pro), the customer data platform: send events and orders, update profiles and consents, sync the product catalog, get recommendations, manage segments, journeys and campaigns — the whole public API v1, without writing HTTP requests by hand.

- PHP 7.4–8.5, no required dependencies besides `ext-curl` and `ext-json`; any PSR-18 client can be used instead of cURL.
- Safe retries: network errors, `429` and `5xx` are retried with backoff; events are deduplicated by `messageId`, creating and launching resources carries an `Idempotency-Key`.
- Batching buffer for events, cursor pagination helpers, typed exceptions.

## Installation

```bash
composer require onetracepro/onetrace-php
```

## Quick start

```php
use OneTrace\Client;

$onetrace = new Client('https://cdp.onetrace.pro', [
    'write_key' => getenv('ONETRACE_WRITE_KEY'),   // cdp_wk_…: events, recommendations
    'secret_key' => getenv('ONETRACE_SECRET_KEY'), // cdp_sk_…: everything else, server only
]);

$onetrace->events()->track([
    'userId' => (string) $user->id,
    'event' => 'order_completed',
    'messageId' => 'order-' . $order->number, // a repeat within 24 hours is ignored
    'properties' => [
        'order_id' => $order->number,
        'amount' => $order->total,
        'products' => [['product_id' => 'SKU-1', 'quantity' => 2, 'price' => 4990]],
    ],
]);
```

The first argument is the address of your account: `https://cdp.onetrace.pro`, or the domain of your white-label brand. Keys are created in the project under **API keys**. A write key is enough for events; a secret key gets only the permissions chosen when it was created. Keep keys in environment variables and never send a secret key to browsers.

### Options

| Option | Default | |
|---|---|---|
| `write_key` | — | `cdp_wk_…`: events, recommendations, widgets, Web Push |
| `secret_key` | — | `cdp_sk_…`: all methods (used for events too when there is no write key) |
| `timeout` | `10` | seconds per request (built-in cURL transport) |
| `connect_timeout` | `5` | seconds to connect (built-in cURL transport) |
| `max_retries` | `3` | retries of network errors, `429` and `5xx`; `0` disables |
| `retry_delay` | `0.5` | first backoff delay in seconds, doubled on every retry (up to 8 s); `Retry-After` wins |
| `user_agent` | — | appended to the `User-Agent`, e.g. `my-shop/2.1` |
| `language` | `en` | language of error messages from the API: `en`, `ru`, `de`, `es`, `fr`, `it`, `pt`, `tr`, `uz`, `zh` |
| `http_client`, `request_factory`, `stream_factory` | — | send through a PSR-18 client instead of cURL |
| `transport` | cURL | your own `OneTrace\Http\Transport` (tests, custom networking) |

## Events

```php
$events = $onetrace->events();

// A visitor became a known customer: link the browser's anonymous id to the user.
$events->identify([
    'userId' => (string) $user->id,
    'anonymousId' => $_COOKIE['cdp_aid'] ?? null, // set by the website tracker
    'traits' => ['email' => $user->email, 'phone' => '+4915112345678', 'first_name' => 'Anna'],
]);

$events->track(['userId' => '42', 'event' => 'subscription_renewed', 'properties' => ['plan' => 'pro']]);
$events->page(['anonymousId' => $anonymousId, 'name' => 'Checkout', 'properties' => ['url' => $url]]);
$events->alias(['previousId' => $oldUserId, 'userId' => '42']);
```

Every message needs `userId` or `anonymousId` (`alias` needs `userId` and `previousId`, `track` needs `event`). The library adds `messageId`, `timestamp` (or formats a `DateTimeInterface` you pass) and `context.library`. Traits `email`, `phone`, `telegram_chat_id` and `viber_id` identify the profile; other traits become profile attributes; `null` deletes a trait. Calls return `['accepted' => 1, 'duplicates' => 0]`: events are processed asynchronously.

**Many events** — `batch()` splits them into requests of up to 500 messages and 1 MB:

```php
$onetrace->events()->batch([
    ['type' => 'identify', 'userId' => '42', 'traits' => ['plan' => 'pro']],
    ['type' => 'track', 'userId' => '42', 'event' => 'plan_changed'],
]);
```

**Buffer** — queue events during a request or a job and send them in batches:

```php
$buffer = $onetrace->events()->buffer(100); // sends every 100 events

foreach ($orders as $order) {
    $buffer->track(['userId' => $order->userId, 'event' => 'order_shipped', 'properties' => ['order_id' => $order->number]]);
}

$buffer->flush(); // the rest; also sent automatically when the buffer is destroyed
```

In long-running workers call `flush()` yourself. A failed `flush()` throws and keeps the messages; flushing again resends them with the same `messageId`s. Errors of the automatic flush at the end of the script are passed to the callback `buffer(100, function (Throwable $e, array $lost) { … })`, or reported as a PHP warning.

## Profiles

```php
use OneTrace\Identity;

$profile = $onetrace->profiles()->get('email', 'anna@example.com');   // traits, identities, first/last seen
$onetrace->profiles()->updateConsent('user_id', '42', 'email', 'unsubscribed', 'news');

foreach ($onetrace->profiles()->iterateEvents('user_id', '42', ['name' => 'order_completed']) as $event) {
    // newest first, all pages
}

$link = $onetrace->profiles()->telegramLink(Identity::userId(42)); // ['url' => 'https://t.me/…', …]
$onetrace->profiles()->delete('user_id', '42'); // GDPR erasure
```

Identity types: `user_id`, `anonymous_id`, `email`, `phone`, `telegram_chat_id`, `viber_id`, `web_push`. Personal data in responses is masked unless the key has the `profiles.pii` permission.

## Products and recommendations

```php
$onetrace->products()->upsert(
    [['id' => 'SKU-1', 'name' => 'Sneakers', 'price' => 4990, 'currency' => 'EUR', 'url' => 'https://shop.example/sku-1',
      'image' => 'https://shop.example/sku-1.jpg', 'category_ids' => ['shoes'], 'available' => true]],
    [['id' => 'shoes', 'name' => 'Shoes']]
);
$onetrace->products()->delete(['SKU-2']);

$recommendations = $onetrace->recommendations()->get('viewed_with', ['item' => 'SKU-1', 'limit' => 4]);
// personal, popular, trending, viewed_with, bought_with, similar, recently_viewed
```

Product ids are the same ids the website sends in events (`product_id`). Up to 1000 products per call.

## Product search

```php
$result = $onetrace->search()->products('linen dress', [
    'per_page' => 24, 'sort' => 'relevance', 'brand' => ['Contoso'], 'anonymousId' => $visitorId,
]);
// $result['items'], $result['total'], $result['facets'] (categories, brands, price), $result['relaxed'], $result['personalized']

$hints = $onetrace->search()->suggest('lin'); // products, categories, popular queries
```

Word forms, typos and a query typed in the wrong keyboard layout are understood; with `anonymousId` (the tracker visitor id) the first results follow the visitor's interests. The plan of the project must include product search. A shop that renders the results page itself sends the `search` event once per query: `(new Messages('myshop', $url))->search($customer, $query, $result['total'])`.

## Segments, journeys and campaigns

```php
$segment = $onetrace->segments()->create(['name' => 'VIP', 'type' => 'static']);
$onetrace->segments()->addMembers($segment['id'], [Identity::email('anna@example.com'), Identity::userId(42)]);
$onetrace->segments()->membership($segment['id'], 'user_id', '42'); // ['member' => true, …]

// Start a journey with the "API" trigger for one customer; the key enrolls only once.
$onetrace->journeys()->enroll(7, Identity::userId(42), ['order_id' => 'A-1001'], 'order-A-1001');
$onetrace->journeys()->pause(7);

$campaign = $onetrace->campaigns()->get(3);
$onetrace->campaigns()->schedule(3);
$report = $onetrace->campaigns()->report(3);
```

Segment rules, journey graphs and campaign settings use the same JSON as the [API reference](https://onetrace.pro/en/docs/api).

## Message templates

Templates have language versions: `content` in the main `language`, other languages in `translations`. Each recipient gets the version for the `language` trait of their profile, otherwise the main one.

```php
$template = $onetrace->templates()->create([
    'name' => 'Welcome',
    'channel_key' => 'email',
    'language' => 'en',
    'content' => ['subject' => 'Hello {{ traits.first_name }}', 'html' => '<p>Welcome!</p>'],
    'translations' => ['de' => ['subject' => 'Hallo {{ traits.first_name }}', 'html' => '<p>Willkommen!</p>']],
]);
$onetrace->templates()->update($template['id'], ['name' => 'Welcome email']); // versions are kept
```

## E-commerce plugins

`OneTrace\Commerce` builds the messages of the common e-commerce contract that the OneTrace.pro shop plugins send (WooCommerce, Magento, PrestaShop, Shopware, OpenCart, 1C-Bitrix, CS-Cart). Use it for your own shop backend too: journeys, recommendations and predictions then work the same way.

```php
use OneTrace\Commerce\{CatalogItem, Customer, LineItem, Messages, Order, Retry};

$messages = new Messages('myshop', 'https://shop.example.com');
$customer = (new Customer((string) $user->id, $_COOKIE['cdp_aid'] ?? null))
    ->email($user->email)->phone($user->phone)->name($user->first_name, $user->last_name)->country('DE')->language('de');
$order = (new Order('A-1001', 109.90, 'EUR', $placedAt, $customer, [new LineItem('SKU-1', 'Sneakers', 49.95, 2)]))
    ->with(['shipping' => 10, 'coupon' => 'AUTUMN']);

$onetrace->events()->batch(array_filter([
    $messages->identify($customer, [Messages::subscribed('email')]), // consents need the secret key
    $messages->orderCompleted($order),                               // the same order gives the same messageId
]));

$onetrace->products()->upsert([CatalogItem::make('SKU-1', 'Sneakers', $url, $image, 49.95, 'EUR', true, ['shoes'])]);
```

- `Customer` keeps only valid values: the phone in E.164, the country as ISO 3166-1, the language as a BCP 47 tag.
- `orderPaid()`, `orderCancelled()` and `orderRefunded()` follow order status changes; `product()` and `checkoutStarted()` send cart events from the backend.
- `Retry::decide($exception)` tells a queue what to do with a failed batch: `retry` (after `Retry::delay($attempt)`), `drop` (invalid data) or `settings` (the key is wrong).
- The contract as JSON Schema: `resources/ecommerce-events.schema.json` — validate your messages against it in tests.
- `$onetrace->checkKey()` / `checkKey('write')` — type, project and permissions of a key, for a "Test connection" button.

## Pagination

Lists return a `OneTrace\Page` (iterable, countable, `getNextCursor()`); `iterate…()` methods walk all pages lazily:

```php
$page = $onetrace->segments()->list(['limit' => 50]);
$next = $onetrace->segments()->list(['cursor' => $page->getNextCursor()]);

foreach ($onetrace->segments()->iterateMembers(12, ['limit' => 200]) as $member) {
    // …
}
```

## Errors

All exceptions implement `OneTrace\Exception\OneTraceException`.

| Exception | When |
|---|---|
| `ValidationException` (422) | invalid request; `getErrors()` returns messages by field |
| `AuthenticationException` (401) | missing, invalid or revoked key |
| `PaymentRequiredException` (402) | the feature is not in the plan (`getReason()` = `feature_unavailable`, `getFeature()`) or the project is read-only after the trial or the payment grace period (`subscription_expired`) |
| `PermissionException` (403) | the key lacks a permission, the site domain is not allowed or the account is suspended |
| `NotFoundException` (404) | not found in the key's project |
| `ConflictException` (409) | the current state does not allow the action |
| `RateLimitException` (429) | rate limit or monthly event quota; `getRetryAfter()` |
| `ServerException` (5xx) | temporary server error |
| `ApiException` | any other error status; base class of the above, `getStatusCode()`, `getPayload()` |
| `TransportException` | no response: DNS, connection, TLS, timeout |
| `ConfigurationException` | a method needs a key the client was not given |

Network errors, `429`, `5xx` (and `409` of a request with an idempotency key) are retried before the exception is thrown. Invalid arguments (a `track` without `event`, an unknown identity type) throw `InvalidArgumentException` before any request.

```php
try {
    $onetrace->segments()->create(['name' => '']);
} catch (OneTrace\Exception\ValidationException $e) {
    $e->getErrors(); // ['name' => ['The name field is required.']]
}
```

## Idempotency

Retries never duplicate data: events carry a `messageId` (pass your own, such as an order number, to make resending from your side safe too); creating segments, journeys and campaigns, adding segment members, enrolling and launching send an `Idempotency-Key` — generated per call and reused on retries, or your own as the last argument:

```php
$onetrace->segments()->create(['name' => 'VIP', 'type' => 'static'], 'segment-vip');
```

## PSR-18 clients

```php
$factory = new Nyholm\Psr7\Factory\Psr17Factory();

$onetrace = new OneTrace\Client('https://cdp.onetrace.pro', [
    'secret_key' => getenv('ONETRACE_SECRET_KEY'),
    'http_client' => new GuzzleHttp\Client(['timeout' => 10]),
    'request_factory' => $factory,
    'stream_factory' => $factory,
]);
```

## Testing your code

Pass a `transport` implementing `OneTrace\Http\Transport` to record requests and return prepared responses instead of calling the API.

## Development

```bash
composer install
composer check   # PHPStan and PHPUnit
```

The test suite checks that every operation of the API specification has a method (`tests/OperationsTest.php`); `ONETRACE_SPEC_URL=https://cdp.onetrace.pro/api/v1/openapi.json vendor/bin/phpunit --filter OperationsTest` runs it against the live platform.

## License

MIT, see [LICENSE](LICENSE).
