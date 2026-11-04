<?php

declare(strict_types=1);

use Beziworld\Allegro\Data\ShipmentCommandStatus;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @return array<string, mixed>
 */
function shipmentInput(): array
{
    $address = ['name' => 'Jan Kowalski', 'street' => 'Grunwaldzka 182', 'postalCode' => '60-166', 'city' => 'Poznań', 'countryCode' => 'PL', 'email' => 'jan@example.com', 'phone' => '600700800'];

    return [
        'deliveryMethodId' => '1fa56f79-4b6a-4821-a6f2-ca9c16d5c925',
        'sender' => $address,
        'receiver' => $address + ['point' => 'POZ08A'],
        'packages' => [[
            'type' => 'PACKAGE',
            'length' => ['value' => 30, 'unit' => 'CENTIMETER'],
            'width' => ['value' => 20, 'unit' => 'CENTIMETER'],
            'height' => ['value' => 10, 'unit' => 'CENTIMETER'],
            'weight' => ['value' => 1.5, 'unit' => 'KILOGRAMS'],
        ]],
        'labelFormat' => 'PDF',
    ];
}

it('creates a shipment and waits for the command to finish', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/shipment-management/shipments/create-commands' => Http::response(['commandId' => 'cmd-1', 'input' => shipmentInput()], 200, ['Retry-After' => '1']),
        TestCase::API . '/shipment-management/shipments/create-commands/cmd-1' => Http::sequence()
            ->push(['commandId' => 'cmd-1', 'status' => 'IN_PROGRESS', 'errors' => [], 'shipmentId' => null])
            ->push(['commandId' => 'cmd-1', 'status' => 'SUCCESS', 'errors' => [], 'shipmentId' => 'ship-1']),
        TestCase::API . '/shipment-management/shipments/ship-1' => Http::response([
            'id' => 'ship-1', 'carrier' => 'INPOST', 'labelFormat' => 'PDF', 'referenceNumber' => 'ORDER-1',
            'packages' => [['waybill' => '620000000000000', 'type' => 'PACKAGE']],
            'cashOnDelivery' => ['amount' => '123.45', 'currency' => 'PLN', 'ownerName' => 'Shop', 'iban' => 'PL61109010140000071219812874'],
            'createdDate' => '2026-11-04T10:00:00Z', 'canceledDate' => null, 'pickupAvailable' => true, 'additionalServices' => [],
        ]),
    ]);

    $status = Allegro::shipmentManagement()->createShipment(shipmentInput(), 'cmd-1');
    $shipment = Allegro::shipmentManagement()->waitForShipment($status);

    expect($status->status)->toBe(ShipmentCommandStatus::IN_PROGRESS)
        ->and($status->retryAfter)->toBe(1)
        ->and($shipment->waybills)->toBe(['620000000000000'])
        ->and($shipment->carrier)->toBe('INPOST')
        ->and($shipment->cashOnDelivery?->amount)->toBe('123.45')
        ->and($shipment->pickupAvailable)->toBeTrue()
        ->and($shipment->isCanceled())->toBeFalse();
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/create-commands')
        && hasAllegroHeaders($request)
        && jsonBody($request) === ['commandId' => 'cmd-1', 'input' => shipmentInput()]);
});

it('generates a command id when none is given', function (): void {
    connected();
    Http::fake([TestCase::API . '/shipment-management/shipments/create-commands' => Http::response([])]);

    $status = Allegro::shipmentManagement()->createShipment(shipmentInput());

    expect(Str::isUuid($status->commandId))->toBeTrue();
    Http::assertSent(fn(Request $request): bool => (jsonBody($request)['commandId'] ?? null) === $status->commandId);
});

it('throws the command errors when shipment creation fails', function (): void {
    connected();
    Http::fake([TestCase::API . '/shipment-management/shipments/create-commands/cmd-2' => Http::response([
        'commandId' => 'cmd-2', 'status' => 'ERROR', 'shipmentId' => null,
        'errors' => [['code' => 'VALIDATION_ERROR', 'message' => 'Invalid postal code', 'path' => 'receiver.postalCode', 'userMessage' => 'Nieprawidłowy kod pocztowy']],
    ])]);

    try {
        Allegro::shipmentManagement()->waitForShipment('cmd-2');
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->errors()[0]['path'])->toBe('receiver.postalCode')
            ->and($exception->getMessage())->toContain('Nieprawidłowy kod pocztowy');
    }
});

it('downloads labels as binary content with the octet-stream Accept header', function (): void {
    connected();
    Http::fake([TestCase::API . '/shipment-management/label' => Http::response('%PDF-label', 200, ['Content-Type' => 'application/octet-stream'])]);

    $label = Allegro::shipmentManagement()->label(['ship-1', 'ship-2'], 'A4', true, ['placement' => 'LAST', 'fields' => ['WAYBILL']]);

    expect($label)->toBe('%PDF-label');
    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Accept', 'application/octet-stream')
        && $request->hasHeader('Content-Type', 'application/vnd.allegro.public.v1+json')
        && jsonBody($request) === ['shipmentIds' => ['ship-1', 'ship-2'], 'pageSize' => 'A4', 'cutLine' => true, 'summaryReport' => ['placement' => 'LAST', 'fields' => ['WAYBILL']]]);
});

it('returns null when no label or protocol is available', function (): void {
    connected();
    Http::fake([TestCase::API . '/shipment-management/*' => Http::response(null, 204)]);

    expect(Allegro::shipmentManagement()->label('ship-1'))->toBeNull()
        ->and(Allegro::shipmentManagement()->protocol(['ship-1']))->toBeNull();
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/label') && jsonBody($request) === ['shipmentIds' => ['ship-1'], 'pageSize' => 'A6']);
});

it('cancels a shipment', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/shipment-management/shipments/cancel-commands' => Http::response(['commandId' => 'cancel-1', 'input' => ['shipmentId' => 'ship-1']]),
        TestCase::API . '/shipment-management/shipments/cancel-commands/cancel-1' => Http::response(['commandId' => 'cancel-1', 'status' => 'SUCCESS', 'errors' => [], 'shipmentId' => 'ship-1']),
    ]);

    $status = Allegro::shipmentManagement()->cancelShipment('ship-1', 'cancel-1');

    expect(Allegro::shipmentManagement()->waitForCancellation($status)->isSuccessful())->toBeTrue();
    Http::assertSent(fn(Request $request): bool => jsonBody($request) === ['commandId' => 'cancel-1', 'input' => ['shipmentId' => 'ship-1']]);
});

it('orders a courier pickup', function (): void {
    connected();
    $address = ['name' => 'Shop', 'street' => 'Prosta 1', 'postalCode' => '00-001', 'city' => 'Warszawa', 'countryCode' => 'PL', 'email' => 'shop@example.com', 'phone' => '500600700'];
    Http::fake([
        TestCase::API . '/shipment-management/pickup-proposals' => Http::response([['shipmentId' => 'ship-1', 'proposals' => [['date' => '2026-11-05', 'proposalItems' => []]]]]),
        TestCase::API . '/shipment-management/pickups/create-commands' => Http::response(['commandId' => 'pick-1']),
        TestCase::API . '/shipment-management/pickups/create-commands/pick-1' => Http::response(['commandId' => 'pick-1', 'status' => 'SUCCESS', 'pickupId' => 'p-1', 'carrierPickupId' => 'DPD-77', 'errors' => []]),
    ]);

    Allegro::shipmentManagement()->pickupProposals(['ship-1'], $address, new DateTimeImmutable('2026-11-05'));
    Allegro::shipmentManagement()->createPickup(['ship-1'], $address, ['date' => '2026-11-05', 'minTime' => '10:00', 'maxTime' => '14:00'], 'pick-1');
    $status = Allegro::shipmentManagement()->pickupStatus('pick-1');

    expect($status->pickupId)->toBe('p-1')
        ->and($status->carrierPickupId)->toBe('DPD-77');
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/pickup-proposals')
        && jsonBody($request) === ['shipmentIds' => ['ship-1'], 'address' => $address, 'readyDate' => '2026-11-05']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/pickups/create-commands')
        && jsonBody($request) === ['commandId' => 'pick-1', 'input' => ['shipmentIds' => ['ship-1'], 'pickupTime' => ['date' => '2026-11-05', 'minTime' => '10:00', 'maxTime' => '14:00'], 'address' => $address]]);
});

it('reads delivery proposals for an order', function (): void {
    connected();
    Http::fake([TestCase::API . '/shipment-management/delivery-proposals/order-1' => Http::response(['deliveryMethodId' => 'dm-1', 'packages' => []])]);

    expect(Allegro::shipmentManagement()->deliveryProposals('order-1')['deliveryMethodId'])->toBe('dm-1');
});
