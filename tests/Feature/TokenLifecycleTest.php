<?php

declare(strict_types=1);

use Beziworld\Allegro\AllegroManager;
use Beziworld\Allegro\Contracts\TokenStore;
use Beziworld\Allegro\Data\AccessToken;
use Beziworld\Allegro\Exceptions\ApiException;
use Beziworld\Allegro\Exceptions\AuthenticationException;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function expiredToken(string $refresh = 'refresh-old'): AccessToken
{
    return new AccessToken('access-old', CarbonImmutable::now()->subMinute(), $refresh);
}

it('sends the bearer token, media type, User-Agent and Accept-Language with every call', function (): void {
    connected();
    Http::fake([TestCase::API . '/me' => Http::response(['id' => '43682', 'login' => 'shop'], 200, ['Trace-Id' => 'trace-me'])]);

    expect(Allegro::me()['login'])->toBe('shop')
        ->and(Allegro::lastTraceId())->toBe('trace-me');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/me'
        && hasAllegroHeaders($request)
        && $request->hasHeader('Accept-Language', 'pl-PL'));
});

it('refreshes an expired access token and stores the rotated refresh token', function (): void {
    Allegro::account()->storeToken(expiredToken());
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(tokenPayload('access-new', 'refresh-new')),
        TestCase::API . '/me' => Http::response(['id' => '1']),
    ]);

    Allegro::me();

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::OAUTH . '/token'
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('client-id:client-secret'))
        && ($request->data()['grant_type'] ?? null) === 'refresh_token'
        && ($request->data()['refresh_token'] ?? null) === 'refresh-old');
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/me' && hasAllegroHeaders($request, 'access-new'));
    expect(Allegro::account()->token()?->refreshToken)->toBe('refresh-new');
});

it('reuses a stored token without contacting the OAuth server', function (): void {
    connected();
    Http::fake([TestCase::API . '/*' => Http::response(['id' => '1'])]);

    Allegro::me();
    Allegro::me();

    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/auth/oauth/'));
    Http::assertSentCount(2);
});

it('seeds the token store from the configured refresh token', function (): void {
    config()->set('allegro.accounts.default.refresh_token', 'seed-refresh');
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(tokenPayload('access-seeded', 'refresh-rotated')),
        TestCase::API . '/me' => Http::response(['id' => '1']),
    ]);

    expect(Allegro::isConnected())->toBeTrue();
    Allegro::me();

    Http::assertSent(fn(Request $request): bool => ($request->data()['refresh_token'] ?? null) === 'seed-refresh');
    expect(Allegro::account()->token()?->refreshToken)->toBe('refresh-rotated');
});

it('throws when the account was never connected', function (): void {
    Http::fake();

    expect(Allegro::isConnected())->toBeFalse();
    Allegro::me();
})->throws(AuthenticationException::class, 'No Allegro token is stored for "default"');

it('reports a revoked refresh token as requiring reauthorization', function (): void {
    Allegro::account()->storeToken(expiredToken());
    Http::fake([TestCase::OAUTH . '/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid refresh token'], 400)]);

    try {
        Allegro::me();
        $this->fail('Expected an exception.');
    } catch (AuthenticationException $exception) {
        expect($exception->requiresReauthorization())->toBeTrue()
            ->and($exception->error())->toBe('invalid_grant');
    }
});

it('refreshes the token once and repeats the request after HTTP 401', function (): void {
    connected();
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(tokenPayload('access-2', 'refresh-2')),
        TestCase::API . '/me' => Http::sequence()
            ->push(['error' => 'invalid_token', 'error_description' => 'Access token expired'], 401)
            ->push(['id' => '1']),
    ]);

    expect(Allegro::me()['id'])->toBe('1');

    $recorded = Http::recorded()->map(fn(array $pair): string => $pair[0]->method() . ' ' . $pair[0]->url())->all();
    expect($recorded)->toBe([
        'GET ' . TestCase::API . '/me',
        'POST ' . TestCase::OAUTH . '/token',
        'GET ' . TestCase::API . '/me',
    ]);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/me' && $request->hasHeader('Authorization', 'Bearer access-2'));
});

it('gives up after a second HTTP 401 and parses the OAuth error envelope', function (): void {
    connected();
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(tokenPayload('access-2', 'refresh-2')),
        TestCase::API . '/me' => Http::response(['error' => 'unauthorized', 'error_description' => 'Full authentication is required to access this resource'], 401, ['Trace-Id' => 'trace-401']),
    ]);

    try {
        Allegro::me();
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->httpStatus())->toBe(401)
            ->and($exception->traceId())->toBe('trace-401')
            ->and($exception->errors()[0]['code'])->toBe('unauthorized')
            ->and($exception->getMessage())->toContain('Full authentication is required');
    }

    Http::assertSentCount(3);
});

it('repeats a write only after HTTP 401 and never after other failures', function (): void {
    connected();
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(tokenPayload('access-2', 'refresh-2')),
        TestCase::API . '/order/checkout-forms/*' => Http::sequence()
            ->push(['error' => 'invalid_token'], 401)
            ->push(['errors' => [['code' => 'InternalServerError', 'message' => 'Try again']]], 503),
    ]);

    expect(fn() => Allegro::orders()->addTrackingNumber('order-1', 'INPOST', '6200000000000'))
        ->toThrow(ApiException::class, 'HTTP 503');

    Http::assertSentCount(3);
});

it('does not expire a token that another process already replaced', function (): void {
    connected();
    Http::fake([TestCase::API . '/me' => function () {
        Allegro::account()->storeToken(new AccessToken('access-other', CarbonImmutable::now()->addHours(12), 'refresh-other'));

        return Http::response(['error' => 'invalid_token'], 401);
    }]);

    expect(fn() => Allegro::me())->toThrow(ApiException::class);
    expect(Allegro::account()->token()?->accessToken)->toBe('access-other');
    Http::assertNotSent(fn(Request $request): bool => str_contains($request->url(), '/auth/oauth/'));
});

it('forgets the tokens on disconnect', function (): void {
    connected();
    Allegro::disconnect();

    expect(Allegro::account()->token())->toBeNull()
        ->and(Allegro::isConnected())->toBeFalse();
});

it('keeps tokens in a custom token store', function (): void {
    $store = new class implements TokenStore {
        /** @var array<string, AccessToken> */
        public array $tokens = [];

        public function get(string $key): ?AccessToken
        {
            return $this->tokens[$key] ?? null;
        }

        public function put(string $key, AccessToken $token): void
        {
            $this->tokens[$key] = $token;
        }

        public function forget(string $key): void
        {
            unset($this->tokens[$key]);
        }
    };
    app()->instance(TokenStore::class, $store);
    app(AllegroManager::class)->purge();
    $store->put('tenant-9', expiredToken('tenant-refresh'));
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(tokenPayload('tenant-access', 'tenant-refresh-2')),
        TestCase::API . '/me' => Http::response(['id' => '9']),
    ]);

    Allegro::forUser('tenant-9')->me();

    expect($store->tokens['tenant-9']->refreshToken)->toBe('tenant-refresh-2')
        ->and(Allegro::tokenStore())->toBe($store);
});

it('keeps the current refresh token when a refresh response omits it', function (): void {
    Allegro::account()->storeToken(expiredToken('refresh-keep'));
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(['access_token' => 'access-new', 'expires_in' => 43199]),
        TestCase::API . '/me' => Http::response(['id' => '1']),
    ]);

    Allegro::me();

    expect(Allegro::account()->token()?->refreshToken)->toBe('refresh-keep');
});

it('reports a missing connection as requiring authorization', function (): void {
    Http::fake();

    expect(fn() => Allegro::forUser('new-seller')->me())
        ->toThrow(fn(AuthenticationException $exception) => expect($exception->requiresReauthorization())->toBeTrue()
            ->and($exception->error())->toBe('not_connected'));
});
