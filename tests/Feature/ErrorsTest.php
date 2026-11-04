<?php

declare(strict_types=1);

use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

it('parses the standard error envelope', function (): void {
    connected();
    Http::fake([TestCase::API . '/sale/product-offers' => Http::response(['errors' => [[
        'code' => 'ConstraintViolationException.NotNull',
        'message' => 'must not be null',
        'details' => null,
        'path' => 'sellingMode.price',
        'userMessage' => 'Podaj cenę',
        'metadata' => [],
    ]]], 422, ['Trace-Id' => 'trace-422'])]);

    try {
        Allegro::offers()->create(['name' => 'Test']);
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->httpStatus())->toBe(422)
            ->and($exception->isValidationError())->toBeTrue()
            ->and($exception->userMessage())->toBe('Podaj cenę')
            ->and($exception->hasErrorCode('ConstraintViolationException.NotNull'))->toBeTrue()
            ->and($exception->errors()[0]['path'])->toBe('sellingMode.price')
            ->and($exception->traceId())->toBe('trace-422')
            ->and($exception->getMessage())->toBe('Allegro request failed (HTTP 422): sellingMode.price: Podaj cenę [Trace-Id trace-422]');
    }
});

it('exposes rate limiting and Retry-After', function (): void {
    connected();
    Http::fake([TestCase::API . '/*' => Http::response(['errors' => [['code' => 'TooManyRequests', 'message' => 'Too many requests']]], 429, ['Retry-After' => '60'])]);

    try {
        Allegro::orders()->carriers();
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->isRateLimited())->toBeTrue()
            ->and($exception->retryAfter())->toBe(60);
    }

    Http::assertSentCount(1);
});

it('flags revision conflicts and missing resources', function (): void {
    connected();
    Http::fake([
        TestCase::API . '/order/checkout-forms/stale/fulfillment*' => Http::response(['errors' => [['code' => 'ConflictException', 'message' => 'Revision mismatch']]], 409),
        TestCase::API . '/order/checkout-forms/missing' => Http::response(['errors' => [['code' => 'NotFoundException', 'message' => 'Not found']]], 404),
    ]);

    expect(fn() => Allegro::orders()->setFulfillmentStatus('stale', 'SENT', 'rev-1'))
        ->toThrow(fn(ApiException $exception) => expect($exception->isConflict())->toBeTrue());
    expect(fn() => Allegro::orders()->checkoutForm('missing'))
        ->toThrow(fn(ApiException $exception) => expect($exception->isNotFound())->toBeTrue());
});

it('wraps connection failures', function (): void {
    connected();
    Http::fake(fn() => throw new ConnectionException('cURL error 28: timed out'));

    Allegro::me();
})->throws(ApiException::class, 'Could not connect to Allegro: cURL error 28');

it('logs failed requests with the Trace-Id when a log channel is configured', function (): void {
    config()->set('allegro.log_channel', 'allegro');
    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('warning')->once()->with('Allegro request failed', Mockery::on(fn(array $context): bool => $context['trace_id'] === 'trace-500'
        && $context['status'] === 500
        && $context['url'] === '/me'
        && $context['account'] === 'default'));
    Log::shouldReceive('channel')->with('allegro')->andReturn($logger);
    connected();
    Http::fake([TestCase::API . '/me' => Http::response('', 500, ['Trace-Id' => 'trace-500'])]);

    expect(fn() => Allegro::me())->toThrow(ApiException::class, 'HTTP 500');
});
