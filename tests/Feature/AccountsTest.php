<?php

declare(strict_types=1);

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\AllegroManager;
use Beziworld\Allegro\Exceptions\InvalidConfiguration;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('keeps separate tokens for named accounts and runtime sellers', function (): void {
    connected(null, 'token-default');
    connected('second', 'token-second');
    Allegro::forUser('seller-42')->storeToken(tokenPayload('token-seller'));
    Http::fake([TestCase::API . '/me' => Http::response(['id' => '1'])]);

    Allegro::me();
    Allegro::account('second')->me();
    Allegro::forUser('seller-42')->me();

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-default'));
    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-second'));
    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-seller'));
    expect(Allegro::account('second')->key())->toBe('shop-two')
        ->and(Allegro::forUser('seller-42')->key())->toBe('seller-42');
});

it('applies account options over the shared configuration', function (): void {
    connected('second', 'token-second');
    Http::fake([TestCase::API . '/me' => Http::response(['id' => '1'])]);

    Allegro::account('second')->me();

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Accept-Language', 'en-US'));
});

it('accepts option overrides for runtime sellers', function (): void {
    $client = Allegro::forUser('seller-cz', ['language' => 'cs-CZ']);

    expect($client->config()->language)->toBe('cs-CZ')
        ->and($client->config()->clientId)->toBe('client-id');
});

it('forwards facade calls to the default account', function (): void {
    expect(Allegro::account())->toBe(app(AllegroManager::class)->account('default'))
        ->and(Allegro::getDefaultAccount())->toBe('default')
        ->and(Allegro::accountNames())->toBe(['default', 'second'])
        ->and(app(AllegroClient::class)->key())->toBe('default');
});

it('rejects unknown accounts', function (): void {
    Allegro::account('missing');
})->throws(InvalidConfiguration::class, 'Allegro account "missing" is not defined');

it('requires the User-Agent mandated by Allegro', function (): void {
    config()->set('allegro.user_agent', null);

    Allegro::account();
})->throws(InvalidConfiguration::class, 'ALLEGRO_USER_AGENT');

it('requires the application credentials', function (): void {
    config()->set('allegro.client_secret', '');

    Allegro::account();
})->throws(InvalidConfiguration::class, 'ALLEGRO_CLIENT_SECRET');

it('uses the production API host outside the sandbox', function (): void {
    config()->set('allegro.sandbox', false);
    connected();
    Http::fake(['https://api.allegro.pl/me' => Http::response(['id' => '1'])]);

    Allegro::me();

    Http::assertSent(fn(Request $request): bool => $request->url() === 'https://api.allegro.pl/me');
    expect(Allegro::account()->config()->environment())->toBe('production');
});

it('supports a single account configuration without the accounts list', function (): void {
    config()->set('allegro.accounts', []);

    expect(Allegro::accountNames())->toBe(['default'])
        ->and(Allegro::account()->key())->toBe('default');
    expect(fn() => Allegro::account('other'))->toThrow(InvalidConfiguration::class);
});

it('forgets resolved clients on purge', function (): void {
    $first = Allegro::account();
    Allegro::purge();

    expect(Allegro::account())->not->toBe($first);
});
