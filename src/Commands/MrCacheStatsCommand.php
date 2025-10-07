<?php

declare(strict_types=1);

namespace MrCache\Commands;

use Illuminate\Console\Command;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\KeyGeneratorInterface;

class MrCacheStatsCommand extends Command
{
    protected $signature = 'mrcache:stats';
    protected $description = 'Display statistics for the MrCache.';

    public function handle(CacheClientInterface $client, KeyGeneratorInterface $keyGenerator): int
    {
        if (!config('mrcache.store_metrics', false)) {
            $this->warn('Metrics are currently disabled. Please enable `store_metrics` in your `config/mrcache.php` file.');
            return self::FAILURE;
        }

        $this->info('Fetching MrCache Statistics...');

        $hitsKey = $keyGenerator->getMetricsKey('hits');
        $missesKey = $keyGenerator->getMetricsKey('misses');

        $hits = (int) $client->get($hitsKey);
        $misses = (int) $client->get($missesKey);
        $total = $hits + $misses;

        $hitRate = $total > 0 ? number_format(($hits / $total) * 100, 2) . '%' : 'N/A';

        $info = $client->info('keyspace');
        $dbName = 'db' . config('mrcache.redis.database', 0);
        $keyspaceInfo = $info[$dbName] ?? null;
        $totalKeys = 'N/A';
        if ($keyspaceInfo && is_string($keyspaceInfo)) {
             parse_str(str_replace(',', '&', $keyspaceInfo), $output);
             $totalKeys = $output['keys'] ?? 'N/A';
        }


        $this->table(
            ['Metric', 'Value'],
            [
                ['Cache Hits', $hits],
                ['Cache Misses', $misses],
                ['Total Lookups', $total],
                ['Hit Rate', $hitRate],
                ['Total Keys in DB', $totalKeys],
            ]
        );

        return self::SUCCESS;
    }
}
