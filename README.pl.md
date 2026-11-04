# Laravel Allegro

[![tests](https://github.com/beziworld/laravel-allegro/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-allegro/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-allegro.svg)](https://packagist.org/packages/beziworld/laravel-allegro)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-allegro.svg)](LICENSE.md)

Klient [Allegro REST API](https://developer.allegro.pl) dla Laravela, dla sklepów i platform SaaS obsługujących jednego
lub wielu sprzedawców Allegro:

- OAuth2: authorization code (z PKCE), device flow i client credentials; rotowane refresh tokeny odświeżane pod blokadą
  i przechowywane w wymiennym magazynie tokenów
- wiele kont sprzedawców w jednej aplikacji: konta nazwane w konfiguracji i sprzedawcy podłączani w trakcie działania
- zamówienia: odpytywanie dziennika zdarzeń z eventami Laravela, formularze zamówień, status realizacji, numery
  przesyłek, faktury
- oferty: lista, tworzenie i edycja przez `/sale/product-offers` (z odpytywaniem operacji asynchronicznych), masowa
  zmiana cen i stanów, dziennik zdarzeń ofert
- Wysyłam z Allegro: przesyłki, etykiety (PDF/ZPL, A4/A6), protokoły nadania, zamawianie kuriera
- rozliczenia, centrum wiadomości, zwroty (beta), kategorie, parametry i katalog produktów
- typowane, niemutowalne DTO (`Money` trzyma kwoty jako stringi) i ogólne `request()` dla pozostałych zasobów

[English documentation](README.md)

## Instalacja

```bash
composer require beziworld/laravel-allegro
php artisan vendor:publish --tag=allegro-config   # opcjonalnie
```

Zarejestruj aplikację na [apps.developer.allegro.pl](https://apps.developer.allegro.pl/) (sandbox:
[apps.developer.allegro.pl.allegrosandbox.pl](https://apps.developer.allegro.pl.allegrosandbox.pl/)).

```dotenv
ALLEGRO_CLIENT_ID=twoj-client-id
ALLEGRO_CLIENT_SECRET=twoj-client-secret
ALLEGRO_SANDBOX=false                                    # true = *.allegrosandbox.pl
ALLEGRO_REDIRECT_URI=https://sklep.pl/allegro/callback
ALLEGRO_USER_AGENT="MojSklep/1.0 (+https://sklep.pl)"    # obowiązkowy, patrz niżej
ALLEGRO_CACHE_STORE=redis                                # wspólny magazyn tokenów, blokad i kursorów
```

Allegro wymaga, aby każda integracja wysyłała nagłówek `User-Agent` w formacie `NazwaAplikacji/Wersja (+https://strona-informacyjna)`,
zgodny z nazwą zarejestrowanej aplikacji; bez niego pakiet się nie uruchomi. Żądania zawierają też
`Accept`/`Content-Type: application/vnd.allegro.public.v1+json` (dla zasobów beta typ beta) oraz `Accept-Language`
(`ALLEGRO_LANGUAGE`, domyślnie `pl-PL`), który ustala język komunikatów błędów.

## Podłączenie sprzedawcy

### Authorization code z PKCE

```php
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\OAuth\Pkce;

// Przekierowanie
$verifier = Pkce::generateVerifier();
session(['allegro_verifier' => $verifier, 'allegro_state' => $state = Str::random(40)]);

return redirect(Allegro::authorizationUrl($state, $verifier));

// Callback (kod jest ważny kilka sekund)
abort_unless($request->query('state') === session('allegro_state'), 403);

Allegro::exchangeCode($request->query('code'), session('allegro_verifier'));            // konto domyślne
Allegro::forUser('seller-' . $seller->id)->exchangeCode($request->query('code'), session('allegro_verifier')); // SaaS
```

Bez PKCE wywołaj `authorizationUrl($state)` i `exchangeCode($code)`. Trzecim argumentem (lub `allegro.scopes`)
ograniczysz zakresy uprawnień, a czwartym (`'confirm'`) pokażesz ekran potwierdzenia konta.

### Device flow

Dla narzędzi zaplecza i terminala (aplikacja musi być zarejestrowana jako typ „device”):

```bash
php artisan allegro:device-login            # konto domyślne
php artisan allegro:device-login second     # konto nazwane
php artisan allegro:device-login --user=seller-42
```

Albo w kodzie:

```php
$code = Allegro::startDeviceFlow();
// pokaż $code->verificationUri i $code->formattedUserCode() albo klikalny $code->verificationUriComplete

$token = Allegro::forUser('seller-42')->waitForDeviceToken($code);   // respektuje "interval" i "slow_down"
// lub odpytuj samodzielnie: Allegro::forUser('seller-42')->pollDeviceToken($code)->isGranted()
```

### Token aplikacji

Zasoby publiczne (kategorie, parametry, przewoźnicy, typy opłat) przyjmują token client credentials:

```php
Allegro::application()->catalog()->categories();
```

### Tokeny

Access token jest ważny 12 godzin, refresh token 3 miesiące i jest **jednorazowy**: każde odświeżenie zwraca nową parę,
a poprzedni refresh token działa jeszcze tylko 60 sekund. Klient odświeża wygasłe tokeny pod blokadą w cache, po jej
uzyskaniu ponownie czyta magazyn i od razu zapisuje nową parę. Po HTTP 401 odświeża token raz i powtarza żądanie.

Domyślnie tokeny trafiają do cache (`CacheTokenStore`, TTL 90 dni). Aby trzymać je w bazie danych, zaimplementuj
`Beziworld\Allegro\Contracts\TokenStore` (`get`, `put`, `forget`; kluczem jest nazwa konta lub klucz z `forUser()`)
i wskaż swoją klasę w `allegro.token_store` albo zarejestruj interfejs w kontenerze. Konto można też zasilić refresh
tokenem z konfiguracji (`ALLEGRO_REFRESH_TOKEN`).

Gdy Allegro cofnie autoryzację (zmiana hasła, odłączenie aplikacji, wygaśnięcie refresh tokena), wywołania rzucają
`AuthenticationException` z `requiresReauthorization() === true`: poproś sprzedawcę o ponowne podłączenie konta.

## Wiele kont sprzedawców

Jedna aplikacja obsługuje dowolną liczbę sprzedawców, każdego z własnymi tokenami:

```php
// config/allegro.php
'accounts' => [
    'default' => ['refresh_token' => env('ALLEGRO_REFRESH_TOKEN')],
    'outlet' => ['token_key' => 'outlet-shop', 'language' => 'en-US'],
],
```

```php
Allegro::orders()->checkoutForms();                       // konto domyślne
Allegro::account('outlet')->orders()->checkoutForms();    // konto nazwane
Allegro::forUser('seller-42')->orders()->checkoutForms(); // sprzedawca podłączony w trakcie działania (SaaS)
Allegro::forUser('seller-42')->isConnected();
Allegro::forUser('seller-42')->disconnect();
```

Konto może nadpisać każdą wspólną opcję, także `client_id`, `client_secret` i `sandbox`.

## Zamówienia

### Odpytywanie nowych zamówień

Allegro nie ma webhooków; zmiany odczytuje się z dzienników zdarzeń. `allegro:poll-orders` czyta `/order/events` od
ostatnio zapisanego identyfikatora zdarzenia i dla każdego nowego zdarzenia wysyła event `OrderEventReceived`. Przy
pierwszym uruchomieniu dla konta zaczyna od najnowszego zdarzenia (ustaw `allegro.events.start` na `beginning`, aby
odtworzyć ostatnie 60 dni).

```php
// routes/console.php
Schedule::command('allegro:poll-orders --all')->everyMinute()->withoutOverlapping();
// sprzedawcy podłączeni w trakcie działania: --user=seller-1 --user=seller-2 albo bezpośrednio:
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
    $order->delivery?->pickupPointId;          // paczkomat lub punkt odbioru
    $order->invoice?->taxId;                   // NIP, gdy kupujący chce fakturę
    $order->payment?->isCashOnDelivery();
    $order->totalToPay?->amount;               // "123.45" (string)
    foreach ($order->lineItems as $item) {
        $item->externalId;                     // twój SKU (external.id oferty)
        $item->quantity;
        $item->price?->amount;
    }
});
```

Kursor zapisuje się po każdym zdarzeniu, a blokada nie dopuszcza do nakładania się uruchomień. Allegro może powtarzać
zdarzenia lub dostarczać je w innej kolejności, dlatego opieraj przetwarzanie na `checkoutFormId`, a listenery pisz
idempotentnie. Dane adresowe i do faktury są ostateczne dopiero w statusie `READY_FOR_PROCESSING`. `allegro:poll-offers`
robi to samo dla `/sale/offer-events` (`OfferEventReceived`; dziennik przechowuje 24 godziny).

### Lista i szczegóły zamówień

```php
$page = Allegro::orders()->checkoutForms([
    'status' => 'READY_FOR_PROCESSING',
    'fulfillment.status' => ['NEW', 'PROCESSING'],
    'lineItems.boughtAt.gte' => now()->subDay(),
    'sort' => '-lineItems.boughtAt',
], limit: 100, offset: 0);                      // $page->checkoutForms, $page->totalCount

foreach (Allegro::orders()->allCheckoutForms(['status' => 'READY_FOR_PROCESSING']) as $order) {
    // wszystkie strony, w granicach okna 10 000 zamówień API
}
```

### Realizacja i numery przesyłek

```php
use Beziworld\Allegro\Enums\FulfillmentStatus;

Allegro::orders()->setFulfillmentStatus($order->id, FulfillmentStatus::ReadyForShipment, $order->revision);
// nieaktualna rewizja rzuca ApiException z isConflict() === true

Allegro::orders()->carriers();                                         // np. INPOST, DPD, DHL, POCZTA_POLSKA
Allegro::orders()->addTrackingNumber($order->id, 'INPOST', '620000000000000');
Allegro::orders()->addTrackingNumber($order->id, 'OTHER', 'X123', 'Kurier lokalny', [$lineItemId]);
Allegro::orders()->shipments($order->id);
Allegro::orders()->setFulfillmentStatus($order->id, FulfillmentStatus::Sent);
```

### Faktury

```php
$documentId = Allegro::orders()->uploadInvoice($order->id, $pdfContents, invoiceNumber: 'FV/1/11/2026');
Allegro::orders()->addBillingDocumentLink($order->id, 'https://sklep.pl/faktury/1.pdf');
Allegro::orders()->billingDocuments($order->id);
```

## Oferty

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
$offer = Allegro::offers()->waitForOperation($result);   // odpytuje operację, gdy Allegro odpowiedziało 202

Allegro::offers()->edit('7712345678', ['stock' => ['available' => 5]]);

// Komendy masowe (commandId w formacie UUID jest generowany, jeśli go nie podasz)
$report = Allegro::offers()->changePrice(['7712345678', '7712345679'], Money::of('39.90'));
$report = Allegro::offers()->changeQuantity(['7712345678'], 3, 'GAIN');
Allegro::offers()->priceChangeReport($report->id)->isCompleted();
Allegro::offers()->priceChangeTasks($report->id);         // wynik SUCCESS / FAIL dla każdej oferty z błędami
```

## Wysyłam z Allegro

```php
$wza = Allegro::shipmentManagement();

$proposal = $wza->deliveryProposals($order->id);         // zalecane dane wejściowe przesyłki
$command = $wza->createShipment([
    'deliveryMethodId' => $order->delivery->methodId,
    'sender' => [...], 'receiver' => [...],
    'packages' => [['type' => 'PACKAGE',
        'length' => ['value' => 30, 'unit' => 'CENTIMETER'], 'width' => ['value' => 20, 'unit' => 'CENTIMETER'],
        'height' => ['value' => 10, 'unit' => 'CENTIMETER'], 'weight' => ['value' => 1.5, 'unit' => 'KILOGRAMS']]],
    'labelFormat' => 'PDF',
]);
$shipment = $wza->waitForShipment($command);              // Shipment: id, carrier, waybills

$pdf = $wza->label([$shipment->id], 'A6');                // null, gdy etykieta jest niedostępna
$protocol = $wza->protocol([$shipment->id]);              // null dla przewoźników bez protokołów
$slots = $wza->pickupProposals([$shipment->id], $pickupAddress, now()->addDay());
$wza->createPickup([$shipment->id], $pickupAddress, ['date' => '2026-11-05', 'minTime' => '10:00', 'maxTime' => '14:00']);
$wza->waitForCancellation($wza->cancelShipment($shipment->id));
```

## Pozostałe zasoby

```php
Allegro::billing()->entries(['occurredAt.gte' => now()->subMonth(), 'type.id' => ['SUC']]);
Allegro::messaging()->threads(['read' => false]);
Allegro::messaging()->reply($threadId, 'Paczka wyjeżdża dziś.');
Allegro::messaging()->send('login-kupujacego', 'Dziękujemy!', $orderId, [Allegro::messaging()->uploadAttachment('faktura.pdf', $pdf)]);
Allegro::customerReturns()->list(['status' => 'DELIVERED']);       // typ mediów beta
Allegro::customerReturns()->rejectRefund($returnId, 'REFUND_REJECTED', 'Produkt był używany');
Allegro::catalog()->matchingCategories('skarpety wełniane');
Allegro::catalog()->searchProducts('5901234123457', 'GTIN');
Allegro::catalog()->parameters('257931');

// Dowolny inny zasób
Allegro::request('GET', '/sale/shipping-rates');
Allegro::request('PUT', "/sale/offer-modification-commands/{$uuid}", body: [...]);
Allegro::send('GET', '/sale/offers', ['limit' => 1])->header('Trace-Id');   // surowa odpowiedź
```

## Błędy i limity

| Sytuacja | Wyjątek | Przydatne metody |
|---|---|---|
| HTTP 400/422, walidacja | `ApiException` | `errors()` (code, message, details, path, userMessage), `userMessage()`, `isValidationError()` |
| HTTP 401 po jednym odświeżeniu i ponowieniu | `ApiException` | `httpStatus()`, `error` z OAuth w `errors()[0]['code']` |
| HTTP 404 | `ApiException` | `isNotFound()` |
| HTTP 409 (nieaktualna rewizja, powtórzony commandId) | `ApiException` | `isConflict()` |
| HTTP 429 (9000 żądań/min na Client ID, limity zasobów) | `ApiException` | `isRateLimited()`, `retryAfter()` |
| Błąd połączenia | `ApiException` | `getPrevious()` |
| Nieudana komenda WzA, operacja wciąż trwa | `ApiException` | `errors()` |
| Brak tokena, cofnięty refresh token, odmowa w device flow | `AuthenticationException` | `requiresReauthorization()`, `error()` |
| Brak konfiguracji | `InvalidConfiguration` | |

Wszystkie wyjątki dziedziczą po `AllegroException`. `traceId()` (oraz `lastTraceId()` klienta) zwraca nagłówek
`Trace-Id`, o który prosi wsparcie Allegro; ustaw `ALLEGRO_LOG_CHANNEL`, aby logować z nim nieudane żądania. Klient
nigdy nie powtarza żądań automatycznie, z wyjątkiem jednego ponowienia po HTTP 401 (odrzucone żądanie nie zostało
przetworzone). Przy 429 zwolnij: przekroczenie globalnego limitu blokuje Client ID na minutę.

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (poziom 8), pest
```

## Autor

Pakiet tworzy i rozwija **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia: [GitHub issues](https://github.com/beziworld/laravel-allegro/issues)

Potrzebujesz integracji z Allegro lub innym marketplace? Zapraszam do kontaktu przez [beziworld.eu](https://beziworld.eu).

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z Allegro.pl sp. z o.o. ani przez nią wspierany.
