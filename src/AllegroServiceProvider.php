<?php

declare(strict_types=1);

namespace Beziworld\Allegro;

use Beziworld\Allegro\Console\DeviceLoginCommand;
use Beziworld\Allegro\Console\PollOffersCommand;
use Beziworld\Allegro\Console\PollOrdersCommand;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the Allegro account manager and the artisan commands.
 */
final class AllegroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/allegro.php', 'allegro');

        $this->app->singleton(AllegroManager::class, static fn(Application $app): AllegroManager => new AllegroManager(
            $app,
            $app->make(HttpFactory::class),
            $app->make(Config::class),
            $app->make(CacheFactory::class),
        ));

        $this->app->bind(AllegroClient::class, static fn(Application $app): AllegroClient => $app->make(AllegroManager::class)->account());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/allegro.php' => $this->app->configPath('allegro.php')], 'allegro-config');

            $this->commands([
                PollOrdersCommand::class,
                PollOffersCommand::class,
                DeviceLoginCommand::class,
            ]);
        }
    }
}
