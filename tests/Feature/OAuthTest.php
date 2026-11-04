<?php

declare(strict_types=1);

use Beziworld\Allegro\Data\DeviceCode;
use Beziworld\Allegro\Data\DevicePollResult;
use Beziworld\Allegro\Exceptions\AuthenticationException;
use Beziworld\Allegro\Exceptions\InvalidConfiguration;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\OAuth\Pkce;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

it('builds the consent URL with PKCE, scopes and state', function (): void {
    $url = Allegro::authorizationUrl('csrf-state', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', ['allegro:api:orders:read', 'allegro:api:orders:write'], 'confirm');

    expect($url)->toStartWith(TestCase::OAUTH . '/authorize?');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($query)->toBe([
        'response_type' => 'code',
        'client_id' => 'client-id',
        'redirect_uri' => 'https://shop.test/allegro/callback',
        'code_challenge_method' => 'S256',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'scope' => 'allegro:api:orders:read allegro:api:orders:write',
        'state' => 'csrf-state',
        'prompt' => 'confirm',
    ]);
});

it('omits optional consent parameters and uses the production host outside the sandbox', function (): void {
    config()->set('allegro.sandbox', false);

    expect(Allegro::authorizationUrl())->toBe('https://allegro.pl/auth/oauth/authorize?response_type=code&client_id=client-id&redirect_uri=https%3A%2F%2Fshop.test%2Fallegro%2Fcallback');
});

it('requires a redirect URI for the authorization code flow', function (): void {
    config()->set('allegro.redirect_uri', null);

    Allegro::authorizationUrl();
})->throws(InvalidConfiguration::class, 'ALLEGRO_REDIRECT_URI');

it('generates PKCE verifiers of the requested length', function (): void {
    $verifier = Pkce::generateVerifier(43);

    expect(strlen($verifier))->toBe(43)
        ->and($verifier)->toMatch('/^[A-Za-z0-9_-]+$/')
        ->and(strlen(Pkce::generateVerifier()))->toBe(64);
});

it('rejects PKCE verifiers outside the allowed length', function (): void {
    Pkce::generateVerifier(20);
})->throws(InvalidArgumentException::class);

it('exchanges the authorization code with HTTP Basic client authentication and stores the tokens', function (): void {
    Http::fake([TestCase::OAUTH . '/token' => Http::response(tokenPayload())]);

    $token = Allegro::exchangeCode('auth-code');

    expect($token->accessToken)->toBe('access-1')
        ->and($token->refreshToken)->toBe('refresh-1')
        ->and($token->scope)->toBe('allegro:api:orders:read')
        ->and(Allegro::account()->token()?->refreshToken)->toBe('refresh-1');

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::OAUTH . '/token'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('client-id:client-secret'))
        && $request->hasHeader('User-Agent', TestCase::USER_AGENT)
        && ($request->data()['grant_type'] ?? null) === 'authorization_code'
        && ($request->data()['code'] ?? null) === 'auth-code'
        && ($request->data()['redirect_uri'] ?? null) === 'https://shop.test/allegro/callback');
});

it('exchanges the code with the PKCE verifier and client id instead of Basic authentication', function (): void {
    Http::fake([TestCase::OAUTH . '/token' => Http::response(tokenPayload())]);

    Allegro::forUser('seller-7')->exchangeCode('auth-code', 'verifier-value');

    Http::assertSent(fn(Request $request): bool => ! $request->hasHeader('Authorization')
        && ($request->data()['code_verifier'] ?? null) === 'verifier-value'
        && ($request->data()['client_id'] ?? null) === 'client-id');
    expect(Allegro::forUser('seller-7')->token()?->accessToken)->toBe('access-1')
        ->and(Allegro::account()->token())->toBeNull();
});

it('reports a rejected code exchange with the OAuth error', function (): void {
    Http::fake([TestCase::OAUTH . '/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid authorization code'], 400, ['Trace-Id' => 'trace-oauth'])]);

    try {
        Allegro::exchangeCode('expired');
        $this->fail('Expected an exception.');
    } catch (AuthenticationException $exception) {
        expect($exception->error())->toBe('invalid_grant')
            ->and($exception->httpStatus())->toBe(400)
            ->and($exception->traceId())->toBe('trace-oauth')
            ->and($exception->requiresReauthorization())->toBeTrue()
            ->and($exception->getMessage())->toContain('Invalid authorization code');
    }
});

it('starts the device flow', function (): void {
    Http::fake([TestCase::OAUTH . '/device*' => Http::response([
        'user_code' => 'cbt3zdu4g',
        'device_code' => '645629715',
        'expires_in' => '3600',
        'interval' => '5',
        'verification_uri' => 'https://allegro.pl.allegrosandbox.pl/skojarz-aplikacje',
        'verification_uri_complete' => 'https://allegro.pl.allegrosandbox.pl/skojarz-aplikacje?code=cbt3zdu4g',
    ])]);

    $code = Allegro::startDeviceFlow(['allegro:api:orders:read']);

    expect($code->deviceCode)->toBe('645629715')
        ->and($code->formattedUserCode())->toBe('cbt 3zd u4g')
        ->and($code->interval)->toBe(5)
        ->and($code->verificationUriComplete)->toEndWith('?code=cbt3zdu4g')
        ->and($code->isExpired())->toBeFalse();

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::OAUTH . '/device?client_id=client-id'
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('client-id:client-secret'))
        && ($request->data()['client_id'] ?? null) === 'client-id'
        && ($request->data()['scope'] ?? null) === 'allegro:api:orders:read');
});

it('reports pending, slow down and granted device polls and stores the granted token', function (): void {
    Http::fake([TestCase::OAUTH . '/token' => Http::sequence()
        ->push(['error' => 'authorization_pending'], 400)
        ->push(['error' => 'slow_down'], 400)
        ->push(tokenPayload('device-access', 'device-refresh'))]);

    $client = Allegro::account('second');

    expect($client->pollDeviceToken('645629715')->status)->toBe(DevicePollResult::PENDING)
        ->and($client->pollDeviceToken('645629715')->status)->toBe(DevicePollResult::SLOW_DOWN)
        ->and($client->pollDeviceToken('645629715')->isGranted())->toBeTrue()
        ->and(Allegro::forUser('shop-two')->token()?->accessToken)->toBe('device-access');

    Http::assertSent(fn(Request $request): bool => ($request->data()['grant_type'] ?? null) === 'urn:ietf:params:oauth:grant-type:device_code'
        && ($request->data()['device_code'] ?? null) === '645629715');
});

it('stops polling when the seller denies access', function (): void {
    Http::fake([TestCase::OAUTH . '/token' => Http::response(['error' => 'access_denied'], 400)]);

    Allegro::pollDeviceToken('645629715');
})->throws(AuthenticationException::class, 'access_denied');

it('waits for the device token honouring the interval and slow_down', function (): void {
    Sleep::fake();
    Http::fake([TestCase::OAUTH . '/token' => Http::sequence()
        ->push(['error' => 'authorization_pending'], 400)
        ->push(['error' => 'slow_down'], 400)
        ->push(tokenPayload('device-access'))]);

    $polls = 0;
    $token = Allegro::waitForDeviceToken(DeviceCode::fromArray(['device_code' => 'dc', 'user_code' => 'uc', 'interval' => 5, 'expires_in' => 600]), function () use (&$polls): void {
        $polls++;
    });

    expect($token->accessToken)->toBe('device-access')
        ->and($polls)->toBe(2);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds(), Sleep::for(10)->seconds()]);
});

it('fails when the device code expires', function (): void {
    Http::fake();

    Allegro::waitForDeviceToken(DeviceCode::fromArray(['device_code' => 'dc', 'user_code' => 'uc', 'expires_in' => 0]));
})->throws(AuthenticationException::class, 'expired');

it('uses a client credentials token for application resources and reuses it', function (): void {
    Http::fake([
        TestCase::OAUTH . '/token' => Http::response(['access_token' => 'app-token', 'token_type' => 'bearer', 'expires_in' => 43199]),
        TestCase::API . '/sale/categories*' => Http::response(['categories' => [['id' => '954b95b6-43cf-4104-8354-dea4d9b10ddf', 'name' => 'Dom i Ogród', 'leaf' => false, 'parent' => null]]]),
    ]);

    $categories = Allegro::application()->catalog()->categories();
    Allegro::application()->catalog()->categories();

    expect($categories[0]->name)->toBe('Dom i Ogród')
        ->and($categories[0]->parentId)->toBeNull()
        ->and(Allegro::application()->isApplication())->toBeTrue();

    Http::assertSent(fn(Request $request): bool => ($request->data()['grant_type'] ?? null) === 'client_credentials'
        && $request->hasHeader('Authorization', 'Basic ' . base64_encode('client-id:client-secret')));
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/sale/categories' && hasAllegroHeaders($request, 'app-token'));
    Http::assertSentCount(3);
});
