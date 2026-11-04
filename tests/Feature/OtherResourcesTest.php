<?php

declare(strict_types=1);

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads billing entries with filters', function (): void {
    connected();
    Http::fake([TestCase::API . '/billing/billing-entries*' => Http::response(['billingEntries' => [[
        'id' => 'b5a7a3a0-6e0e-4c3c-9c6a-2f4b1f0b7c11',
        'occurredAt' => '2026-11-03T10:00:00Z',
        'type' => ['id' => 'SUC', 'name' => 'Prowizja od sprzedaży'],
        'offer' => ['id' => '77', 'name' => 'Skarpety'],
        'value' => ['amount' => '-4.99', 'currency' => 'PLN'],
        'tax' => ['percentage' => '23'],
        'balance' => ['amount' => '-120.00', 'currency' => 'PLN'],
        'order' => ['id' => 'order-1'],
        'marketplaceId' => 'allegro-pl',
    ]]])]);

    $entries = Allegro::billing()->entries(['type.id' => ['SUC', 'LIS'], 'order.id' => 'order-1'], 500, 20000);

    expect($entries[0]->typeId)->toBe('SUC')
        ->and($entries[0]->value?->amount)->toBe('-4.99')
        ->and($entries[0]->balance?->amount)->toBe('-120.00')
        ->and($entries[0]->taxPercentage)->toBe('23')
        ->and($entries[0]->orderId)->toBe('order-1');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/billing/billing-entries?type.id=SUC&type.id=LIS&order.id=order-1&limit=100&offset=10000');
});

it('reads conversations and messages', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/messaging/threads/t-1/messages*' => Http::response(['messages' => [[
            'id' => 'm-1', 'status' => 'DELIVERED', 'type' => 'MESSAGE_CENTER', 'createdAt' => '2026-11-04T10:00:00Z',
            'thread' => ['id' => 't-1'], 'author' => ['login' => 'buyer', 'isInterlocutor' => true],
            'text' => 'Kiedy wysyłka?', 'subject' => null,
            'relatesTo' => ['offer' => null, 'order' => ['id' => 'order-1']],
            'hasAdditionalAttachments' => false,
            'attachments' => [['fileName' => 'photo.jpg', 'mimeType' => 'image/jpeg', 'url' => 'https://api.allegro.pl/messaging/message-attachments/a-1', 'status' => 'SAFE']],
        ]], 'offset' => 0, 'limit' => 20]),
        TestCase::API . '/messaging/threads*' => Http::response(['threads' => [['id' => 't-1', 'read' => false, 'lastMessageDateTime' => '2026-11-04T10:00:00Z', 'interlocutor' => ['login' => 'buyer', 'avatarUrl' => null]]], 'offset' => 0, 'limit' => 20]),
    ]);

    $threads = Allegro::messaging()->threads(['read' => false], 50);
    $messages = Allegro::messaging()->messages('t-1');

    expect($threads[0]->interlocutorLogin)->toBe('buyer')
        ->and($threads[0]->read)->toBeFalse()
        ->and($messages[0]->text)->toBe('Kiedy wysyłka?')
        ->and($messages[0]->authorIsInterlocutor)->toBeTrue()
        ->and($messages[0]->orderId)->toBe('order-1')
        ->and($messages[0]->attachments[0]['status'])->toBe('SAFE');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/messaging/threads?read=false&limit=20&offset=0');
});

it('sends messages and marks conversations as read', function (): void {
    connected();
    $message = ['id' => 'm-2', 'status' => 'VERIFYING', 'type' => 'MESSAGE_CENTER', 'createdAt' => '2026-11-04T10:00:00Z', 'thread' => ['id' => 't-1'], 'author' => ['login' => 'shop', 'isInterlocutor' => false], 'text' => 'Jutro', 'relatesTo' => [], 'hasAdditionalAttachments' => false, 'attachments' => []];
    Http::fake([
        TestCase::API . '/messaging/threads/t-1/messages' => Http::response($message, 201),
        TestCase::API . '/messaging/messages' => Http::response($message, 201),
        TestCase::API . '/messaging/threads/t-1/read' => Http::response(['id' => 't-1', 'read' => true]),
    ]);

    expect(Allegro::messaging()->reply('t-1', 'Jutro', ['a-1'])->threadId)->toBe('t-1');
    Allegro::messaging()->send('buyer', 'Dziękujemy za zakup', 'order-1');
    Allegro::messaging()->markRead('t-1');

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/threads/t-1/messages')
        && jsonBody($request) === ['text' => 'Jutro', 'attachments' => [['id' => 'a-1']]]);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/messaging/messages'
        && jsonBody($request) === ['recipient' => ['login' => 'buyer'], 'text' => 'Dziękujemy za zakup', 'order' => ['id' => 'order-1']]);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT' && jsonBody($request) === ['read' => true]);
});

it('declares and uploads a message attachment', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/messaging/message-attachments' => Http::response(['id' => 'a-9'], 201),
        TestCase::API . '/messaging/message-attachments/a-9' => Http::response(['id' => 'a-9']),
    ]);

    expect(Allegro::messaging()->uploadAttachment('invoice.pdf', '%PDF-data'))->toBe('a-9');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST' && jsonBody($request) === ['filename' => 'invoice.pdf', 'size' => 9]);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT'
        && $request->hasHeader('Content-Type', 'application/pdf')
        && $request->body() === '%PDF-data');
});

it('uses the beta media type for customer returns', function (): void {
    connected();
    $return = [
        'id' => 'r-1', 'isFulfillment' => false, 'createdAt' => '2026-11-04T10:00:00Z', 'referenceNumber' => 'XWZ1', 'orderId' => 'order-1',
        'buyer' => ['email' => 'b@allegromail.pl', 'login' => 'buyer'],
        'items' => [['offerId' => '77', 'quantity' => 1, 'name' => 'Skarpety', 'price' => ['amount' => '49.99', 'currency' => 'PLN'], 'reason' => ['type' => 'DAMAGED', 'userComment' => 'Dziura']]],
        'parcels' => [['createdAt' => '2026-11-04T10:00:00Z', 'waybill' => 'W1', 'carrierId' => 'INPOST', 'transportingWaybill' => null, 'transportingCarrierId' => null]],
        'rejection' => null, 'marketplaceId' => 'allegro-pl', 'status' => 'DELIVERED',
    ];
    Http::fake([
        TestCase::API . '/order/customer-returns/r-1/rejection' => Http::response(['rejection' => ['code' => 'REFUND_REJECTED']] + $return),
        TestCase::API . '/order/customer-returns*' => Http::response(['count' => 1, 'customerReturns' => [$return]]),
    ]);

    $returns = Allegro::customerReturns()->list(['orderId' => 'order-1']);
    $rejected = Allegro::customerReturns()->rejectRefund('r-1', 'REFUND_REJECTED', 'Produkt używany');

    expect($returns[0]->status)->toBe('DELIVERED')
        ->and($returns[0]->items[0]->reasonType)->toBe('DAMAGED')
        ->and($returns[0]->items[0]->price?->amount)->toBe('49.99')
        ->and($returns[0]->parcels[0]['waybill'])->toBe('W1')
        ->and($rejected->rejectionCode)->toBe('REFUND_REJECTED');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/order/customer-returns?orderId=order-1&limit=100&offset=0'
        && hasAllegroHeaders($request, accept: AllegroClient::BETA_MEDIA_TYPE));
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/rejection')
        && $request->hasHeader('Content-Type', AllegroClient::BETA_MEDIA_TYPE)
        && jsonBody($request) === ['rejection' => ['code' => 'REFUND_REJECTED', 'reason' => 'Produkt używany']]);
});

it('reads categories, parameters and searches the product catalogue', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/sale/categories/257931/parameters' => Http::response(['parameters' => [['id' => '11323', 'name' => 'Stan', 'type' => 'dictionary', 'required' => true]]]),
        TestCase::API . '/sale/categories/257931' => Http::response(['id' => '257931', 'name' => 'Skarpety', 'leaf' => true, 'parent' => ['id' => '1']]),
        TestCase::API . '/sale/matching-categories*' => Http::response(['matchingCategories' => [['id' => '257931', 'name' => 'Skarpety', 'parent' => null]]]),
        TestCase::API . '/sale/products*' => Http::response(['products' => [['id' => 'p-1', 'name' => 'Skarpety X']], 'nextPage' => ['id' => 'page-2']]),
    ]);

    $category = Allegro::catalog()->category('257931');

    expect($category->leaf)->toBeTrue()
        ->and($category->parentId)->toBe('1')
        ->and(Allegro::catalog()->parameters('257931')[0]['name'])->toBe('Stan')
        ->and(Allegro::catalog()->matchingCategories('skarpety bawełniane')[0]['id'])->toBe('257931')
        ->and(Allegro::catalog()->searchProducts('5901234123457', 'GTIN')['nextPage']['id'])->toBe('page-2');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/matching-categories?name=skarpety%20bawe%C5%82niane');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/products?phrase=5901234123457&mode=GTIN');
});

it('reaches any other endpoint through the generic request method', function (): void {
    connected();
    Http::fake([TestCase::API . '/sale/offer-modification-commands/cmd-9*' => Http::response(['id' => 'cmd-9'], 201)]);

    $data = Allegro::request('PUT', 'sale/offer-modification-commands/cmd-9', ['dryRun' => true], ['modification' => ['delivery' => ['handlingTime' => 'PT24H']]]);

    expect($data['id'])->toBe('cmd-9');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT'
        && $request->url() === TestCase::API . '/sale/offer-modification-commands/cmd-9?dryRun=true'
        && $request->hasHeader('Content-Type', AllegroClient::MEDIA_TYPE)
        && ($request->data()['modification']['delivery']['handlingTime'] ?? null) === 'PT24H');
});
