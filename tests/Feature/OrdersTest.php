<?php

declare(strict_types=1);

use Beziworld\Allegro\Data\OrderEvent;
use Beziworld\Allegro\Enums\FulfillmentStatus;
use Beziworld\Allegro\Enums\OrderStatus;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function checkoutFormPayload(string $id = '29738e61-7f6a-11e8-ac45-09db60ede9d6'): array
{
    return [
        'id' => $id,
        'messageToSeller' => 'Proszę o szybką wysyłkę',
        'buyer' => [
            'id' => '23123123', 'email' => 'pokxxx@allegromail.pl', 'login' => 'User_1', 'firstName' => 'Jan', 'lastName' => 'Kowalski',
            'companyName' => null, 'guest' => false, 'personalIdentity' => null, 'phoneNumber' => '600700800',
            'preferences' => ['language' => 'pl-PL'],
            'address' => ['street' => 'Grunwaldzka 182', 'city' => 'Poznań', 'postCode' => '60-166', 'countryCode' => 'PL'],
        ],
        'payment' => [
            'id' => '0f8f1d13-7e9e-11e8-9b00-c5b0dfb78ea6', 'type' => 'CASH_ON_DELIVERY', 'provider' => 'P24',
            'finishedAt' => '2018-10-12T10:12:32.321Z', 'paidAmount' => ['amount' => '123.45', 'currency' => 'PLN'], 'reconciliation' => null, 'features' => [],
        ],
        'status' => 'READY_FOR_PROCESSING',
        'fulfillment' => ['status' => 'NEW', 'shipmentSummary' => ['lineItemsSent' => 'NONE'], 'provider' => ['id' => 'SELLER']],
        'delivery' => [
            'address' => ['firstName' => 'Jan', 'lastName' => 'Kowalski', 'street' => 'Grunwaldzka 182', 'city' => 'Poznań', 'zipCode' => '60-166', 'countryCode' => 'PL', 'companyName' => null, 'phoneNumber' => '600700800', 'modifiedAt' => null],
            'method' => ['id' => '1fa56f79-4b6a-4821-a6f2-ca9c16d5c925', 'name' => 'Allegro Paczkomaty InPost'],
            'pickupPoint' => ['id' => 'POZ08A', 'name' => 'Paczkomat POZ08A', 'description' => 'Stacja paliw BP', 'address' => ['street' => 'Grunwaldzka 108', 'zipCode' => '60-166', 'city' => 'Poznań', 'countryCode' => 'PL']],
            'cost' => ['amount' => '9.99', 'currency' => 'PLN'],
            'time' => ['from' => null, 'to' => null, 'guaranteed' => null, 'dispatch' => ['from' => '2018-10-13T08:00:00Z', 'to' => '2018-10-14T16:00:00Z']],
            'smart' => true,
            'cancellation' => null,
            'calculatedNumberOfPackages' => 1,
        ],
        'invoice' => [
            'required' => true,
            'address' => [
                'street' => 'Grunwaldzka 182', 'city' => 'Poznań', 'zipCode' => '60-166', 'countryCode' => 'PL',
                'company' => ['name' => 'Udix Sp. z o.o.', 'ids' => [['type' => 'PL_NIP', 'value' => '5261040828']], 'vatPayerStatus' => 'ACTIVE', 'taxId' => '5261040828'],
                'naturalPerson' => null,
            ],
            'dueDate' => '2018-10-20',
            'features' => [],
        ],
        'lineItems' => [[
            'id' => '62ae358b-8f65-4fc4-9c77-bedf604a2e2b',
            'offer' => ['id' => '9206571541', 'name' => 'Skarpety', 'external' => ['id' => 'SKU-1'], 'productSet' => null],
            'quantity' => 2,
            'originalPrice' => ['amount' => '60.00', 'currency' => 'PLN'],
            'price' => ['amount' => '56.73', 'currency' => 'PLN'],
            'boughtAt' => '2018-10-12T08:22:12.000Z',
        ]],
        'surcharges' => [],
        'discounts' => [],
        'note' => ['text' => 'VIP'],
        'marketplace' => ['id' => 'allegro-pl'],
        'summary' => ['totalToPay' => ['amount' => '123.45', 'currency' => 'PLN']],
        'updatedAt' => '2018-10-12T10:12:32.321Z',
        'revision' => '819b5836',
    ];
}

it('reads order events with a cursor, repeated type filters and typed payloads', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/events*' => Http::response(['events' => [[
        'id' => 'MTEzMjQzODU3NA',
        'occurredAt' => '2018-10-12T10:12:32.321Z',
        'type' => 'READY_FOR_PROCESSING',
        'order' => [
            'seller' => ['id' => '40507'],
            'buyer' => ['id' => '23123123', 'email' => 'pokxxx@allegromail.pl', 'login' => 'User_1', 'guest' => false, 'preferences' => ['language' => 'pl-PL']],
            'lineItems' => [['id' => 'li-1', 'offer' => ['id' => '9206571541', 'name' => 'Skarpety', 'external' => ['id' => 'SKU-1']], 'quantity' => 1, 'originalPrice' => ['amount' => '10.00', 'currency' => 'PLN'], 'price' => ['amount' => '10.00', 'currency' => 'PLN'], 'boughtAt' => '2018-10-12T10:12:32.321Z']],
            'checkoutForm' => ['id' => '29738e61-7f6a-11e8-ac45-09db60ede9d6', 'revision' => '819b5836'],
            'marketplace' => ['id' => 'allegro-pl'],
        ],
    ]]])]);

    $events = Allegro::orders()->events('MTEzMjQzODU3Mw', [OrderEvent::BOUGHT, OrderEvent::READY_FOR_PROCESSING], 5000);

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(OrderEvent::READY_FOR_PROCESSING)
        ->and($events[0]->checkoutFormId)->toBe('29738e61-7f6a-11e8-ac45-09db60ede9d6')
        ->and($events[0]->revision)->toBe('819b5836')
        ->and($events[0]->sellerId)->toBe('40507')
        ->and($events[0]->buyer?->login)->toBe('User_1')
        ->and($events[0]->lineItems[0]->externalId)->toBe('SKU-1')
        ->and($events[0]->lineItems[0]->price?->amount)->toBe('10.00')
        ->and($events[0]->occurredAt?->toIso8601ZuluString())->toBe('2018-10-12T10:12:32Z')
        ->and($events[0]->isCancellation())->toBeFalse();

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/order/events?from=MTEzMjQzODU3Mw&type=BOUGHT&type=READY_FOR_PROCESSING&limit=1000'
        && hasAllegroHeaders($request));
});

it('reads the id of the newest order event', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/event-stats' => Http::response(['latestEvent' => ['id' => 'MTEzMjQzODU3NA', 'occurredAt' => '2018-10-12T10:12:32.321Z']])]);

    expect(Allegro::orders()->latestEventId())->toBe('MTEzMjQzODU3NA');
});

it('lists checkout forms with filters and maps every part of the order', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/checkout-forms*' => Http::response(['checkoutForms' => [checkoutFormPayload()], 'count' => 1, 'totalCount' => 1])]);

    $list = Allegro::orders()->checkoutForms([
        'status' => 'READY_FOR_PROCESSING',
        'fulfillment.status' => ['NEW', 'PROCESSING'],
        'lineItems.boughtAt.gte' => CarbonImmutable::parse('2026-11-01 12:00:00', 'Europe/Warsaw'),
    ], 500, 200);

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/order/checkout-forms?status=READY_FOR_PROCESSING&fulfillment.status=NEW&fulfillment.status=PROCESSING&lineItems.boughtAt.gte=2026-11-01T11%3A00%3A00.000Z&limit=100&offset=200');

    $order = $list->checkoutForms[0];

    expect($list->totalCount)->toBe(1)
        ->and($order->orderStatus())->toBe(OrderStatus::ReadyForProcessing)
        ->and($order->isReadyForProcessing())->toBeTrue()
        ->and($order->fulfillment())->toBe(FulfillmentStatus::New)
        ->and($order->revision)->toBe('819b5836')
        ->and($order->buyer->email)->toBe('pokxxx@allegromail.pl')
        ->and($order->buyer->address?->zipCode)->toBe('60-166')
        ->and($order->buyer->language)->toBe('pl-PL')
        ->and($order->payment?->isCashOnDelivery())->toBeTrue()
        ->and($order->payment?->paidAmount?->amount)->toBe('123.45')
        ->and($order->delivery?->methodName)->toBe('Allegro Paczkomaty InPost')
        ->and($order->delivery?->isPickupPoint())->toBeTrue()
        ->and($order->delivery?->pickupPointId)->toBe('POZ08A')
        ->and($order->delivery?->pickupPointAddress?->city)->toBe('Poznań')
        ->and($order->delivery?->address?->fullName())->toBe('Jan Kowalski')
        ->and($order->delivery?->cost?->amount)->toBe('9.99')
        ->and($order->delivery?->smart)->toBeTrue()
        ->and($order->delivery?->dispatchTo?->toDateString())->toBe('2018-10-14')
        ->and($order->invoiceRequired())->toBeTrue()
        ->and($order->invoice?->taxId)->toBe('5261040828')
        ->and($order->invoice?->companyName)->toBe('Udix Sp. z o.o.')
        ->and($order->invoice?->companyIds)->toBe([['type' => 'PL_NIP', 'value' => '5261040828']])
        ->and($order->invoice?->address?->companyName)->toBe('Udix Sp. z o.o.')
        ->and($order->lineItems[0]->quantity)->toBe(2)
        ->and($order->lineItems[0]->originalPrice?->amount)->toBe('60.00')
        ->and((string) $order->totalToPay)->toBe('123.45 PLN')
        ->and($order->note)->toBe('VIP')
        ->and($order->messageToSeller)->toBe('Proszę o szybką wysyłkę')
        ->and($order->marketplaceId)->toBe('allegro-pl')
        ->and($order->lineItemsSent)->toBe('NONE')
        ->and($order->isFulfilledByAllegro())->toBeFalse()
        ->and($order->raw['revision'])->toBe('819b5836');
});

it('iterates over all pages of checkout forms', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/checkout-forms*' => Http::sequence()
        ->push(['checkoutForms' => [checkoutFormPayload('a'), checkoutFormPayload('b')], 'count' => 2, 'totalCount' => 3])
        ->push(['checkoutForms' => [checkoutFormPayload('c')], 'count' => 1, 'totalCount' => 3])]);

    $ids = array_map(fn($form): string => $form->id, iterator_to_array(Allegro::orders()->allCheckoutForms(['status' => 'READY_FOR_PROCESSING'], 2), false));

    expect($ids)->toBe(['a', 'b', 'c']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), 'limit=2&offset=2'));
    Http::assertSentCount(2);
});

it('reads one checkout form', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/checkout-forms/29738e61-7f6a-11e8-ac45-09db60ede9d6' => Http::response(checkoutFormPayload())]);

    expect(Allegro::orders()->checkoutForm('29738e61-7f6a-11e8-ac45-09db60ede9d6')->id)->toBe('29738e61-7f6a-11e8-ac45-09db60ede9d6');
});

it('changes the fulfillment status with the checkout form revision', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/checkout-forms/*/fulfillment*' => Http::response(null, 204)]);

    Allegro::orders()->setFulfillmentStatus('order-1', FulfillmentStatus::ReadyForShipment, '819b5836');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT'
        && $request->url() === TestCase::API . '/order/checkout-forms/order-1/fulfillment?checkoutForm.revision=819b5836'
        && $request->hasHeader('Content-Type', 'application/vnd.allegro.public.v1+json')
        && hasAllegroHeaders($request)
        && ($request->data()['status'] ?? null) === 'READY_FOR_SHIPMENT');
});

it('adds a tracking number to selected line items', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/checkout-forms/order-1/shipments' => Http::response([
        'id' => '09ac3e6a-f6bd-4cca-8a5b-5b4f6b7f1a43', 'waybill' => '620000000000000', 'carrierId' => 'INPOST', 'carrierName' => null,
        'lineItems' => [['id' => 'li-1']], 'createdAt' => '2026-11-04T10:00:00Z',
    ], 201)]);

    $shipment = Allegro::orders()->addTrackingNumber('order-1', 'INPOST', '620000000000000', null, ['li-1']);

    expect($shipment->waybill)->toBe('620000000000000')
        ->and($shipment->lineItemIds)->toBe(['li-1']);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && jsonBody($request) === ['carrierId' => 'INPOST', 'waybill' => '620000000000000', 'lineItems' => [['id' => 'li-1']]]);
});

it('adds a tracking number of a carrier outside the list', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/checkout-forms/order-1/shipments' => Http::response(['id' => 's1', 'waybill' => 'X1', 'carrierId' => 'OTHER', 'carrierName' => 'Kurier lokalny'], 201)]);

    Allegro::orders()->addTrackingNumber('order-1', 'OTHER', 'X1', 'Kurier lokalny');

    Http::assertSent(fn(Request $request): bool => jsonBody($request) === ['carrierId' => 'OTHER', 'waybill' => 'X1', 'carrierName' => 'Kurier lokalny']);
});

it('lists tracking numbers, carriers and parcel tracking', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/order/checkout-forms/order-1/shipments' => Http::response(['shipments' => [['id' => 's1', 'waybill' => 'W1', 'carrierId' => 'DPD', 'carrierName' => null, 'createdAt' => '2026-11-04T10:00:00Z']]]),
        TestCase::API . '/order/carriers' => Http::response(['carriers' => [['id' => 'INPOST', 'name' => 'InPost'], ['id' => 'DPD', 'name' => 'DPD']]]),
        TestCase::API . '/order/carriers/DPD/tracking*' => Http::response(['carrierId' => 'DPD', 'waybills' => [['waybill' => 'W1', 'trackingDetails' => null]]]),
    ]);

    expect(Allegro::orders()->shipments('order-1')[0]->carrierId)->toBe('DPD')
        ->and(Allegro::orders()->carriers()[1]->name)->toBe('DPD')
        ->and(Allegro::orders()->tracking('DPD', ['W1', 'W2'])['waybills'][0]['waybill'])->toBe('W1');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/order/carriers/DPD/tracking?waybill=W1&waybill=W2');
});

it('uploads an invoice file as multipart form data', function (): void {
    connected();
    Http::fake([TestCase::API . '/order/order-1/billing-documents/files' => Http::response(['id' => 'b0373e5b-d6b5-4457-a358-a1d35ec41426'], 201)]);

    $id = Allegro::orders()->uploadInvoice('order-1', '%PDF-1.4 test', 'FV/1/11/2026', 'fv-1.pdf');

    expect($id)->toBe('b0373e5b-d6b5-4457-a358-a1d35ec41426');
    Http::assertSent(function (Request $request): bool {
        $parts = collect($request->data())->keyBy('name');

        return $request->isMultipart()
            && hasAllegroHeaders($request)
            && $parts['file']['contents'] === '%PDF-1.4 test'
            && $parts['file']['filename'] === 'fv-1.pdf'
            && $parts['invoiceNumber']['contents'] === 'FV/1/11/2026';
    });
});

it('adds a billing document link and lists billing documents', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/order/order-1/billing-documents/links' => Http::response(null, 201),
        TestCase::API . '/order/checkout-forms/order-1/invoices' => Http::response(['invoices' => [['id' => 'inv-1', 'invoiceNumber' => 'FV/1']], 'links' => [], 'hasExternalInvoices' => false]),
    ]);

    Allegro::orders()->addBillingDocumentLink('order-1', 'https://shop.test/invoices/1.pdf');

    expect(Allegro::orders()->billingDocuments('order-1')['invoices'][0]['invoiceNumber'])->toBe('FV/1');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && jsonBody($request) === ['url' => 'https://shop.test/invoices/1.pdf']);
});
