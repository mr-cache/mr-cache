<?php

declare(strict_types=1);

namespace MrCache\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use MrCache\Providers\MrCacheServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [MrCacheServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testdb');
        $app['config']->set('database.connections.testdb', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('mrcache.enabled', true);
        $app['config']->set('mrcache.prefix', 'mrcache_test_'.bin2hex(random_bytes(8)));
        $app['config']->set('mrcache.redis.client', getenv('MRCACHE_TEST_REDIS_CLIENT') ?: 'predis');
        $app['config']->set('mrcache.redis.port', (int) (getenv('MRCACHE_TEST_REDIS_PORT') ?: 6379));
        $app['config']->set('mrcache.strict_mode', true);
        $app['config']->set('mrcache.store_metrics', true);
    }
}
