<?php

declare(strict_types=1);

use Beziworld\Allegro\Events\OfferEventReceived;
use Beziworld\Allegro\Events\OrderEventReceived;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Polling\OfferEventPoller;
use Beziworld\Allegro\Polling\OrderEventPoller;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function orderEventPayload(string $id, string $type = 'READY_FOR_PROCESSING', string $checkoutFormId = 'order-1'): array
{
    return [
        'id' => $id,
        'occurredAt' => '2026-11-04T10:00:00.000Z',
        'type' => $type,
        'order' => [
            'seller' => ['id' => '40507'],
            'buyer' => ['id' => '1', 'email' => 'b@allegromail.pl', 'login' => 'buyer', 'guest' => false],
            'lineItems' => [],
            'checkoutForm' => ['id' => $checkoutFormId, 'revision' => 'rev-' . $id],
            'marketplace' => ['id' => 'allegro-pl'],
        ],
    ];
}

it('starts at the newest event on the first run and dispatches only later events', function (): void {
    Event::fake([OrderEventReceived::class]);
    connected();
    Http::fake([
        TestCase::API . '/order/event-stats' => Http::response(['latestEvent' => ['id' => 'E10', 'occurredAt' => '2026-11-04T09:00:00Z']]),
        TestCase::API . '/order/events*' => Http::response(['events' => [orderEventPayload('E11'), orderEventPayload('E12', 'BUYER_CANCELLED', 'order-2')]]),
    ]);

    $poller = app(OrderEventPoller::class);

    expect($poller->poll())->toBe(2)
        ->and($poller->cursor(Allegro::account()))->toBe('E12');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/order/events?from=E10&limit=100');
    Event::assertDispatchedTimes(OrderEventReceived::class, 2);
    Event::assertDispatched(OrderEventReceived::class, fn(OrderEventReceived $event): bool => $event->account === 'default'
        && $event->event->id === 'E12'
        && $event->event->isCancellation()
        && $event->event->checkoutFormId === 'order-2');
});

it('continues from the stored cursor without dispatching events twice', function (): void {
    Event::fake([OrderEventReceived::class]);
    connected();
    Http::fake([TestCase::API . '/order/events*' => Http::sequence()
        ->push(['events' => [orderEventPayload('E21')]])
        ->push(['events' => []])]);

    $poller = app(OrderEventPoller::class);
    $poller->setCursor(Allegro::account(), 'E20');

    expect($poller->poll('default'))->toBe(1)
        ->and($poller->poll('default'))->toBe(0);

    Http::assertSent(fn(Request $request): bool => str_contains($request->url(), 'from=E20'));
    Http::assertSent(fn(Request $request): bool => str_contains($request->url(), 'from=E21'));
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), 'event-stats'));
    Event::assertDispatchedTimes(OrderEventReceived::class, 1);
});

it('reads further pages while they are full, up to the page limit', function (): void {
    config()->set('allegro.events.limit', 2);
    config()->set('allegro.events.max_pages', 2);
    Event::fake([OrderEventReceived::class]);
    connected();
    Http::fake([TestCase::API . '/order/events*' => Http::sequence()
        ->push(['events' => [orderEventPayload('E1'), orderEventPayload('E2')]])
        ->push(['events' => [orderEventPayload('E3'), orderEventPayload('E4')]])
        ->push(['events' => [orderEventPayload('E5')]])]);

    $poller = app(OrderEventPoller::class);
    $poller->setCursor(Allegro::account(), 'E0');

    expect($poller->poll())->toBe(4)
        ->and($poller->cursor(Allegro::account()))->toBe('E4')
        ->and($poller->poll())->toBe(1);
});

it('replays the whole journal when configured to start at the beginning', function (): void {
    config()->set('allegro.events.start', 'beginning');
    config()->set('allegro.events.order_types', ['READY_FOR_PROCESSING']);
    Event::fake([OrderEventReceived::class]);
    connected();
    Http::fake([TestCase::API . '/order/events*' => Http::response(['events' => [orderEventPayload('E1')]])]);

    app(OrderEventPoller::class)->poll();

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/order/events?type=READY_FOR_PROCESSING&limit=100');
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), 'event-stats'));
});

it('skips a run while another run of the same account holds the lock', function (): void {
    Event::fake([OrderEventReceived::class]);
    $client = connected();
    Http::fake();
    $lock = cache()->lock($client->scopedKey('lock:poll:orders'), 300);
    $lock->get();

    expect(app(OrderEventPoller::class)->poll($client))->toBe(0);
    Http::assertNothingSent();

    $lock->release();
});

it('keeps separate cursors per account', function (): void {
    $poller = app(OrderEventPoller::class);
    $poller->setCursor(Allegro::account(), 'A1');
    $poller->setCursor(Allegro::forUser('seller-9'), 'B1');

    expect($poller->cursor(Allegro::account()))->toBe('A1')
        ->and($poller->cursor(Allegro::forUser('seller-9')))->toBe('B1');

    $poller->setCursor(Allegro::account(), null);
    expect($poller->cursor(Allegro::account()))->toBeNull();
});

it('polls configured accounts and runtime sellers from the artisan command', function (): void {
    Event::fake([OrderEventReceived::class]);
    connected();
    connected('second', 'token-second');
    Allegro::forUser('seller-9')->storeToken(tokenPayload('token-seller'));
    $poller = app(OrderEventPoller::class);
    foreach ([Allegro::account(), Allegro::account('second'), Allegro::forUser('seller-9')] as $client) {
        $poller->setCursor($client, 'E0');
    }
    Http::fake([TestCase::API . '/order/events*' => Http::response(['events' => [orderEventPayload('E1')]])]);

    $this->artisan('allegro:poll-orders', ['--all' => true, '--user' => ['seller-9']])
        ->expectsOutput('default: 1 new order event(s).')
        ->expectsOutput('shop-two: 1 new order event(s).')
        ->expectsOutput('seller-9: 1 new order event(s).')
        ->assertSuccessful();

    Event::assertDispatched(OrderEventReceived::class, fn(OrderEventReceived $event): bool => $event->account === 'seller-9');
    Http::assertSentCount(3);
});

it('reports accounts that cannot be polled and continues with the others', function (): void {
    Event::fake([OrderEventReceived::class]);
    connected('second', 'token-second');
    app(OrderEventPoller::class)->setCursor(Allegro::account('second'), 'E0');
    Http::fake([TestCase::API . '/order/events*' => Http::response(['events' => []])]);

    $this->artisan('allegro:poll-orders', ['account' => ['default', 'second']])
        ->expectsOutputToContain('No Allegro token is stored for "default"')
        ->expectsOutput('shop-two: 0 new order event(s).')
        ->assertFailed();
});

it('dispatches offer events from the offer journal', function (): void {
    Event::fake([OfferEventReceived::class]);
    connected();
    Http::fake([TestCase::API . '/sale/offer-events*' => Http::response(['offerEvents' => [[
        'id' => 'O1',
        'occurredAt' => '2026-11-04T10:00:00.000Z',
        'type' => 'OFFER_STOCK_CHANGED',
        'offer' => ['id' => '9206571541', 'external' => ['id' => 'SKU-1']],
    ]]])]);

    $this->artisan('allegro:poll-offers')->expectsOutput('default: 1 new offer event(s).')->assertSuccessful();

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/offer-events?limit=100');
    Event::assertDispatched(OfferEventReceived::class, fn(OfferEventReceived $event): bool => $event->event->offerId === '9206571541'
        && $event->event->externalId === 'SKU-1'
        && $event->event->type === 'OFFER_STOCK_CHANGED');
    expect(app(OfferEventPoller::class)->cursor(Allegro::account()))->toBe('O1');
});
