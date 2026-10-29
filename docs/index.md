# omnifood/ubereats

Uber Eats for [omnisense/omnifood](https://github.com/glitchr-omnisense/omnifood): a store's
orders read, accepted, denied, marked ready and cancelled, its menu uploaded and its items
suspended, the store paused and its holiday hours, the webhooks checked, and the merchant's
consent to link the store - through the Uber Eats Marketplace APIs (Order Fulfillment API Suite,
Store API Suite, Menu API). The calls go to `https://api.uber.com` with a token from
`https://auth.uber.com/oauth/v2/token`, or to `https://test-api.uber.com` and
`https://sandbox-login.uber.com/oauth/v2/token` with `sandbox: true`.

| Operation | Uber Eats |
|---|---|
| `order($id)` | `GET /v1/delivery/order/{id}?expand=carts,deliveries,payment` |
| `orders($since)` | `GET /v1/delivery/store/{store_id}/orders` (pages of 50) |
| `accept($id, $readyAt)` | `POST /v1/delivery/order/{id}/accept` |
| `deny($id, $reason, $note)` | `POST /v1/delivery/order/{id}/deny` |
| `ready($id)` | `POST /v1/delivery/order/{id}/ready` |
| `cancel($id, $reason, $note)` | `POST /v1/delivery/order/{id}/cancel` |
| `updateReadyTime($id, $at)` | `POST /v1/delivery/order/{id}/update-ready-time` |
| `pushMenu($menu)` | `PUT /v2/eats/stores/{store_id}/menus` |
| `setAvailability($ref, ...)` | `POST /v2/eats/stores/{store_id}/menus/items/{ref}` |
| `status()` | `GET /v1/delivery/store/{store_id}/status` |
| `pause()`, `resume()` | `POST /v1/delivery/store/{store_id}/update-store-status` |
| `setHours($hours)` | `POST /v1/eats/stores/{store_id}/holiday-hours` |
| `token()`, `refresh()` | `POST /oauth/v2/token`, client credentials |
| `authorizationUrl()`, `exchange()` | `https://auth.uber.com/oauth/v2/authorize`, then the token endpoint with the code |

The store must be on webhooks version 1.0.0 (`PATCH /v1/eats/stores/{store_id}/pos_data` with
`"webhooks_version": "1.0.0"`, a provisioning call this package does not make), so that its
orders are those of the Order Fulfillment API.

## Installation

```sh
composer require omnifood/ubereats
```

Requires PHP 8.2, `omnisense/omnifood` (1.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnifood\Bridge\Symfony\OmnifoodBundle`, the factory is registered as soon as
the package is installed.

## Credentials

- A **Developer Dashboard** account and application at developer.uber.com, a **non-disclosure and
  API licence agreement** with Uber, and **Uber's written approval**: the scopes (`eats.store`,
  `eats.order`, `eats.store.orders.read`, `eats.store.status.write`) are granted to the
  application by Uber's team.
- **Test stores** from Uber's Integration Tech Support, a joint end-to-end check, then a
  production application and a pilot store.
- The application's **client id and secret** (the secret also signs the webhooks), the **store
  id**, the **webhook URL** set in the dashboard (Primary Webhook URL), and the store linked to the
  application (by Uber, or by the merchant through `authorizationUrl()`).

Uber publishes no shared test account.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | for every call | the application's client id |
| `client_secret` | string | - | for every call and `notify()` | the application's secret, the webhooks' signing key |
| `store_id` | string | - | for `orders()`, the menu and the store | the store's id at Uber |
| `scopes` | list, or a string split on spaces and commas | `eats.store eats.order eats.store.orders.read eats.store.status.write` | no | the client-credentials token's scopes |
| `access_token` | string | - | no | a token kept by the site |
| `token_expires_at` | string (a date, ATOM) | - | no | when that token dies; a date PHP cannot read is an `InvalidConfigException` |
| `language` | string | `en_us` | no | the key of the menu's translations |
| `tax_inclusive` | bool | `true` | no | `true`: items carry `vat_rate_percentage` (prices include VAT); `false`: `tax_rate` |
| `sandbox` | bool | `false` | no | the test API and the sandbox login |
| `base_uri` | string | `https://api.uber.com` (sandbox: `https://test-api.uber.com`) | no | another API address |
| `token_url` | string | `https://auth.uber.com/oauth/v2/token` (sandbox: `https://sandbox-login.uber.com/oauth/v2/token`) | no | another token endpoint |

No option is checked when the platform is built; a call that needs a key and finds it empty
throws `InvalidConfigException` (`The "ubereats" platform needs: client_id, client_secret.`,
`... needs: store_id.`).

## Capabilities

| Interface | Supported |
|---|---|
| `OrdersInterface` | yes: `order()`, `orders()`, `accept()` (with a ready time), `deny()`, `ready()`, `cancel()`; and `updateReadyTime()` |
| `MenuInterface` | yes |
| `StoreInterface` | yes; `setHours()` sends the dates apart only |
| `ReservationsInterface` | no |
| `NotifiableInterface` | yes |
| `Auth\RefreshableInterface` | yes |
| `Auth\OAuthInterface` | yes (scope `eats.pos_provisioning`) |

`capabilities()`: order types delivery, pickup and dine-in; `acceptanceDelay` 690 (11.5 minutes);
`readyAt`, `allergens`, `photos`, `vatRates`, `pauseUntil` and `modifiersAreItems` true;
`modifierDepth` 2; no name or description limit, no currency restriction.

## Behaviour

**Token.** Asked for on the first call that needs one: `POST` of a form with the client id, the
secret, `grant_type=client_credentials` and the scopes joined by spaces. Kept in the object until 5
minutes before it expires (`expires_in`; a token without one never expires). Uber allows 100
token requests an hour: a site with many processes keeps it ([the core's
configuration](https://github.com/glitchr-omnisense/omnifood/blob/1.x/docs/configuration.md#tokens)).
Every call sends `Authorization: Bearer` and `Accept: application/json`.

**Orders.** `order()` reads `order` from the answer (or the answer itself). `orders($since)` sends
`start_time` (RFC 3339; the last 24 hours by default), `page_size=50`, and follows
`pagination_data.next_page_token`; the orders come back newest first by `created_time`. The
mapping:

- `reference` is Uber's `id`, `displayId` its `display_id`, `store` `store.id`, `placedAt`
  `created_time`, `pickupAt` `preparation_time.ready_for_pickup_time`, `deliverAt` the first
  delivery's `estimated_dropoff_time`;
- `type`: `fulfillment_type` `PICKUP` is pickup, `DINE_IN` dine-in, anything else delivery;
- `status`: `CREATED` and `OFFERED` are `NEW`; `ACCEPTED` is `ACCEPTED`, or `READY` when
  `preparation_status` is `READY_FOR_HANDOFF`; `HANDED_OFF` `PICKED_UP`; `SUCCEEDED` `DELIVERED`;
  `FAILED` is `DENIED` when `failure_info.reason` is `POS_DENIED`, else `CANCELLED`; anything else
  `UNKNOWN`;
- the lines are every cart's items: `title`, `quantity.amount`, `id` as `posRef` (the menu's item
  reference), `cart_item_id` as `platformRef`, the special instructions and allergy instructions
  as the line's note; the selected modifier groups' items as modifiers, nested;
- prices come from `payment.payment_detail`: Uber's amounts (`amount_e5`, times 10^5) become
  `Money` in minor units, the gross amount (tax included). `total` is `order_total`, `subtotal`
  `item_charges.total`, `discount` `promotions.total` (as a positive amount), `fees`
  `fees.total`, `tax` `order_total`'s tax part; each line's unit and total from
  `item_charges.price_breakdown`, by cart item;
- the customer is the primary one (else the first): display name (or first and last names), phone
  and pin code; the courier the first delivery's partner: name, phone, the delivery's status,
  `estimated_pick_up_time`, vehicle type;
- the carts' special instructions, joined, are the order's note. `cutlery` is not read.

**Accept, deny, ready, cancel.** `accept()` sends `ready_for_pickup_time` (RFC 3339) when given, an
empty object otherwise. `deny()` sends `deny_reason`, `cancel()` `cancellation_reason`: `{type,
info}`, the type mapped from `DenyReason` (`ITEM_ISSUE`, `STORE_CLOSED`, `RESTAURANT_TOO_BUSY`,
`CUSTOMER_CALLED_TO_CANCEL`, `ADDRESS`, `PRICING`, `SPECIAL_INSTRUCTIONS`, `TECHNICAL_FAILURE`,
`OTHER`), the note as `info`. Only the application that is the store's order manager may accept,
deny or cancel; an order neither accepted nor denied within 11.5 minutes is cancelled by Uber.

**Menu.** The menu is checked by the core's validator, then sent whole as Uber's four lists: one
menu (`id` the menu's `ref` or `menu`, `title`, `subtitle`, `service_availability` from
`Menu::$hours` by day of the week, the category ids), the categories (their items as entities of
type `ITEM`), the items and the modifier groups. Every option is an item of its own (Uber's
model), with its price (`price_info.price` in minor units; 0 for a free option), its VAT rate
(the item's, else its parent's), its allergens as the EU-14 names (`gluten`, `milk`...), its photo
(`image_url`), its dietary labels (`vegan`, `vegetarian`, `gluten_free` among the labels become
`VEGAN`, `VEGETARIAN`, `GLUTEN_FREE`); `external_data` is the reference. A group's
`quantity_info` is its `min` and `max`. Texts are `{translations: {<language>: text}}`. The
`Hours::$exceptions` of the menu's hours and `Item::$available` are not sent.

**Out of stock.** `setAvailability()` sends `suspension_info.suspension.suspend_until`, a Unix
time: `$until`, or a year from now when none is given (Uber needs an end); 0 makes the item
available again.

**Store.** `status()`: `ONLINE` is `OPEN`, `OFFLINE` `PAUSED`, anything else `UNKNOWN`, with
`is_offline_until` and `offline_reason`. `pause()` sends `OFFLINE`, `is_offline_until` (RFC 3339)
and `reason` when given; `resume()` `ONLINE`. `setHours()` sends `Hours::$exceptions` as holiday
hours, all at once (each call replaces the previous ones), a closed date as 00:00-00:00; the week
is not sent: at Uber it is the menus' service hours (`pushMenu()`).

**Webhooks.** `X-Uber-Signature` must be the lowercase hex HMAC-SHA256 of the raw body with the
client secret, compared in constant time. The notification: `event` `event_type`; `subject` order
for `orders.*`, courier for `delivery.*`, menu for `store.menu*`, store for `store.*`, other
otherwise; `reference` `meta.resource_id` (else `meta.order_id`); `id` `event_id`; `status` `NEW`
for `orders.notification` and `orders.scheduled.notification`, `CANCELLED` for `orders.cancel` and
`orders.failure`; `occurredAt` `event_time` (seconds, or milliseconds when above 10^11); `store`
`meta.user_id` (else `meta.store_id`). The webhook carries no order: `order($n->reference)` reads
it. Answer 200, empty.

**OAuth.** `authorizationUrl($redirectUri, $state, $scopes)` is the authorize URL with
`response_type=code` and the scopes (`eats.pos_provisioning` by default); `exchange($code,
$redirectUri)` asks the token endpoint with `grant_type=authorization_code`. That token (with its
refresh token and scopes) is for the provisioning calls (`GET /v1/eats/stores`, `POST
.../pos_data`), which this package does not make.

Not covered, though documented by Uber: price adjustments and fulfillment issues (mostly retail),
the provisioning calls, the reporting API, busy mode and preparation times. Not public, so not
used: the allergen values Uber expects (free strings: the EU-14 names are sent), the webhook's
response time limit, the general rate limits.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnifood\Exception\InvalidSignatureException;
use Omnifood\UberEats\UberEatsPlatformFactory;
use Symfony\Component\HttpClient\HttpClient;

$ubereats = (new UberEatsPlatformFactory(HttpClient::create()))->create([
    'client_id' => getenv('UBEREATS_CLIENT_ID') ?: null,
    'client_secret' => getenv('UBEREATS_CLIENT_SECRET') ?: null,   // also signs the webhooks
    'store_id' => getenv('UBEREATS_STORE_ID') ?: null,
    'sandbox' => true,
]);

// The webhook endpoint
try {
    $notification = $ubereats->notify((string) file_get_contents('php://input'), getallheaders());
} catch (InvalidSignatureException) {
    http_response_code(401);
    exit;
}
http_response_code(200);

// Later, in a worker (11.5 minutes at most)
if ('orders.notification' === $notification->event) {
    $order = $ubereats->order($notification->reference);
    $ubereats->accept($order->reference, new \DateTimeImmutable('+15 minutes'));
    // ... when it is ready:
    $ubereats->ready($order->reference);
}

$ubereats->pause(new \DateTimeImmutable('+30 minutes'), 'Rush');
$ubereats->setHours(new Omnifood\Model\Hours([], ['2026-12-25' => []]));   // Christmas closed
```

```yaml
# config/packages/omnifood.yaml
omnifood:
    platforms:
        ubereats:
            factory: ubereats
            options:
                client_id: '%env(default::UBEREATS_CLIENT_ID)%'
                client_secret: '%env(default::UBEREATS_CLIENT_SECRET)%'
                store_id: '%env(default::UBEREATS_STORE_ID)%'
                sandbox: '%env(bool:default::UBEREATS_SANDBOX)%'
```

Not run live (no keys); the calls above are those the package's tests check on recorded answers.

## Errors

- 401 and 403: `UnauthorizedException` (the keys, a scope Uber has not granted).
- 429: `ProviderException` `Too many calls, try again later: ...`.
- Any other status of 400 or more: `ProviderException` with Uber's `message` (or the OAuth
  `error_description`, or `HTTP <status>.`), `status`, and Uber's `code` (or the OAuth `error`)
  as `providerCode`.
- A network failure: `ProviderException` `Uber could not be reached: ...`, the HTTP client's
  exception as the previous one.
- A 2xx answer whose body is not JSON (a proxy's or a maintenance page): `ProviderException`
  `An answer that is not JSON (HTTP 200).`, with the status.
- A token answer without `access_token`: `ProviderException` `No token in Uber's answer.`
- A webhook whose signature does not match, or whose body is not JSON: `InvalidSignatureException`;
  no `client_secret`: `InvalidConfigException`.

## Verified, and not

`Tests/UberEatsPlatformTest.php` checks, on recorded answers written from Uber's public
documentation (read on 2026-10-04): an order read whole, the states as Omnifood's statuses, the
orders page by page newest first, accept, deny, ready and cancel, the menu in Uber's shape, a menu
the validator refuses sending nothing, an item suspended then back, the store paused and resumed
and its holidays, a webhook checked and read, the merchant's OAuth, a token kept and refreshed, the
sandbox addresses, the errors mapped, and that only what needs no key works without keys. Green in
the family's harness on 2026-10-26. Not run against Uber's API, live or sandbox: start in Uber's
sandbox (`sandbox: true`) before a real store.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Uber Technologies, Inc. Whoever runs it complies with Uber's terms ([Uber Eats API
Terms of Use](https://www.uber.com/ca/en/legal/uber-eats-api-terms-of-use/)); see the README's
Responsibility section.
