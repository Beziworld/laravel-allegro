<?php

declare(strict_types=1);

use Beziworld\Allegro\Data\Money;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @return array<string, mixed>
 */
function productOfferPayload(string $id = '7712345678', string $status = 'ACTIVE'): array
{
    return [
        'id' => $id,
        'name' => 'Skarpety bawełniane',
        'category' => ['id' => '257931'],
        'productSet' => [['product' => ['id' => 'c9e39cae-9cb6-11e9-a2a3-2a2ae2dbcce4', 'publication' => ['status' => 'LISTED']], 'quantity' => ['value' => 1]]],
        'sellingMode' => ['format' => 'BUY_NOW', 'price' => ['amount' => '49.99', 'currency' => 'PLN']],
        'stock' => ['available' => 12, 'unit' => 'UNIT'],
        'publication' => ['status' => $status],
        'external' => ['id' => 'SKU-1'],
        'validation' => ['errors' => [], 'warnings' => [], 'validatedAt' => '2026-11-04T10:00:00Z'],
        'createdAt' => '2026-11-04T10:00:00Z',
        'updatedAt' => '2026-11-04T10:05:00Z',
    ];
}

it('lists offers with filters and maps the listing', function (): void {
    connected();
    Http::fake([TestCase::API . '/sale/offers*' => Http::response([
        'offers' => [[
            'id' => '7712345678', 'name' => 'Skarpety', 'category' => ['id' => '257931'],
            'primaryImage' => ['url' => 'https://a.allegroimg.com/original/1.jpg'],
            'sellingMode' => ['format' => 'BUY_NOW', 'price' => ['amount' => '49.99', 'currency' => 'PLN']],
            'stock' => ['available' => 12, 'sold' => 3],
            'publication' => ['status' => 'ACTIVE', 'startedAt' => '2026-10-01T10:00:00Z', 'endedAt' => null],
            'external' => ['id' => 'SKU-1'],
            'isFulfillment' => false,
        ]],
        'count' => 1,
        'totalCount' => 1,
    ])]);

    $list = Allegro::offers()->list(['publication.status' => ['ACTIVE', 'INACTIVE'], 'external.id' => 'SKU-1'], 5000);
    $offer = $list->offers[0];

    expect($offer->isActive())->toBeTrue()
        ->and($offer->price?->amount)->toBe('49.99')
        ->and($offer->stockAvailable)->toBe(12)
        ->and($offer->stockSold)->toBe(3)
        ->and($offer->externalId)->toBe('SKU-1')
        ->and($offer->primaryImageUrl)->toEndWith('1.jpg')
        ->and($offer->startedAt?->toDateString())->toBe('2026-10-01');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/offers?publication.status=ACTIVE&publication.status=INACTIVE&external.id=SKU-1&limit=1000&offset=0'
        && hasAllegroHeaders($request));
});

it('iterates over every offer page', function (): void {
    connected();
    Http::fake([TestCase::API . '/sale/offers*' => Http::sequence()
        ->push(['offers' => [['id' => '1', 'name' => 'A'], ['id' => '2', 'name' => 'B']], 'count' => 2, 'totalCount' => 3])
        ->push(['offers' => [['id' => '3', 'name' => 'C']], 'count' => 1, 'totalCount' => 3])]);

    $ids = array_map(fn($offer): string => $offer->id, iterator_to_array(Allegro::offers()->all([], 2), false));

    expect($ids)->toBe(['1', '2', '3']);
});

it('reads a product offer and selected parts', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/sale/product-offers/7712345678' => Http::response(productOfferPayload()),
        TestCase::API . '/sale/product-offers/7712345678/parts*' => Http::response(['stock' => ['available' => 12]]),
    ]);

    $offer = Allegro::offers()->get('7712345678');

    expect($offer->name)->toBe('Skarpety bawełniane')
        ->and($offer->productIds)->toBe(['c9e39cae-9cb6-11e9-a2a3-2a2ae2dbcce4'])
        ->and($offer->stockUnit)->toBe('UNIT')
        ->and($offer->isActive())->toBeTrue()
        ->and($offer->validationErrors)->toBe([])
        ->and(Allegro::offers()->parts('7712345678', ['stock', 'price'])['stock']['available'])->toBe(12);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/product-offers/7712345678/parts?include=stock&include=price');
});

it('creates an offer synchronously', function (): void {
    connected();
    Http::fake([TestCase::API . '/sale/product-offers' => Http::response(productOfferPayload(), 201)]);

    $body = [
        'productSet' => [['product' => ['id' => 'c9e39cae-9cb6-11e9-a2a3-2a2ae2dbcce4']]],
        'sellingMode' => ['price' => Money::of(49.99)->toArray()],
        'stock' => ['available' => 12],
        'external' => ['id' => 'SKU-1'],
    ];
    $result = Allegro::offers()->create($body);

    expect($result->isPending())->toBeFalse()
        ->and($result->offer->id)->toBe('7712345678')
        ->and(Allegro::offers()->waitForOperation($result)->id)->toBe('7712345678');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/vnd.allegro.public.v1+json')
        && jsonBody($request) === ['productSet' => [['product' => ['id' => 'c9e39cae-9cb6-11e9-a2a3-2a2ae2dbcce4']]], 'sellingMode' => ['price' => ['amount' => '49.99', 'currency' => 'PLN']], 'stock' => ['available' => 12], 'external' => ['id' => 'SKU-1']]);
    Http::assertSentCount(1);
});

it('waits for an asynchronous offer edit until the operation completes', function (): void {
    connected();
    $operation = TestCase::API . '/sale/product-offers/7712345678/operations/op-1';
    Http::fake([
        TestCase::API . '/sale/product-offers/7712345678/operations/op-1' => Http::sequence()
            ->push(['offer' => ['id' => '7712345678'], 'operation' => ['id' => 'op-1', 'status' => 'IN_PROGRESS']], 202, ['Retry-After' => '0'])
            ->push(null, 303, ['Location' => TestCase::API . '/sale/product-offers/7712345678']),
        TestCase::API . '/sale/product-offers/7712345678' => Http::sequence()
            ->push(productOfferPayload('7712345678', 'ACTIVATING'), 202, ['Location' => $operation, 'Retry-After' => '0'])
            ->push(productOfferPayload()),
    ]);

    $result = Allegro::offers()->edit('7712345678', ['sellingMode' => ['price' => ['amount' => '39.99', 'currency' => 'PLN']]]);

    expect($result->isPending())->toBeTrue()
        ->and($result->operationUrl)->toBe($operation)
        ->and($result->retryAfter)->toBe(0);

    $offer = Allegro::offers()->waitForOperation($result);

    expect($offer->status)->toBe('ACTIVE');
    $recorded = Http::recorded()->map(fn(array $pair): string => $pair[0]->method() . ' ' . $pair[0]->url() . ' ' . $pair[1]->status())->all();
    expect($recorded)->toBe([
        'PATCH ' . TestCase::API . '/sale/product-offers/7712345678 202',
        'GET ' . $operation . ' 202',
        'GET ' . $operation . ' 303',
        'GET ' . TestCase::API . '/sale/product-offers/7712345678 200',
    ]);
});

it('stops waiting for an offer operation after the configured attempts', function (): void {
    config()->set('allegro.polling.attempts', 2);
    connected();
    Http::fake([TestCase::API . '/sale/product-offers/1/operations/op-1' => Http::response(['operation' => ['status' => 'IN_PROGRESS']], 202)]);

    Allegro::offers()->waitForOperation('https://elsewhere.example/sale/product-offers/1/operations/op-1');
})->throws(ApiException::class, 'still running');

it('changes prices with a command and reads its report and tasks', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/sale/offer-price-change-commands/*/tasks*' => Http::response(['tasks' => [
            ['offer' => ['id' => '1'], 'status' => 'SUCCESS', 'message' => '', 'field' => 'price', 'errors' => []],
            ['offer' => ['id' => '2'], 'status' => 'FAIL', 'message' => 'Offer ended', 'field' => 'price', 'errors' => [['code' => 'OfferEnded', 'message' => 'Offer ended', 'userMessage' => 'Oferta zakończona']]],
        ]]),
        TestCase::API . '/sale/offer-price-change-commands/*' => Http::response(['createdAt' => '2026-11-04T10:00:00Z', 'completedAt' => null, 'taskCount' => ['total' => 2, 'success' => 0, 'failed' => 0]], 201),
    ]);

    $report = Allegro::offers()->changePrice(['1', '2'], Money::of('39.90'));
    $tasks = Allegro::offers()->priceChangeTasks($report->id, 5000);

    expect(Str::isUuid($report->id))->toBeTrue()
        ->and($report->total)->toBe(2)
        ->and($report->isCompleted())->toBeFalse()
        ->and($tasks[0]->isSuccessful())->toBeTrue()
        ->and($tasks[1]->isFailed())->toBeTrue()
        ->and($tasks[1]->errors[0]['userMessage'])->toBe('Oferta zakończona');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT'
        && Str::isUuid(Str::afterLast($request->url(), '/'))
        && jsonBody($request) === [
            'modification' => ['type' => 'FIXED_PRICE', 'price' => ['amount' => '39.90', 'currency' => 'PLN']],
            'offerCriteria' => [['type' => 'CONTAINS_OFFERS', 'offers' => [['id' => '1'], ['id' => '2']]]],
        ]);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/tasks?limit=1000&offset=0'));
});

it('changes quantities with a caller supplied command id', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/sale/offer-quantity-change-commands/cmd-1' => Http::sequence()
            ->push(['id' => 'cmd-1', 'taskCount' => ['total' => 1, 'success' => 0, 'failed' => 0]], 201)
            ->push(['id' => 'cmd-1', 'completedAt' => '2026-11-04T10:01:00Z', 'taskCount' => ['total' => 1, 'success' => 1, 'failed' => 0]]),
    ]);

    $report = Allegro::offers()->changeQuantity('77', 5, 'GAIN', 'cmd-1');
    $final = Allegro::offers()->quantityChangeReport('cmd-1');

    expect($report->id)->toBe('cmd-1')
        ->and($final->isCompleted())->toBeTrue()
        ->and($final->success)->toBe(1);
    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT'
        && jsonBody($request) === ['modification' => ['changeType' => 'GAIN', 'value' => 5], 'offerCriteria' => [['type' => 'CONTAINS_OFFERS', 'offers' => [['id' => '77']]]]]);
});

it('supports other price modification types', function (): void {
    connected();
    Http::fake([TestCase::API . '/sale/offer-price-change-commands/cmd-2' => Http::response(['id' => 'cmd-2'], 201)]);

    Allegro::offers()->priceChangeCommand(['type' => 'INCREASE_PERCENTAGE', 'percentage' => '5.00'], ['1'], 'cmd-2');

    Http::assertSent(fn(Request $request): bool => (jsonBody($request)['modification'] ?? null) === ['type' => 'INCREASE_PERCENTAGE', 'percentage' => '5.00']);
});

it('reads offer events and deletes drafts', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/sale/offer-events*' => Http::response(['offerEvents' => [['id' => 'O1', 'occurredAt' => '2026-11-04T10:00:00Z', 'type' => 'OFFER_ENDED', 'offer' => ['id' => '77']]]]),
        TestCase::API . '/sale/offers/88' => Http::response(null, 204),
    ]);

    expect(Allegro::offers()->events('O0', ['OFFER_ENDED'])[0]->offerId)->toBe('77');
    Allegro::offers()->delete('88');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/offer-events?from=O0&type=OFFER_ENDED&limit=100');
    Http::assertSent(fn(Request $request): bool => $request->method() === 'DELETE' && $request->url() === TestCase::API . '/sale/offers/88');
});
