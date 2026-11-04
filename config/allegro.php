<?php

declare(strict_types=1);

use Beziworld\Allegro\TokenStores\CacheTokenStore;

return [

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    |
    | Client ID and secret of the application registered at
    | apps.developer.allegro.pl (sandbox: apps.developer.allegro.pl.allegrosandbox.pl).
    | One application serves every seller account. "redirect_uri" must match
    | the URI registered for the application (authorization code flow).
    | "scopes" limits the requested permissions, e.g.
    | ['allegro:api:orders:read', 'allegro:api:orders:write']; an empty list
    | requests every scope of the application.
    |
    */

    'client_id' => env('ALLEGRO_CLIENT_ID'),

    'client_secret' => env('ALLEGRO_CLIENT_SECRET'),

    'sandbox' => (bool) env('ALLEGRO_SANDBOX', false),

    'redirect_uri' => env('ALLEGRO_REDIRECT_URI'),

    'scopes' => [],

    /*
    |--------------------------------------------------------------------------
    | Request headers
    |--------------------------------------------------------------------------
    |
    | Allegro requires every client to send a User-Agent in the format
    | "AppName/Version (+https://info-page-url)", consistent with the name of
    | the registered application. "language" is sent as Accept-Language and
    | selects the language of error messages (pl-PL, en-US, cs-CZ, sk-SK,
    | hu-HU, uk-UA); null omits the header.
    |
    */

    'user_agent' => env('ALLEGRO_USER_AGENT'),

    'language' => env('ALLEGRO_LANGUAGE', 'pl-PL'),

    /*
    |--------------------------------------------------------------------------
    | Seller accounts
    |--------------------------------------------------------------------------
    |
    | Named seller accounts, each with its own tokens. An account may
    | override any shared option above. "token_key" is the key in the token
    | store (the account name by default) and "refresh_token" seeds the
    | store on first use, for example with a token obtained once with
    | "php artisan allegro:device-login". Sellers connected at runtime are
    | used with Allegro::forUser($key) and need no entry here.
    |
    */

    'default' => env('ALLEGRO_ACCOUNT', 'default'),

    'accounts' => [

        'default' => [
            'refresh_token' => env('ALLEGRO_REFRESH_TOKEN'),
        ],

        // 'second-shop' => [
        //     'refresh_token' => env('ALLEGRO_SECOND_SHOP_REFRESH_TOKEN'),
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Token storage
    |--------------------------------------------------------------------------
    |
    | Refresh tokens are single-use and rotate on every refresh, so the store
    | must be shared by every process (redis, database). The default store
    | keeps tokens in the cache; set "token_store" to a class implementing
    | Beziworld\Allegro\Contracts\TokenStore to keep them elsewhere, for
    | example in a sellers table. The cache store is also used for refresh
    | locks and event polling cursors.
    |
    */

    'token_store' => CacheTokenStore::class,

    'cache' => [
        'store' => env('ALLEGRO_CACHE_STORE'),
        'prefix' => 'allegro',
    ],

    /*
    |--------------------------------------------------------------------------
    | Event polling
    |--------------------------------------------------------------------------
    |
    | Used by allegro:poll-orders and allegro:poll-offers. "limit" is the page
    | size (up to 1000) and "max_pages" the number of pages read per run.
    | "start" decides where the order poller begins for a new account:
    | "latest" (only new events) or "beginning" (the last 60 days). Leave the
    | type lists empty to receive every event type.
    |
    */

    'events' => [
        'limit' => 100,
        'max_pages' => 10,
        'start' => env('ALLEGRO_EVENTS_START', 'latest'),
        'order_types' => [],
        'offer_types' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Request timeout in seconds, polling of asynchronous operations (offer
    | operations, Wysyłam z Allegro commands) and an optional log channel
    | that receives failed requests with their Trace-Id.
    |
    */

    'timeout' => (int) env('ALLEGRO_TIMEOUT', 30),

    'polling' => [
        'attempts' => (int) env('ALLEGRO_POLL_ATTEMPTS', 30),
        'interval_ms' => (int) env('ALLEGRO_POLL_INTERVAL_MS', 1000),
    ],

    'log_channel' => env('ALLEGRO_LOG_CHANNEL'),

];
