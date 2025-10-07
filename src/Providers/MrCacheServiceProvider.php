<?php

declare(strict_types=1);

namespace MrCache\Providers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\ServiceProvider;
use MrCache\Commands\MrCacheStatsCommand;
use MrCache\Commands\MrCacheFlushCommand;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\InvalidationInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use MrCache\Macros\EloquentCacheMacros;
use MrCache\Services\CacheManager;
use MrCache\Services\InvalidationManager;
use MrCache\Services\KeyGenerator;
use MrCache\Services\RedisNativeClient;

class MrCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/mrcache.php',
            'mrcache'
        );

        $this->app->singleton(CacheClientInterface::class, function ($app) {
            return new RedisNativeClient($app['config']['mrcache']);
        });

        $this->app->singleton(KeyGeneratorInterface::class, function ($app) {
            return new KeyGenerator($app['config']['mrcache']);
        });

        $this->app->singleton(CacheManager::class, function ($app) {
            // Add a public method to CacheManager to decode payloads
            // This is a quick way to share the logic with InvalidationManager
            if (!method_exists(CacheManager::class, 'decodePayload')) {
                \MrCache\Services\CacheManager::macro('decodePayload', function (string $rawPayload) {
                    // @phpstan-ignore-next-line
                    return $this->decodePayload($rawPayload);
                });
            }

            return new CacheManager(
                $app->make(CacheClientInterface::class),
                $app->make(KeyGeneratorInterface::class),
                $app['config']['mrcache']
            );
        });

        $this->app->singleton(InvalidationInterface::class, function ($app) {
            return new InvalidationManager(
                $app->make(CacheClientInterface::class),
                $app->make(KeyGeneratorInterface::class),
                $app['config']['mrcache'],
                $app->make(CacheManager::class)
            );
        });

        $this->commands([
            MrCacheFlushCommand::class,
            MrCacheStatsCommand::class,
        ]);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/mrcache.php' => config_path('mrcache.php'),
            ], 'mrcache-config');
        }

        $this->registerMacros();
    }

    private function registerMacros(): void
    {
        // Make the decodePayload method public for InvalidationManager to use
        $reflection = new \ReflectionClass(CacheManager::class);
        $method = $reflection->getMethod('decodePayload');
        if (!$method->isPublic()) {
            $method->setAccessible(true);
        }
        
        InvalidationManager::macro('decodePayload', function (string $payload) use ($method) {
            return $method->invoke(app(CacheManager::class), $payload);
        });


        $macros = new EloquentCacheMacros();
        Builder::macro('withoutCaching', $macros->withoutCaching());
        Builder::macro('withCustomTTL', $macros->withCustomTTL());
        Builder::macro('cache', $macros->cache());
    }
}
