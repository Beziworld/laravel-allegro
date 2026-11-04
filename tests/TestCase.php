<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Tests;

use Beziworld\Allegro\AllegroServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string API = 'https://api.allegro.pl.allegrosandbox.pl';

    public const string OAUTH = 'https://allegro.pl.allegrosandbox.pl/auth/oauth';

    public const string USER_AGENT = 'TestShop/1.0 (+https://shop.test)';

    protected function getPackageProviders($app): array
    {
        return [AllegroServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('allegro.client_id', 'client-id');
        $app['config']->set('allegro.client_secret', 'client-secret');
        $app['config']->set('allegro.sandbox', true);
        $app['config']->set('allegro.user_agent', self::USER_AGENT);
        $app['config']->set('allegro.redirect_uri', 'https://shop.test/allegro/callback');
        $app['config']->set('allegro.polling.interval_ms', 0);
        $app['config']->set('allegro.accounts', [
            'default' => ['refresh_token' => null],
            'second' => ['token_key' => 'shop-two', 'language' => 'en-US'],
        ]);
    }
}
