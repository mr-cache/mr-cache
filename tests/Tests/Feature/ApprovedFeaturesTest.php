<?php

declare(strict_types=1);

namespace MrCache\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use MrCache\Events\CacheEvent;
use MrCache\Services\CacheInvalidator;
use MrCache\Services\CacheManager;
use MrCache\Services\Generation;
use MrCache\Services\InvalidationOutbox;
use MrCache\Services\QueryLock;
use MrCache\Services\RedisNativeClient;
use MrCache\Tests\Concerns\UsesRedisDatabase;
use MrCache\Tests\Models\Post;
use MrCache\Tests\TestCase;
use MrCache\Testing\InteractsWithMrCache;

class ApprovedFeaturesTest extends TestCase
{
    use UsesRedisDatabase;
    use InteractsWithMrCache;

    private function configure(array $values): void
    {
        config($values);
        $this->app->forgetInstance(CacheManager::class);
        $this->app->forgetInstance(CacheInvalidator::class);
    }

    private function queryKeys(): array
    {
        return iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*'));
    }

    private function migrateOutbox(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/2026_10_25_000001_create_mrcache_outbox_table.php';
        $migration->up();
    }

    public function test_context_is_typed_canonical_bounded_and_private(): void
    {
        $a = Post::query()->cacheContext(['tenant' => 1, 'role' => 'private-role']);
        $b = Post::query()->cacheContext(['role' => 'private-role', 'tenant' => 1]);
        $c = Post::query()->cacheContext(['tenant' => '1', 'role' => 'private-role']);
        $this->assertSame($a->mrcache_context, $b->mrcache_context);
        $this->assertNotSame($a->mrcache_context, $c->mrcache_context);
        $events = [];
        app('events')->listen(CacheEvent::class, function ($event) use (&$events) { $events[] = $event; });
        $a->get();
        $b->get();
        $c->get();
        $this->assertSame(2, $this->selects());
        $this->assertCount(2, $this->queryKeys());
        $this->assertStringNotContainsString('private-role', json_encode($events));
        $this->assertStringNotContainsString('private-role', implode('', $this->queryKeys()));
        $this->expectException(\InvalidArgumentException::class);
        Post::query()->cacheContext(['unsafe' => new \stdClass()]);
    }

    public function test_policy_modes_explain_and_consumer_test_helpers(): void
    {
        $query = Post::query()->cacheConsistency('database');
        $this->assertSame('database_consistency', $query->explainCache()['reason']);
        $this->assertMrCacheOperation('bypass', fn () => $query->get(), 'database_consistency');
        $this->assertSame([], $this->queryKeys());
        $this->assertTrue(Post::query()->explainCache()['cacheable']);
        $this->assertSame('locking_read', Post::query()->lockForUpdate()->explainCache()['reason']);
        $this->assertMrCacheOperation('stored', fn () => Post::all());
        $this->assertMrCacheOperation('hit', fn () => Post::all());
    }

    public function test_central_model_policy_and_query_override(): void
    {
        $this->configure(['mrcache.policies' => [Post::class => ['ttl' => 17]]]);
        $this->assertSame(17, Post::query()->explainCache()['policy']['ttl']);
        $this->assertSame(9, Post::query()->cachePolicy(['ttl' => 9])->explainCache()['policy']['ttl']);
        $this->assertSame(5, Post::query()->cachePolicy(['ttl' => 9])->withCustomTTL(5)->explainCache()['policy']['ttl']);
        Post::query()->cachePolicy(['ttl' => 9])->get();
        $this->assertGreaterThanOrEqual(8, $this->client->getTtl($this->queryKeys()[0]));
        $this->expectException(\InvalidArgumentException::class);
        Post::query()->cachePolicy(['unknown' => true]);
    }

    public function test_lock_ownership_expiration_and_safe_publish(): void
    {
        $key = config('mrcache.prefix').':ownership';
        $lock = new QueryLock($this->client, $key, 50);
        $old = $lock->acquire();
        $this->assertNotNull($old);
        $this->assertNull($lock->acquire());
        usleep(100000);
        $new = $lock->acquire();
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        $this->assertFalse($lock->publish($old, $key.':data', 'old', 30));
        $lock->release($old);
        $this->assertTrue($lock->publish($new, $key.':data', 'new', 30));
        $this->assertSame('new', $this->client->get($key.':data'));
        $lock->release($new);
        $this->assertNull($this->client->get($key));
    }

    public function test_lock_wait_timeout_returns_database_without_publishing(): void
    {
        $this->configure(['mrcache.stampede.wait_ms' => 0]);
        $builder = Post::query();
        $builder->getQuery()->columns = ['*'];
        $key = app(KeyGeneratorInterface::class)->generateQueryKey($builder->applyScopes()).':3600:'.(new Generation($this->client, config('mrcache')))->current();
        $lockKey = config('mrcache.prefix').':lock:'.hash('sha256', $key);
        $lock = new QueryLock($this->client, $lockKey, 1000);
        $token = $lock->acquire();
        try {
            $this->assertCount(2, $this->assertMrCacheOperation('bypass', fn () => Post::all(), 'lock_wait_timeout'));
            $this->assertSame([], $this->queryKeys());
        } finally {
            $lock->release($token);
        }
    }

    public function test_expired_fill_lease_never_publishes(): void
    {
        $this->configure(['mrcache.stampede.lease_ms' => 50]);
        $builder = Post::query();
        app(CacheManager::class)->rememberQuery($builder, function () {
            usleep(100000);
            return Post::withoutCaching()->get();
        });
        $this->assertSame([], $this->queryKeys());
    }

    public function test_refresh_ahead_has_one_owner_and_rebuilds_before_expiry(): void
    {
        $query = Post::query()->cachePolicy(['ttl' => 30, 'refresh_ahead_seconds' => 5]);
        $query->get();
        $key = $this->queryKeys()[0];
        $this->client->expire($key, 2);
        $this->assertMrCacheOperation('refresh', fn () => $query->get());
        $this->assertSame(2, $this->selects());
        $this->assertGreaterThan(20, $this->client->getTtl($key));
        $query->get();
        $this->assertSame(2, $this->selects());
    }

    public function test_table_generations_keep_unrelated_queries_and_unknown_writes_flush_all(): void
    {
        Schema::create('notes', fn ($table) => $table->increments('id'));
        $this->configure(['mrcache.invalidation' => ['mode' => 'tables', 'writes' => ['notes' => ['notes'], 'posts' => ['posts']]]]);
        Post::all();
        DB::table('notes')->insert(['id' => 1]);
        Post::all();
        $this->assertSame(1, $this->selects());
        DB::table('posts')->where('id', 1)->update(['title' => 'Changed']);
        $this->assertSame('Changed', Post::find(1)->title);
        $this->assertSame(2, $this->selects());
        DB::statement('create table unrecognized (id integer)');
        Post::find(1);
        $this->assertSame(3, $this->selects());
    }

    public function test_explicit_dependencies_and_missing_table_metadata_invalidate(): void
    {
        Schema::create('notes', fn ($table) => $table->increments('id'));
        $this->configure(['mrcache.invalidation' => ['mode' => 'tables', 'writes' => ['notes' => ['notes']]]]);
        $query = Post::query()->cacheDependsOn(['notes']);
        $query->get();
        $query->get();
        $this->assertSame(1, $this->selects());
        DB::table('notes')->insert(['id' => 1]);
        $query->get();
        $this->assertSame(2, $this->selects());
        $key = config('mrcache.prefix').':v2:table:'.hash('sha256', 'notes');
        $this->assertGreaterThan(0, $this->client->getTtl($key));
        $this->client->del($key);
        $query->get();
        $this->assertSame(3, $this->selects());
        $this->assertTrue(app(CacheInvalidator::class)->invalidateTables(['posts']));
        $query->get();
        $this->assertSame(4, $this->selects());
    }

    public function test_allowed_relations_cache_rows_separately_and_invalidate_children(): void
    {
        DB::table('posts')->where('id', 2)->update(['parent_id' => 1]);
        $query = Post::where('id', 1)->with('children')->cacheRelations(['children']);
        $first = $query->get();
        $second = $query->get();
        $this->assertSame('Second', $second->first()->children->first()->title);
        $this->assertSame($first->first()->toArray(), $second->first()->toArray());
        $this->assertSame(2, $this->selects()); // root and relation each fill once
        DB::table('posts')->where('id', 2)->update(['title' => 'New child']);
        $this->assertSame('New child', $query->get()->first()->children->first()->title);
        $this->assertCount(4, $this->queryKeys()); // two independent row payloads per generation
    }

    public function test_outbox_is_atomic_with_commit_and_rollback_and_can_be_replayed(): void
    {
        $this->migrateOutbox();
        $outbox = app(InvalidationOutbox::class);
        $this->assertSame('result', $outbox->transaction(null, function ($connection) {
            $connection->table('posts')->where('id', 1)->update(['title' => 'Durable']);
            return 'result';
        }, ['posts']));
        $this->assertSame(1, DB::table('mrcache_outbox')->count());
        try {
            $outbox->transaction(null, function ($connection) {
                $connection->table('posts')->where('id', 1)->update(['title' => 'Rolled back']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame('Durable', Post::find(1)->title);
        $this->assertSame(1, DB::table('mrcache_outbox')->count());
        $this->assertSame(['processed' => 1, 'failed' => 0], $outbox->drain());
        $this->assertSame(['processed' => 0, 'failed' => 0], $outbox->drain());
    }

    public function test_outbox_failure_retains_record_and_recovers_with_new_client(): void
    {
        $this->migrateOutbox();
        app(InvalidationOutbox::class)->transaction(null, fn () => null);
        $config = config('mrcache');
        $config['strict_mode'] = false;
        $config['redis']['port'] = 0;
        $bad = new RedisNativeClient($config);
        $invalidator = new CacheInvalidator($bad, $config, app(\MrCache\Services\CacheObserver::class));
        $failed = new InvalidationOutbox(app('db'), $invalidator, app(\MrCache\Services\CacheObserver::class), $config);
        $this->assertSame(['processed' => 0, 'failed' => 1], $failed->drain());
        $this->assertSame(1, DB::table('mrcache_outbox')->value('attempts'));
        $this->assertSame(['processed' => 1, 'failed' => 0], app(InvalidationOutbox::class)->drain());
    }

    public function test_outbox_rejects_nontransactional_recording(): void
    {
        $this->expectException(\LogicException::class);
        app(InvalidationOutbox::class)->record();
    }

    public function test_doctor_install_and_outbox_commands(): void
    {
        $this->artisan('mrcache:doctor')->assertSuccessful();
        $this->artisan('mrcache:install', ['--outbox' => true])->assertSuccessful();
        $this->assertFileExists(database_path('migrations/2026_10_25_000001_create_mrcache_outbox_table.php'));
        $this->migrateOutbox();
        app(InvalidationOutbox::class)->transaction(null, fn () => null);
        $this->artisan('mrcache:outbox', ['--batch' => '10'])->assertSuccessful();
        $this->assertSame(0, DB::table('mrcache_outbox')->count());
    }

    public function test_tls_cannot_silently_disable_certificate_validation(): void
    {
        $config = config('mrcache');
        $config['redis']['scheme'] = 'tls';
        $config['redis']['tls'] = ['verify_peer' => false];
        $this->expectException(\InvalidArgumentException::class);
        new RedisNativeClient($config);
    }
    private function tlsConfiguration(): array
    {
        if (!getenv('MRCACHE_TEST_TLS_PORT')) {
            $this->markTestSkipped('TLS/ACL integration needs a TLS-enabled disposable Redis and certificate fixture.');
        }
        $config = config('mrcache');
        $config['redis']['scheme'] = 'tls';
        $config['redis']['host'] = 'localhost';
        $config['redis']['port'] = (int) getenv('MRCACHE_TEST_TLS_PORT');
        $config['redis']['tls'] = ['cafile' => getenv('MRCACHE_TEST_TLS_CA')];
        $config['redis']['username'] = 'mrcache';
        $config['redis']['password'] = trim(file_get_contents(getenv('MRCACHE_TEST_TLS_PASSWORD_FILE')));
        return $config;
    }

    public function test_verified_tls_and_named_acl_authentication(): void
    {
        $client = new RedisNativeClient($this->tlsConfiguration());
        $key = config('mrcache.prefix').':tls';
        try {
            $this->assertTrue($client->set($key, 'encrypted', 5));
            $this->assertSame('encrypted', $client->get($key));
            $this->assertSame('encrypted', $client->eval("return redis.call('GET', KEYS[1])", [$key]));
        } finally {
            $client->del($key);
        }
    }

    public function test_tls_rejects_a_hostname_mismatch(): void
    {
        $config = $this->tlsConfiguration();
        $config['redis']['tls']['peer_name'] = 'wrong-host.invalid';
        $this->expectException(\MrCache\Exceptions\RedisConnectionException::class);
        new RedisNativeClient($config);
    }

    public function test_malformed_outbox_rows_remain_visible_without_blocking_valid_batch_rows(): void
    {
        $this->migrateOutbox();
        DB::table('mrcache_outbox')->insert(['dependencies' => 'broken', 'attempts' => 0, 'created_at' => date('Y-m-d H:i:s')]);
        app(InvalidationOutbox::class)->transaction(null, fn () => null);
        $this->assertSame(['processed' => 1, 'failed' => 1], app(InvalidationOutbox::class)->drain());
        $this->assertSame(1, DB::table('mrcache_outbox')->count());
        $this->assertSame(1, DB::table('mrcache_outbox')->value('attempts'));
    }

    public function test_concurrent_cold_readers_share_one_database_fill(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair')) {
            $this->markTestSkipped('Real concurrent lock coverage requires pcntl and Unix socket pairs.');
        }
        $this->configure(['mrcache.stampede.wait_ms' => 2000]);
        $counter = config('mrcache.prefix').':selects';
        $workers = [];
        for ($i = 0; $i < 4; $i++) {
            $file = tempnam(sys_get_temp_dir(), 'mrcache_lock_test_');
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not fork lock test worker.');
            }
            if ($pid === 0) {
                fclose($sockets[0]);
                $client = new RedisNativeClient(config('mrcache'));
                $this->app->instance(CacheClientInterface::class, $client);
                $this->app->forgetInstance(CacheManager::class);
                DB::listen(function ($event) use ($client, $counter) {
                    if (str_starts_with(strtolower($event->sql), 'select')) {
                        $client->incr($counter);
                        usleep(100000);
                    }
                });
                fread($sockets[1], 1);
                try {
                    file_put_contents($file, Post::find(1)->title);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            fclose($sockets[1]);
            $workers[] = [$pid, $file, $sockets[0]];
        }
        foreach ($workers as [$pid, $file, $socket]) {
            fwrite($socket, '1');
            fclose($socket);
        }
        foreach ($workers as [$pid, $file]) {
            pcntl_waitpid($pid, $status);
            $content = file_get_contents($file);
            unlink($file);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            $this->assertSame('First', $content);
        }
        $this->assertSame('1', $this->client->get($counter));
    }

    public function test_outbox_records_application_write_during_redis_outage_for_later_repair(): void
    {
        $this->migrateOutbox();
        $config = config('mrcache');
        $config['redis']['port'] = 0;
        $config['strict_mode'] = false;
        $this->app->instance(CacheClientInterface::class, new RedisNativeClient($config));
        $this->app->forgetInstance(CacheInvalidator::class);
        app(InvalidationOutbox::class)->transaction(null, function ($connection) {
            $connection->table('posts')->where('id', 1)->update(['title' => 'Committed during outage']);
        });
        $this->assertSame('Committed during outage', Post::withoutCaching()->find(1)->title);
        $this->assertSame(1, DB::table('mrcache_outbox')->count());
        $this->app->instance(CacheClientInterface::class, $this->client);
        $this->app->forgetInstance(CacheInvalidator::class);
        $this->assertSame(['processed' => 1, 'failed' => 0], app(InvalidationOutbox::class)->drain());
    }

    public function test_relation_context_is_inherited_and_explicit_bypass_applies_to_children(): void
    {
        DB::table('posts')->where('id', 2)->update(['parent_id' => 1]);
        $a = Post::where('id', 1)->with('children')->cacheRelations(['children'])->cacheContext('tenant-a');
        $b = Post::where('id', 1)->with('children')->cacheRelations(['children'])->cacheContext('tenant-b');
        $a->get();
        $a->get();
        $b->get();
        $this->assertSame(4, $this->selects());
        $this->assertCount(4, $this->queryKeys());
        $before = $this->selects();
        $a->withoutCaching()->get();
        $this->assertSame($before + 2, $this->selects());
    }

    public function test_scalar_in_queries_cache_but_expression_and_subquery_forms_bypass(): void
    {
        Post::whereIn('id', [1, 2])->get();
        Post::whereIn('id', [1, 2])->get();
        $this->assertSame(1, $this->selects());
        $this->assertSame('non_scalar_binding', Post::whereIn('id', function ($q) { $q->select('id')->from('posts'); })->explainCache()['reason']);
        $this->assertSame('non_scalar_binding', Post::whereIn('id', [DB::raw('random()')])->explainCache()['reason']);
    }

    public function test_table_dependencies_follow_database_table_prefixes(): void
    {
        config(['database.connections.prefixed' => array_merge(config('database.connections.testdb'), ['prefix' => 'tenant_'])]);
        Schema::connection('prefixed')->create('posts', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->softDeletes();
        });
        $this->configure(['mrcache.invalidation' => ['mode' => 'tables', 'writes' => ['tenant_posts' => ['tenant_posts']]]]);
        $database = DB::connection('prefixed');
        $database->table('posts')->insert(['title' => 'Before']);
        $this->assertSame('Before', Post::on('prefixed')->first()->title);
        $this->assertSame('Before', Post::on('prefixed')->first()->title);
        $database->table('posts')->where('id', 1)->update(['title' => 'After']);
        $this->assertSame('After', Post::on('prefixed')->first()->title);
    }

    public function test_real_socket_read_timeout_degrades_without_swallowing_programming_errors(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('Network timeout fixture requires pcntl.');
        }
        $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not fork socket timeout fixture.');
        }
        if ($pid === 0) {
            $socket = stream_socket_accept($server, 3);
            if (!$socket) {
                exit(1);
            }
            stream_set_timeout($socket, 3);
            while ($line = fgets($socket)) {
                $parts = (int) substr($line, 1);
                $arguments = [];
                for ($j = 0; $j < $parts; $j++) {
                    $length = (int) substr(fgets($socket), 1);
                    $arguments[] = substr(fread($socket, $length + 2), 0, $length);
                }
                if (strtoupper($arguments[0]) === 'GET') {
                    usleep(300000); // Hold a real TCP connection past read_timeout.
                    break;
                }
                fwrite($socket, "+OK\r\n");
            }
            fclose($socket);
            fclose($server);
            exit(0);
        }
        fclose($server);
        $config = config('mrcache');
        $config['redis']['port'] = (int) substr(strrchr($address, ':'), 1);
        $config['redis']['read_timeout'] = 0.05;
        $config['strict_mode'] = false;
        try {
            $client = new RedisNativeClient($config);
            $this->assertNull($client->get('timeout-fixture'));
            $this->assertNull($client->get('second-call'));
        } finally {
            pcntl_waitpid($pid, $status);
        }
        $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
    }

}
