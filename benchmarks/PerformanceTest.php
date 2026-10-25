<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Services\Generation;
use MrCache\Tests\Models\Post;
use MrCache\Tests\TestCase;

/** Standalone Testbench harness; uses an in-memory DB and a random Redis namespace. */
final class PerformanceTest extends TestCase
{
    public function test_measure(): void
    {
        if (!getenv('MRCACHE_TEST_REDIS_PORT')) {
            throw new RuntimeException('Set MRCACHE_TEST_REDIS_PORT to an isolated Redis server port.');
        }
        $client = $this->app->make(CacheClientInterface::class);
        try {
            config(['mrcache.store_metrics' => false]);
            Schema::create('posts', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title');
                $table->integer('counter')->default(0);
                $table->timestamps();
                $table->softDeletes();
            });
            foreach (array_chunk(range(1, 500), 100) as $chunk) {
                DB::table('posts')->insert(array_map(fn ($id) => ['id' => $id, 'title' => str_repeat('x', 256)], $chunk));
            }
            $generation = new Generation($client, config('mrcache'));
            $results = [];
            $results['uncached_one_row'] = $this->sample(200, fn () => Post::withoutCaching()->find(1));
            $results['cold_one_row'] = $this->sample(200, fn () => Post::find(1), fn () => $generation->rotate());
            Post::find(1);
            $results['warm_one_row'] = $this->sample(200, fn () => Post::find(1));
            $results['write_and_invalidation'] = $this->sample(200, fn () => DB::table('posts')->where('id', 1)->increment('counter'));
            $results['uncached_500_rows'] = $this->sample(50, fn () => Post::withoutCaching()->get());
            $results['cold_500_rows'] = $this->sample(50, fn () => Post::all(), fn () => $generation->rotate());
            Post::all();
            $results['warm_500_rows'] = $this->sample(50, fn () => Post::all());
            $results['concurrent_cold_hot_key'] = $this->concurrent($generation);
            $info = $client->info('server');
            $output = json_encode([
                'php' => PHP_VERSION,
                'laravel' => Illuminate\Foundation\Application::VERSION,
                'client' => config('mrcache.redis.client'),
                'redis' => $info['redis_version'] ?? $info['Server']['redis_version'] ?? 'unknown',
                'predis' => \Composer\InstalledVersions::getPrettyVersion('predis/predis'),
                'phpredis' => phpversion('redis') ?: null,
                'database' => 'SQLite :memory:',
                'rows' => 500,
                'title_bytes' => 256,
                'metrics' => false,
                'stampede_protection' => config('mrcache.stampede.enabled', true),
                'invalidation_mode' => config('mrcache.invalidation.mode', 'global'),
                'key_format' => 3,
                'units' => 'microseconds, monotonic hrtime; cold setup rotation excluded',
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            if ($path = getenv('MRCACHE_BENCHMARK_OUTPUT')) {
                file_put_contents($path, $output);
            } else {
                fwrite(STDOUT, $output);
            }
            $this->assertNotEmpty($results);
        } finally {
            foreach ($client->scanKeys(config('mrcache.prefix').':*') as $key) {
                $client->del($key);
            }
        }
    }

    private function concurrent(Generation $generation): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            return ['status' => 'not_available', 'reason' => 'pcntl and Unix socket pairs required'];
        }
        $generation->rotate();
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $file = tempnam(sys_get_temp_dir(), 'mrcache_bench_');
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Unable to fork benchmark worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $this->app->instance(CacheClientInterface::class, new \MrCache\Services\RedisNativeClient(config('mrcache')));
                $this->app->forgetInstance(\MrCache\Services\CacheManager::class);
                $selects = 0;
                DB::listen(function ($event) use (&$selects) {
                    if (str_starts_with(strtolower($event->sql), 'select')) {
                        $selects++;
                    }
                });
                fread($sockets[1], 1);
                $timings = $this->sample(100, fn () => Post::find(1));
                file_put_contents($file, json_encode(['timings' => $timings, 'database_selects' => $selects], JSON_THROW_ON_ERROR));
                fclose($sockets[1]);
                exit(0);
            }
            fclose($sockets[1]);
            $workers[] = [$pid, $file, $sockets[0]];
        }
        $start = hrtime(true);
        foreach ($workers as [$pid, $file, $socket]) {
            fwrite($socket, '1');
            fclose($socket);
        }
        $measurements = [];
        foreach ($workers as [$pid, $file]) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException('Benchmark worker failed.');
            }
            $measurements[] = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
            unlink($file);
        }
        return [
            'workers' => 4, 'requests_per_worker' => 100,
            'wall_ms' => round((hrtime(true) - $start) / 1000000, 2),
            'database_selects' => array_sum(array_column($measurements, 'database_selects')),
            'worker_results' => $measurements,
        ];
    }

    private function sample(int $count, callable $operation, ?callable $before = null): array
    {
        $samples = [];
        for ($i = 0; $i < $count; $i++) {
            $before && $before();
            $start = hrtime(true);
            $operation();
            $samples[] = (hrtime(true) - $start) / 1000;
        }
        sort($samples);
        return [
            'samples' => $count,
            'mean_us' => round(array_sum($samples) / $count, 2),
            'median_us' => round($samples[(int) floor(($count - 1) * 0.5)], 2),
            'p95_us' => round($samples[(int) floor(($count - 1) * 0.95)], 2),
        ];
    }
}
