<?php

namespace MrCache\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use MrCache\Providers\MrCacheServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            MrCacheServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testdb');
        $app['config']->set('database.connections.testdb', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Use our mock cache config
        $app['config']->set('mrcache.enabled', true);
        $app['config']->set('mrcache.store_metrics', true);
    }
}
