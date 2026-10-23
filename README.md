# OneTrace.pro PHP library

[![CI](https://github.com/OneTracePro/onetrace-php/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-php/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/onetracepro/onetrace-php.svg)](https://packagist.org/packages/onetracepro/onetrace-php)
[![License](https://img.shields.io/packagist/l/onetracepro/onetrace-php.svg)](LICENSE)

Server-side integration with [OneTrace.pro](https://onetrace.pro), the customer data platform: send events and orders, update profiles and consents, sync the product catalog, get recommendations, manage segments, journeys and campaigns, send transactional messages — the whole public API v1, without writing HTTP requests by hand.

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

### Subscription preferences

The preference center of a profile: subscription topics by channel, frequency and pause of marketing messages. Use it for a «Subscriptions» block in your customer account, or link to the hosted page (`preferences_url`, signed, valid for 30 days):

```php
$preferences = $onetrace->profiles()->preferences('user_id', '42', 'en');
// channels: [['key' => 'email', 'blocked' => false]], topics: [['key' => 'news', 'name' => 'News', 'channels' => ['email' => true]]],
// frequency: null|weekly|monthly, paused_until, preferences_url

$onetrace->profiles()->updatePreferences('user_id', '42', [
    'topics' => ['news' => ['email' => false]],   // only the given topics and channels change
    'frequency' => 'weekly',                       // at most one marketing message a week across channels
    'paused_until' => new DateTimeImmutable('+30 days'),
]);
$onetrace->profiles()->updatePreferences('user_id', '42', ['unsubscribe_all' => true]);

$topics = $onetrace->profiles()->subscriptionTopics(); // names and descriptions in the project languages
```

Reading needs `profiles.read`, changing — `profiles.write`. Transactional messages are not affected.

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

Translations for other languages of the store go with the product and the category; recommendations, widgets and search with `language` return names and links in that translation:

```php
use OneTrace\Commerce\CatalogItem;

$item = CatalogItem::make('SKU-1', 'Кроссовки', 'https://shop.example/sku-1', null, 4990, 'EUR', true, ['shoes'], null, null, [], 12); // 12 in stock
$item = CatalogItem::translate($item, 'en', 'Sneakers', 'https://shop.example/en/sku-1');
$category = CatalogItem::translateCategory(CatalogItem::category('shoes', 'Обувь'), 'en', 'Shoes');
$onetrace->products()->upsert([$item], [$category]);

$onetrace->recommendations()->get('popular', ['language' => 'en']);
```

## Product search

```php
$result = $onetrace->search()->products('linen dress', [
    'per_page' => 24, 'sort' => 'relevance', 'brand' => ['Contoso'], 'anonymousId' => $visitorId,
]);
// $result['items'], $result['total'], $result['facets'] (categories, brands, price, attributes), $result['relaxed'], $result['personalized']
// $result['corrected'] — results for the corrected query, $result['suggestion'] — "did you mean"
// $result['filters'] — price, brand and characteristics recognized in the text and applied ("red dress under 50")
// $result['learned'], $result['semantic'] — order learned from clicks and purchases, products found by meaning

$onetrace->search()->products('red dress under 50', ['correct' => false, 'ignore' => ['price'], 'attr' => ['season' => 'winter']]);

$hints = $onetrace->search()->suggest('lin'); // products, categories, popular queries
$widget = $onetrace->search()->widget('de'); // search widget settings of Site → Search: enabled, cart_url, texts
```

Word forms, typos and a query typed in the wrong keyboard layout are understood; with `anonymousId` (the tracker visitor id) the first results follow the visitor's interests. The plan of the project must include product search, and search requests count towards its monthly limit: over it the API answers 402 and `PaymentRequiredException::getReason()` is `quota_exceeded` — show the search of your website. With the search widget on, a results page can be just `<div data-cdp-search-results data-cart-url="/cart/add/{id}"></div>`: the tracker draws the results, facets and an "Add to cart" button that posts to your address and expects `{"ok": true}` or `{"redirect": "…"}`. A shop that renders the results page itself sends the `search` event once per query: `(new Messages('myshop', $url))->search($customer, $query, $result['total'])`.

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

## Analytics

Reports of every kind with the same definitions as the analytics pages; the response holds the normalized `definition` and the result. Needs a secret key with `analytics.read` (30 report calculations per minute per key); saved reports and dashboards need `analytics.write`.

```php
$funnel = $onetrace->analytics()->funnel([
    'steps' => [['event' => 'product_viewed'], ['event' => 'add_to_cart'], ['event' => 'order_completed']],
    'window' => ['amount' => 7, 'unit' => 'day'],
    'period' => ['last' => 30],
]);
$funnel['steps'][2]['from_start']; // conversion from the first step

$revenue = $onetrace->analytics()->revenue(['interval' => 'week', 'period' => ['last' => 90]]);
$sources = $onetrace->analytics()->traffic(['dimension' => 'source_medium']);
$credit = $onetrace->analytics()->attribution(['window_days' => 7, 'model' => 'linear']);

// Saved reports and dashboards
$report = $onetrace->analytics()->createSavedReport(['name' => 'Purchase funnel', 'type' => 'funnel', 'definition' => $funnel['definition']]);
$lastWeek = $onetrace->analytics()->runSavedReport($report['id'], ['last' => 7]);
$onetrace->analytics()->createDashboard(['name' => 'Weekly', 'layout' => [['report_id' => $report['id'], 'size' => 'full']]]);
```

Also: `report()` (event report builder), `retention()`, `paths()`, `savedReports()` / `iterateSavedReports()`, `savedReport()`, `updateSavedReport()`, `deleteSavedReport()`, `dashboards()` / `iterateDashboards()`, `dashboard()`, `updateDashboard()`, `deleteDashboard()`.

## Loyalty

Points of the project's loyalty program at checkout and in the customer's account. Needs a secret key with `loyalty.read` (balance, history, calculation) or `loyalty.write` (holds, enrollment, adjustments).

```php
$customer = Identity::email('anna@example.com');

// Checkout: how many points the cart can take, then hold them for 30 minutes
$offer = $onetrace->loyalty()->calculate($customer, 1500.0);   // balance, max_points, max_discount, earn_points
$hold = $onetrace->loyalty()->hold($customer, $offer['max_points'], 'cart-17', 1500.0);
// … apply $hold['discount'] to the order, then
$onetrace->loyalty()->confirm($hold['id'], 'A-1001');          // or cancel($hold['id']) if the customer leaves

$account = $onetrace->loyalty()->account('email', 'anna@example.com'); // balance, pending, tier, expiring
$onetrace->loyalty()->adjust($customer, 500, 'Compensation for a late delivery');
```

A hold that is not confirmed returns to the balance in 30 minutes; refunds and cancellations of the order (`order_refunded` / `order_cancelled`) return the redeemed points automatically. Also: `transactions()` / `iterateTransactions()`, `join()` for programs with explicit enrollment.

Referrals: every member has a code and a link (`referral_code`, `referral_url` of the account). The tracker keeps the code of a `?ref=` link in the `cdp_ref` cookie; pass it on registration:

```php
$onetrace->loyalty()->refer(Identity::userId('42'), $_COOKIE['cdp_ref']); // friend reward at once; 422 for own code or an existing customer
```

## Promo codes

Pools of promo codes: a shared code or unique codes issued to profiles in messages, journeys and through the API. The store applies the discount with its own coupon rule. Needs `promo.read` (pools, codes, checks) or `promo.write` (generate, upload, issue).

```php
// A store plugin: create coupons for new and changed codes of a pool, keep the cursor
$codes = $onetrace->promo()->iterateCodes('welcome', $savedCursor);
foreach ($codes as $code) {
    // create or update the coupon $code['code'] (status, expires_at)
}
$savedCursor = $codes->getReturn();

$code = $onetrace->promo()->issue('welcome', Identity::email('anna@example.com')); // the same code until it is used
$onetrace->promo()->check('WEL7KQ2XM');                                            // pool, status, active, uses
$onetrace->promo()->generate('welcome', 5000);                                     // or import('welcome', ['SHOP-1', …])
```

Use of a code is counted from the `coupon` property of the purchase event (`Order` of the Commerce module), cancellations free it.

## Transactional messages

One message to a profile from a template — an order confirmation, a one-time code — or a cascade of channels tried in order. The profile is found by the identity or created. Needs a secret key with `messages.send`.

```php
$message = $onetrace->messages()->send(Identity::email('anna@example.com'), 12, [
    'traits' => ['first_name' => 'Anna'],    // written to the profile, {{ traits.first_name }}
    'data' => ['order_id' => 'A-1001'],      // only for this message, {{ context.data.order_id }}
], 'order-A-1001');                          // the same key returns the same message
$onetrace->messages()->get($message['id']); // status: queued, sent, delivered, failed, skipped …

// A cascade (plans with cascades): WhatsApp, and email if it is not delivered in 2 minutes
$cascade = $onetrace->messages()->sendCascade(Identity::phone('+4915112345678'), [
    ['integration_id' => 3, 'template_id' => 21, 'wait_for' => 'delivered', 'timeout' => ['amount' => 2, 'unit' => 'minute']],
    ['integration_id' => 4, 'template_id' => 22],
], ['data' => ['code' => '482913']]);
$onetrace->messages()->cascade($cascade['id']); // running, reached (reached_channel) or failed
```

A transactional template skips consents, frequency limits and quiet hours, but not hard blocks of the channel (the address does not exist, the bot is blocked); other templates follow all messaging rules. `integration_id` is needed when the template's channel has several active integrations. Each recipient gets at most 20 messages an hour through the API (429 with `Retry-After`).

## Store emails

The shop's own emails — order confirmations, registration, password resets, invoices — go out through the project's email integration as is, with delivery statuses and history in the customer profile. Order emails can be built from OneTrace templates instead (the store emails section): then send the order data, not the HTML. Needs a secret key with `messages.send`.

```php
use OneTrace\Resource\Emails;

$settings = $onetrace->emails()->settings(); // cache it for a few minutes

try {
    switch (Emails::route($settings, 'order_shipped')) {
        case Emails::TEMPLATE: // OneTrace builds the email in the customer's language
            $onetrace->emails()->send([
                'to' => 'anna@example.com',
                'kind' => 'order_shipped',
                'language' => 'de',
                'order' => $order->emailData(['number' => '1001', 'url' => $orderUrl, 'carrier' => 'DHL', 'tracking_number' => 'JD0001', 'tracking_url' => $trackingUrl]),
            ], 'order-1001-shipped');
            break;
        case Emails::RELAY: // the shop's own email as is
            $onetrace->emails()->send([
                'to' => 'anna@example.com',
                'kind' => 'order_shipped',
                'subject' => $subject,
                'html' => $html,
                'attachments' => [Emails::attachment('invoice-1001.pdf', 'application/pdf', $pdf)],
            ], 'order-1001-shipped');
            break;
        default:
            $mailer->send($nativeEmail);
    }
} catch (\OneTrace\Exception\OneTraceException $e) {
    $mailer->send($nativeEmail); // not accepted or unavailable: the shop sends it itself
}
```

- `kind`: `order_placed`, `order_paid`, `order_shipped`, `order_delivered`, `order_cancelled`, `order_refunded` (`Emails::ORDER_KINDS`, can be built from templates), `account_new`, `password_reset`, `email_verification`, `login_link`, `login_code` (`Emails::ACCESS_KINDS`: no click tracking, only the subject is kept after sending), any other `[a-z0-9_]` name.
- `Order::emailData()` adds the number, links, tracking, reason, payment instructions and addresses (`Order::address()`) to the order; `LineItem::withDisplay()` — SKU, image, link and variant options of a line. Events keep the contract and do not carry these fields.
- Up to 5 attachments, 5 MB in total; `reply_to` and `from_name` override the integration settings; the sender address is always the integration's.

## Chat conversations

Conversations of the online chat (website and messengers) for bots and external helpdesk systems. Reading needs a secret key with `chats.read`, answers and closing — `chats.reply`.

```php
// Conversations waiting for an answer; a customer's conversations — 'profile' => Identity::email(…)
foreach ($onetrace->chats()->iterateConversations(['status' => 'open', 'channel' => 'web']) as $conversation) {
    $full = $onetrace->chats()->conversation($conversation['id']); // with the latest messages, oldest first
    $last = end($full['messages']);                                  // author: visitor, operator, system

    $onetrace->chats()->reply($conversation['id'], 'Your order ships today.', 'answer-' . $last['id']);
}

$onetrace->chats()->close($conversation['id']); // the website visitor is asked to rate the conversation
```

An answer through the API takes no operator seat: the operator inbox signs it with the name of the key (`via: api`, `api_key`), and the customer gets it in the website widget or in the messenger of the conversation. WhatsApp allows free text only within 24 hours of the customer's last message (`ValidationException` otherwise). A repeated idempotency key does not answer twice.

## Store MCP server for the AI chat bot

The AI chat bot of OneTrace can answer customers with live data of your store and, if you allow it, act on orders: it calls your store's MCP server by the [store MCP contract](https://cdp.onetrace.pro/docs/en/store-mcp) (order status, tracking, stock, delivery, returns, cancellation). `OneTrace\StoreMcp\Server` is a ready server: it checks the platform key, answers the MCP protocol, checks the customer token on customer tools and the customer's consent on actions. You implement only the tools you offer.

```php
use OneTrace\StoreMcp\{Context, CustomerTokens, Server, ToolError, Verification};

$tokens = new CustomerTokens(getenv('ONETRACE_CUSTOMER_TOKEN_SECRET'));   // 32+ random characters, only the store knows it
$verification = new Verification(
    $codeStore,                                                         // OneTrace\StoreMcp\CodeStore: your cache
    fn (string $contact, ?string $order): ?string => findCustomerId($contact, $order),
    fn (string $customerId, string $contact, string $code, string $channel) => sendCode($contact, $code, $channel),
);

$server = new Server(getenv('ONETRACE_STORE_MCP_KEY'), $tokens, [
    'store_get_customer' => fn (array $args, Context $ctx): array => customerInfo($ctx->customerId),
    'store_get_order' => function (array $args, Context $ctx): array {
        $order = findOrder($args['order'], $ctx->customerId);

        if ($order === null) {
            throw new ToolError(ToolError::NOT_FOUND, 'No such order.');
        }

        return ['order' => $order->number, 'status' => $order->status, 'status_label' => $order->label($ctx->language)];
    },
    'store_cancel_order' => fn (array $args, Context $ctx): array => cancelOrder($args['order'], $ctx->customerId, $ctx->idempotencyKey),
], ['name' => 'my-shop', 'enabled' => $toolsAllowedByTheOwner, 'verification' => $verification]);

$server->serve();                                         // plain PHP; in a framework — $server->handle($method, $headers, $body)
$onetrace->chats()->connectStoreMcp('https://shop.example.com/onetrace/mcp', getenv('ONETRACE_STORE_MCP_KEY')); // chats.manage
```

- Customer tools (`store_find_orders`, `store_get_order`, `store_get_tracking`, actions) run only with a valid customer token: `$ctx->customerId` is the confirmed customer. A customer signed in on your website gets a token without a code — pass `$tokens->issue($customerId)` to the tracker with `cdp.chatCustomer(token)`; others confirm with a code your store sends (`Verification`: 6 digits, 10 minutes, 5 attempts, 5 codes an hour, the same answer for unknown addresses).
- Actions run only when the customer agreed in the chat (`$ctx->confirmed`); repeat calls carry the same `$ctx->idempotencyKey`.
- `enabled` — tools the store owner allowed for the bot (keep actions off by default). Throw `ToolError` with a contract code (`not_found`, `not_allowed`, …) and a message for the customer; any other exception answers `unavailable` without details.

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
- `orderPaid()`, `orderCancelled()` and `orderRefunded()` follow order status changes; `orderShipped()` (carrier, tracking number and link, expected delivery date; once per parcel) and `orderDelivered()` tell the platform where the order is — the online chat AI answers "where is my order" from them; `product()` and `checkoutStarted()` send cart and wishlist events (`add_to_wishlist`, `remove_from_wishlist`) from the backend.
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
| `PaymentRequiredException` (402) | the feature is not in the plan (`getReason()` = `feature_unavailable`, `getFeature()`) the project is read-only after the trial or the payment grace period (`subscription_expired`), or a monthly limit of the plan is spent (`quota_exceeded`, e.g. search requests) |
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
