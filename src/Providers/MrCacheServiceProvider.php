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
use MrCache\Services\QueryColumnMatcher;

use MrCache\Services\CacheObserver;
use MrCache\Services\PolicyResolver;
use MrCache\Services\CacheInspector;
use MrCache\Services\QueryEligibility;
use MrCache\Services\CacheInvalidator;
use MrCache\Services\InvalidationOutbox;
use MrCache\Commands\MrCacheDoctorCommand;
use MrCache\Commands\MrCacheInstallCommand;
use MrCache\Commands\MrCacheOutboxCommand;

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
            return new CacheManager(
                $app->make(CacheClientInterface::class),
                $app->make(KeyGeneratorInterface::class),
                $app->make(QueryColumnMatcher::class),
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

        $this->app->singleton(CacheObserver::class, fn ($app) => new CacheObserver($app['events']));
        $this->app->bind(PolicyResolver::class, fn ($app) => new PolicyResolver($app['config']['mrcache']));
        $this->app->bind(CacheInspector::class, fn ($app) => new CacheInspector(
            $app->make(PolicyResolver::class), new QueryEligibility()));
        $this->app->singleton(CacheInvalidator::class, fn ($app) => new CacheInvalidator(
            $app->make(CacheClientInterface::class), $app['config']['mrcache'], $app->make(CacheObserver::class)));
        $this->app->bind(InvalidationOutbox::class, fn ($app) => new InvalidationOutbox(
            $app['db'], $app->make(CacheInvalidator::class), $app->make(CacheObserver::class), $app['config']['mrcache']));

        $this->commands([
            MrCacheDoctorCommand::class,
            MrCacheInstallCommand::class,
            MrCacheOutboxCommand::class,
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
            $this->publishes([__DIR__.'/../../database/migrations/2026_10_25_000001_create_mrcache_outbox_table.php' => database_path('migrations/2026_10_25_000001_create_mrcache_outbox_table.php')], 'mrcache-migrations');
        }

        $this->registerMacros();

        $invalidate = function (): void {
            if (config('mrcache.enabled', false)) {
                $this->app->make(CacheInvalidator::class)->invalidateAll();
            }
        };
        $this->app['events']->listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event): void {
            // Ordinary SELECT is treated as read-only. Side-effecting SELECT
            // functions need manual invalidation; unknown statements invalidate.
            if (!preg_match('/^\s*select\b/i', $event->sql)) {
                $table = \MrCache\Services\TableNames::mutation($event->sql);
                $internal = ($event->connection->getTablePrefix()).config('mrcache.outbox.table', 'mrcache_outbox');
                if ($table !== strtolower($internal) && config('mrcache.enabled', false)) {
                    $this->app->make(CacheInvalidator::class)->observe($event->sql);
                }
            }
        });
        $this->app['events']->listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($event) use ($invalidate): void {
            if ($event->connection->transactionLevel() === 0) {
                $invalidate();
            }
        });
    }

    private function registerMacros(): void
    {
        $macros = new EloquentCacheMacros();
        foreach (['withoutCaching', 'withCustomTTL', 'cache'] as $name) {
            if (!Builder::hasGlobalMacro($name)) {
                Builder::macro($name, $macros->$name());
            }
        }
    }
}
