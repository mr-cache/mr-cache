<?php

declare(strict_types=1);

namespace MrCache\Commands;

use Illuminate\Console\Command;

final class MrCacheInstallCommand extends Command
{
    protected $signature = 'mrcache:install {--outbox : Also publish the optional outbox migration}';
    protected $description = 'Publish configuration without overwriting existing files or enabling caching.';

    public function handle(): int
    {
        $status = $this->call('vendor:publish', ['--provider' => \MrCache\Providers\MrCacheServiceProvider::class, '--tag' => 'mrcache-config']);
        if ($status === self::SUCCESS && $this->option('outbox')) {
            $status = $this->call('vendor:publish', ['--provider' => \MrCache\Providers\MrCacheServiceProvider::class, '--tag' => 'mrcache-migrations']);
        }
        $this->info('Review config, tenant context and consistency before enabling. Run mrcache:doctor after Redis setup.');
        $this->line('Outbox is opt-in: run its migration on each intended connection, wrap writes explicitly, and schedule mrcache:outbox.');
        return $status;
    }
}
