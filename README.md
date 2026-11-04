# Laravel Allegro

[![tests](https://github.com/beziworld/laravel-allegro/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-allegro/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-allegro.svg)](https://packagist.org/packages/beziworld/laravel-allegro)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-allegro.svg)](LICENSE.md)

[Allegro REST API](https://developer.allegro.pl) client for Laravel, built for shops and SaaS platforms that serve one or
many Allegro sellers:

- OAuth2: authorization code (with PKCE), device flow and client credentials; rotating refresh tokens are refreshed
  under a lock and kept in a pluggable token store
- many seller accounts on one application: named accounts in the config and sellers connected at runtime
- orders: event journal polling with Laravel events, checkout forms, fulfillment status, tracking numbers, invoices
- offers: listing, create and edit through `/sale/product-offers` (with async operation polling), batch price and
  quantity changes, offer event journal
- Wysyłam z Allegro: shipments, labels (PDF/ZPL, A4/A6), protocols, courier pickups
- billing entries, message center, customer returns (beta), categories, parameters and the product catalogue
- typed, immutable DTOs (`Money` keeps amounts as strings) and a generic `request()` for every other endpoint

[Polska wersja dokumentacji](README.pl.md)

## Installation

```bash
composer require beziworld/laravel-allegro
php artisan vendor:publish --tag=allegro-config   # optional
```

Register an application at [apps.developer.allegro.pl](https://apps.developer.allegro.pl/) (sandbox:
[apps.developer.allegro.pl.allegrosandbox.pl](https://apps.developer.allegro.pl.allegrosandbox.pl/)).

```dotenv
ALLEGRO_CLIENT_ID=your-client-id
ALLEGRO_CLIENT_SECRET=your-client-secret
ALLEGRO_SANDBOX=false                                    # true = *.allegrosandbox.pl
ALLEGRO_REDIRECT_URI=https://shop.example/allegro/callback
ALLEGRO_USER_AGENT="MyShop/1.0 (+https://shop.example)"  # mandatory, see below
ALLEGRO_CACHE_STORE=redis                                # shared store for tokens, locks and cursors
```

Allegro requires every integration to send a `User-Agent` in the format `AppName/Version (+https://info-page-url)`,
consistent with the name of the registered application; the package refuses to start without it. Requests also carry
`Accept`/`Content-Type: application/vnd.allegro.public.v1+json` (the beta media type for beta resources) and
`Accept-Language` (`ALLEGRO_LANGUAGE`, default `pl-PL`), which sets the language of error messages.

## Connecting a seller

### Authorization code with PKCE

```php
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\OAuth\Pkce;

// Redirect
$verifier = Pkce::generateVerifier();
session(['allegro_verifier' => $verifier, 'allegro_state' => $state = Str::random(40)]);

return redirect(Allegro::authorizationUrl($state, $verifier));

// Callback (the code is valid for a few seconds)
abort_unless($request->query('state') === session('allegro_state'), 403);

Allegro::exchangeCode($request->query('code'), session('allegro_verifier'));            // default account
Allegro::forUser('seller-' . $seller->id)->exchangeCode($request->query('code'), session('allegro_verifier')); // SaaS
```

Without PKCE call `authorizationUrl($state)` and `exchangeCode($code)`. Pass scopes as the third argument (or set
`allegro.scopes`) to request fewer permissions, and `'confirm'` as the fourth one to show the account confirmation
screen.

### Device flow

For back-office tools and terminals (the application must be registered as a device application):

```bash
php artisan allegro:device-login            # default account
php artisan allegro:device-login second     # named account
php artisan allegro:device-login --user=seller-42
```

Or in code:

```php
$code = Allegro::startDeviceFlow();
// show $code->verificationUri and $code->formattedUserCode(), or the clickable $code->verificationUriComplete

$token = Allegro::forUser('seller-42')->waitForDeviceToken($code);   // honours "interval" and "slow_down"
// or poll yourself: Allegro::forUser('seller-42')->pollDeviceToken($code)->isGranted()
```

### Application token

Public resources (categories, parameters, carriers, billing types) accept a client credentials token:

```php
Allegro::application()->catalog()->categories();
```

### Tokens

Access tokens live 12 hours; refresh tokens live 3 months and are **single use**: every refresh returns a new pair and
the previous refresh token stays valid for only 60 seconds. The client refreshes expired tokens under a cache lock,
re-reads the store after acquiring the lock, and stores the new pair immediately. After HTTP 401 it refreshes once and
repeats the request.

Tokens are kept in the cache by default (`CacheTokenStore`, 90-day TTL). To keep them in your database, implement
`Beziworld\Allegro\Contracts\TokenStore` (`get`, `put`, `forget`; the key is the account name or the `forUser()` key)
and set `allegro.token_store` to your class, or bind the interface in the container. An account can also be seeded with
a refresh token in the config (`ALLEGRO_REFRESH_TOKEN`).

When Allegro revokes the authorization (password change, unlinking the application, expired refresh token), calls
throw `AuthenticationException` with `requiresReauthorization() === true`: ask the seller to connect again.

## Multiple seller accounts

One application serves any number of sellers, each with its own tokens:

```php
// config/allegro.php
'accounts' => [
    'default' => ['refresh_token' => env('ALLEGRO_REFRESH_TOKEN')],
    'outlet' => ['token_key' => 'outlet-shop', 'language' => 'en-US'],
],
```

```php
Allegro::orders()->checkoutForms();                       // default account
Allegro::account('outlet')->orders()->checkoutForms();    // named account
Allegro::forUser('seller-42')->orders()->checkoutForms(); // seller connected at runtime (SaaS)
Allegro::forUser('seller-42')->isConnected();
Allegro::forUser('seller-42')->disconnect();
```

Account entries may override any shared option, including `client_id`, `client_secret` and `sandbox`.

## Orders

### Polling new orders

Allegro has no webhooks; changes are read from event journals. `allegro:poll-orders` reads `/order/events` from the
last stored event id and dispatches `OrderEventReceived` for every new event. On the first run of an account it starts
at the newest event (set `allegro.events.start` to `beginning` to replay the last 60 days).

```php
// routes/console.php
Schedule::command('allegro:poll-orders --all')->everyMinute()->withoutOverlapping();
// sellers connected at runtime: --user=seller-1 --user=seller-2, or call the poller yourself:
// app(OrderEventPoller::class)->poll(Allegro::forUser($key));
```

```php
use Beziworld\Allegro\Data\OrderEvent;
use Beziworld\Allegro\Events\OrderEventReceived;
use Beziworld\Allegro\Facades\Allegro;

Event::listen(function (OrderEventReceived $received): void {
    if ($received->event->type !== OrderEvent::READY_FOR_PROCESSING) {
        return;
    }

    $order = Allegro::forUser($received->account)->orders()->checkoutForm($received->event->checkoutFormId);

    $order->buyer->login;
    $order->delivery?->address?->fullName();
    $order->delivery?->pickupPointId;          // parcel locker or pickup point
    $order->invoice?->taxId;                   // NIP when the buyer wants an invoice
    $order->payment?->isCashOnDelivery();
    $order->totalToPay?->amount;               // "123.45" (string)
    foreach ($order->lineItems as $item) {
        $item->externalId;                     // your SKU (offer external.id)
        $item->quantity;
        $item->price?->amount;
    }
});
```

The cursor is saved after each event and a lock prevents overlapping runs. Allegro may repeat events or deliver them out
of order, so key your processing on `checkoutFormId` and make listeners idempotent. Address and invoice data are final
only at `READY_FOR_PROCESSING`. `allegro:poll-offers` does the same for `/sale/offer-events` (`OfferEventReceived`; the
journal keeps 24 hours).

### Listing and reading orders

```php
$page = Allegro::orders()->checkoutForms([
    'status' => 'READY_FOR_PROCESSING',
    'fulfillment.status' => ['NEW', 'PROCESSING'],
    'lineItems.boughtAt.gte' => now()->subDay(),
    'sort' => '-lineItems.boughtAt',
], limit: 100, offset: 0);                      // $page->checkoutForms, $page->totalCount

foreach (Allegro::orders()->allCheckoutForms(['status' => 'READY_FOR_PROCESSING']) as $order) {
    // every page, within the 10 000 orders window of the API
}
```

### Fulfillment and tracking numbers

```php
use Beziworld\Allegro\Enums\FulfillmentStatus;

Allegro::orders()->setFulfillmentStatus($order->id, FulfillmentStatus::ReadyForShipment, $order->revision);
// a stale revision throws ApiException with isConflict() === true

Allegro::orders()->carriers();                                         // ids such as INPOST, DPD, DHL, POCZTA_POLSKA
Allegro::orders()->addTrackingNumber($order->id, 'INPOST', '620000000000000');
Allegro::orders()->addTrackingNumber($order->id, 'OTHER', 'X123', 'Local courier', [$lineItemId]);
Allegro::orders()->shipments($order->id);
Allegro::orders()->setFulfillmentStatus($order->id, FulfillmentStatus::Sent);
```

### Invoices

```php
$documentId = Allegro::orders()->uploadInvoice($order->id, $pdfContents, invoiceNumber: 'FV/1/11/2026');
Allegro::orders()->addBillingDocumentLink($order->id, 'https://shop.example/invoices/1.pdf');
Allegro::orders()->billingDocuments($order->id);
```

## Offers

```php
use Beziworld\Allegro\Data\Money;

$page = Allegro::offers()->list(['publication.status' => ['ACTIVE'], 'external.id' => 'SKU-1'], limit: 1000);
$offer = Allegro::offers()->get('7712345678');           // ProductOffer: price, stockAvailable, status, raw

$result = Allegro::offers()->create([
    'productSet' => [['product' => ['id' => $productId]]],
    'sellingMode' => ['price' => Money::of(49.99)->toArray()],
    'stock' => ['available' => 10],
    'external' => ['id' => 'SKU-1'],
    'publication' => ['status' => 'ACTIVE'],
]);
$offer = Allegro::offers()->waitForOperation($result);   // polls the operation when Allegro answered 202

Allegro::offers()->edit('7712345678', ['stock' => ['available' => 5]]);

// Batch commands (a UUID commandId is generated unless you pass one)
$report = Allegro::offers()->changePrice(['7712345678', '7712345679'], Money::of('39.90'));
$report = Allegro::offers()->changeQuantity(['7712345678'], 3, 'GAIN');
Allegro::offers()->priceChangeReport($report->id)->isCompleted();
Allegro::offers()->priceChangeTasks($report->id);         // per-offer SUCCESS / FAIL with errors
```

## Wysyłam z Allegro

```php
$wza = Allegro::shipmentManagement();

$proposal = $wza->deliveryProposals($order->id);         // recommended input for the shipment
$command = $wza->createShipment([
    'deliveryMethodId' => $order->delivery->methodId,
    'sender' => [...], 'receiver' => [...],
    'packages' => [['type' => 'PACKAGE',
        'length' => ['value' => 30, 'unit' => 'CENTIMETER'], 'width' => ['value' => 20, 'unit' => 'CENTIMETER'],
        'height' => ['value' => 10, 'unit' => 'CENTIMETER'], 'weight' => ['value' => 1.5, 'unit' => 'KILOGRAMS']]],
    'labelFormat' => 'PDF',
]);
$shipment = $wza->waitForShipment($command);              // Shipment: id, carrier, waybills

$pdf = $wza->label([$shipment->id], 'A6');                // null when no label is available
$protocol = $wza->protocol([$shipment->id]);              // null for carriers without protocols
$slots = $wza->pickupProposals([$shipment->id], $pickupAddress, now()->addDay());
$wza->createPickup([$shipment->id], $pickupAddress, ['date' => '2026-11-05', 'minTime' => '10:00', 'maxTime' => '14:00']);
$wza->waitForCancellation($wza->cancelShipment($shipment->id));
```

## Other resources

```php
Allegro::billing()->entries(['occurredAt.gte' => now()->subMonth(), 'type.id' => ['SUC']]);
Allegro::messaging()->threads(['read' => false]);
Allegro::messaging()->reply($threadId, 'Your parcel ships today.');
Allegro::messaging()->send('buyer-login', 'Thank you!', $orderId, [Allegro::messaging()->uploadAttachment('invoice.pdf', $pdf)]);
Allegro::customerReturns()->list(['status' => 'DELIVERED']);       // beta media type
Allegro::customerReturns()->rejectRefund($returnId, 'REFUND_REJECTED', 'Item was used');
Allegro::catalog()->matchingCategories('wool socks');
Allegro::catalog()->searchProducts('5901234123457', 'GTIN');
Allegro::catalog()->parameters('257931');

// Any other endpoint
Allegro::request('GET', '/sale/shipping-rates');
Allegro::request('PUT', "/sale/offer-modification-commands/{$uuid}", body: [...]);
Allegro::send('GET', '/sale/offers', ['limit' => 1])->header('Trace-Id');   // raw Response
```

## Errors and rate limits

| Situation | Exception | Useful methods |
|---|---|---|
| HTTP 400/422 validation | `ApiException` | `errors()` (code, message, details, path, userMessage), `userMessage()`, `isValidationError()` |
| HTTP 401 after one refresh and retry | `ApiException` | `httpStatus()`, OAuth `error` mapped to `errors()[0]['code']` |
| HTTP 404 | `ApiException` | `isNotFound()` |
| HTTP 409 (stale revision, reused commandId) | `ApiException` | `isConflict()` |
| HTTP 429 (9000 requests/min per Client ID, per-resource limits) | `ApiException` | `isRateLimited()`, `retryAfter()` |
| Connection failure | `ApiException` | `getPrevious()` |
| Failed WzA command, operation still running | `ApiException` | `errors()` |
| Missing token, revoked refresh token, denied device flow | `AuthenticationException` | `requiresReauthorization()`, `error()` |
| Missing configuration | `InvalidConfiguration` | |

Every exception extends `AllegroException`. `traceId()` (and `lastTraceId()` on the client) returns the `Trace-Id`
header that Allegro support asks for; set `ALLEGRO_LOG_CHANNEL` to log failed requests with it. The client never
repeats a request automatically, except once after HTTP 401 (a rejected request was not processed). Back off on 429:
exceeding the global limit blocks the Client ID for a minute.

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues: [GitHub issues](https://github.com/beziworld/laravel-allegro/issues)

Need an Allegro or marketplace integration? Get in touch through [beziworld.eu](https://beziworld.eu).

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with, endorsed or sponsored by Allegro.pl sp. z o.o.
