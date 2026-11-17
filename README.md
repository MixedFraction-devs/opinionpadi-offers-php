# OpinionPadi Offers for PHP

Send purchases, signups and refunds from your PHP server to the OpinionPadi Offers events API. OpinionPadi uses these events to record conversions for the padis who sent you customers, and to reconcile what you sent against what was recorded and charged.

Full API reference: https://opinionpadi.com/developers/offers

## Requirements

PHP 8.1 or later with the `curl` and `json` extensions. There are no other dependencies.

## Install

```bash
composer require opinionpadi/offers
```

## Quickstart

A padi's link lands the customer on your site with `op_click` and `op_ref` in the URL. Add this script to your pages and it keeps both in first-party cookies (`op_click`, `op_ref`), so your server can read them at checkout:

```html
<script src="https://unpkg.com/@opinionpadi/offers@0.1.0/dist/opinionpadi.js" defer></script>
```

Then:

```php
use OpinionPadi\Offers\Attribution;
use OpinionPadi\Offers\OpinionPadi;

$op = new OpinionPadi(['api_key' => getenv('OPINIONPADI_SECRET_KEY')]);
$attribution = Attribution::fromCookies($_COOKIE); // ['click_id' => ..., 'ref' => ...]

$op->events->track([
    'type' => 'purchase',
    'event_id' => (string) $order->id, // your own id; sending it twice changes nothing
    'click_id' => $attribution['click_id'],
    'ref' => $attribution['ref'],
    'amount' => 15000.50,              // naira
]);

// Later, if the order is refunded
$op->events->refund(['event_id' => 'refund_'.$order->id, 'refund_of' => (string) $order->id]);
```

Send every purchase, including ones without a click id. Those are stored as `unattributed`, which is what lets the reconciliation report show your share of sales that came from padis. Null values are left out of the request, so you can pass the attribution through as it is.

If you read the landing URL on the server instead, `Attribution::fromUrl($url)` returns the same shape.

## Configuration

```php
$op = new OpinionPadi([
    'api_key' => 'op_sk_live_...',    // or set OPINIONPADI_SECRET_KEY
    'base_url' => 'https://api.opinionpadi.com/api/v1/offers', // or OPINIONPADI_BASE_URL
    'timeout' => 10,                  // seconds
    'max_retries' => 2,
]);

$op->mode();        // 'live' or 'test', from the key prefix
$op->isTestMode();
```

Keys are secret. Use them from your server only, never in a browser or a mobile app. Create them on the Developers page of your business dashboard.

## Methods

Every method returns the decoded JSON response as an associative array, with the field names the API uses.

| Method | Request |
|---|---|
| `$op->account()` | `GET /account` |
| `$op->events->track([...])` | `POST /events`, returns `['duplicate' => bool, 'event' => [...]]` |
| `$op->events->refund([...])` | `POST /events` with `type` set to `refund`. Needs `event_id` and `refund_of`. |
| `$op->events->batch([[...], ...])` | `POST /events/batch`, 1 to 100 events |
| `$op->events->get($eventId)` | `GET /events/{event_id}` |
| `$op->events->list([...])` | `GET /events` |
| `$op->conversions->list([...])` | `GET /conversions` |
| `$op->reconciliation->get([...])` | `GET /reconciliation` |
| `$op->balance([...])` | `GET /balance` |

`occurred_at` accepts an ISO 8601 string or a `DateTimeInterface`. The `from` and `to` filters accept a `Y-m-d` string or a `DateTimeInterface`.

```php
$page = $op->events->list(['outcome' => 'converted', 'per_page' => 20]);
$report = $op->reconciliation->get(['from' => '2026-11-01', 'to' => '2026-11-17']);

// Wallet and what is left on each offer, in whole naira
$balance = $op->balance();
foreach ($balance['by_offer'] as $offer) {
    if ($offer['remaining_slots'] < 5) {
        // top it up in the dashboard before sales start coming back budget_exhausted
    }
}
```

## Test mode

A test key (`op_sk_test_...`) runs every event through the same validation and attribution as a live key, then stops. Nothing is recorded against an offer and no money moves. Each event says what a live event would have done, with `"simulated": true` on its conversion.

Put one of these values in `click_id` or `ref` to play a fixed scenario without a real click. Add `offer_id` to get that offer's payout and cost in the result.

| Test value | Result |
|---|---|
| `op_test_converted` | `converted`, conversion `pending` |
| `op_test_converted_paid` | `converted`, conversion `approved` |
| `op_test_budget_exhausted` | `rejected` / `budget_exhausted` |
| `op_test_outside_window` | `rejected` / `outside_window` |
| `op_test_offer_closed` | `rejected` / `offer_closed` |
| `op_test_link_blocked` | `rejected` / `link_blocked` |
| `op_test_fraud` | `rejected` / `fraud` |
| `op_test_unknown` | error `unknown_click` (or `unknown_ref`) |

```php
$op = new OpinionPadi(['api_key' => 'op_sk_test_...']);

$result = $op->events->track([
    'type' => 'purchase',
    'event_id' => 'test_order_1',
    'click_id' => 'op_test_converted',
    'offer_id' => 42,
    'amount' => 15000,
]);
$result['event']['outcome'];                  // 'converted'
$result['event']['conversion']['status'];     // 'pending'
```

`examples/smoke.php` runs every method against a server with a test key:

```bash
OPINIONPADI_SECRET_KEY=op_sk_test_... php examples/smoke.php
```

## Errors

Every failure throws `OpinionPadi\Offers\OpinionPadiException`.

```php
use OpinionPadi\Offers\OpinionPadiException;

try {
    $op->events->track($event);
} catch (OpinionPadiException $e) {
    $e->getCode();     // 'unknown_click', 'invalid_request', ...
    $e->getMessage();  // what went wrong and how to fix it
    $e->param;         // the field it is about, or null
    $e->status;        // HTTP status, or null when no response arrived
    $e->body;          // the decoded response body
    $e->retryAfter;    // seconds, on a 429 with a Retry-After header
}
```

`getCode()` returns the API's error code as a string (also available as `$e->errorCode`). When no usable response arrived, the code is `connection_error`, `timeout` or `invalid_response`. A 429 without an error body has the code `rate_limited`, and a 5xx without one has `server_error`.

The client retries connection errors, timeouts, `409`, `429` and `5xx` responses up to `max_retries` times, waiting 0.5 seconds, then 1, then 2 (with some jitter, at most 8), or the `Retry-After` time on a `429`. Other `4xx` responses are not retried. Retrying an event is safe because the API is idempotent on `event_id`.

## Custom HTTP and tests

Pass any `OpinionPadi\Offers\Http\TransportInterface` as the `transport` option to send requests through your own HTTP client, or to return fake responses in tests. The default is `CurlTransport`.

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
