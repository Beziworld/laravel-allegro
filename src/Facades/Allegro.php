<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Facades;

use Beziworld\Allegro\AllegroManager;
use Illuminate\Support\Facades\Facade;

/**
 * Facade of the Allegro account manager; calls without account() go to the default account.
 *
 * @method static \Beziworld\Allegro\AllegroClient account(?string $name = null)
 * @method static \Beziworld\Allegro\AllegroClient forUser(string $key, array<string, mixed> $overrides = [])
 * @method static \Beziworld\Allegro\AllegroClient application()
 * @method static string authorizationUrl(?string $state = null, ?string $codeVerifier = null, ?array<int, string> $scopes = null, ?string $prompt = null)
 * @method static \Beziworld\Allegro\Data\DeviceCode startDeviceFlow(?array<int, string> $scopes = null)
 * @method static \Beziworld\Allegro\OAuth\OAuthClient oauth()
 * @method static list<string> accountNames()
 * @method static string getDefaultAccount()
 * @method static \Beziworld\Allegro\Contracts\TokenStore tokenStore()
 * @method static void purge(?string $name = null)
 * @method static \Beziworld\Allegro\Resources\Orders orders()
 * @method static \Beziworld\Allegro\Resources\Offers offers()
 * @method static \Beziworld\Allegro\Resources\ShipmentManagement shipmentManagement()
 * @method static \Beziworld\Allegro\Resources\Billing billing()
 * @method static \Beziworld\Allegro\Resources\Messaging messaging()
 * @method static \Beziworld\Allegro\Resources\CustomerReturns customerReturns()
 * @method static \Beziworld\Allegro\Resources\Catalog catalog()
 * @method static array<array-key, mixed> me()
 * @method static array<array-key, mixed> request(string $method, string $path, array<string, mixed> $query = [], ?array<array-key, mixed> $body = null, string $mediaType = 'application/vnd.allegro.public.v1+json')
 * @method static \Illuminate\Http\Client\Response send(string $method, string $path, array<string, mixed> $query = [], ?array<array-key, mixed> $body = null, string $mediaType = 'application/vnd.allegro.public.v1+json', ?string $accept = null, bool $followRedirects = true)
 * @method static \Beziworld\Allegro\Data\AccessToken exchangeCode(string $code, ?string $codeVerifier = null)
 * @method static \Beziworld\Allegro\Data\AccessToken waitForDeviceToken(\Beziworld\Allegro\Data\DeviceCode $deviceCode, ?\Closure(\Beziworld\Allegro\Data\DevicePollResult): void $onPoll = null)
 * @method static \Beziworld\Allegro\Data\DevicePollResult pollDeviceToken(\Beziworld\Allegro\Data\DeviceCode|string $deviceCode)
 * @method static bool isConnected()
 * @method static void disconnect()
 * @method static string accessToken()
 * @method static ?string lastTraceId()
 *
 * @see AllegroManager
 * @see \Beziworld\Allegro\AllegroClient
 */
final class Allegro extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AllegroManager::class;
    }
}
