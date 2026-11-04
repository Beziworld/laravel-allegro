<?php

declare(strict_types=1);

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\AccessToken;
use Beziworld\Allegro\Facades\Allegro;
use Beziworld\Allegro\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Token endpoint response as returned by Allegro.
 *
 * @return array<string, mixed>
 */
function tokenPayload(string $access = 'access-1', string $refresh = 'refresh-1', int $expiresIn = 43199): array
{
    return ['access_token' => $access, 'token_type' => 'bearer', 'refresh_token' => $refresh, 'expires_in' => $expiresIn, 'scope' => 'allegro:api:orders:read', 'jti' => 'jti-1'];
}

/**
 * Stores a valid token for the account (default account when null) and returns its client.
 */
function connected(?string $account = null, string $access = 'access-1', string $refresh = 'refresh-1'): AllegroClient
{
    $client = Allegro::account($account);
    $client->storeToken(new AccessToken($access, CarbonImmutable::now()->addHours(12), $refresh));

    return $client;
}

/**
 * Whether the request carries the headers every Allegro API call must have.
 */
function hasAllegroHeaders(Request $request, string $token = 'access-1', string $accept = AllegroClient::MEDIA_TYPE): bool
{
    return $request->hasHeader('Authorization', 'Bearer ' . $token)
        && $request->hasHeader('Accept', $accept)
        && $request->hasHeader('User-Agent', TestCase::USER_AGENT);
}

/**
 * Decoded JSON body of a recorded request.
 *
 * @return array<array-key, mixed>
 */
function jsonBody(Request $request): array
{
    $decoded = json_decode($request->body(), true);

    return is_array($decoded) ? $decoded : [];
}
