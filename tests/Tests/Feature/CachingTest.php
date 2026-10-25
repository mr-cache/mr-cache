<?php

declare(strict_types=1);

namespace MrCache\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\InvalidationInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use MrCache\Services\CacheManager;
use MrCache\Services\Generation;
use MrCache\Services\RedisNativeClient;
use MrCache\Tests\Models\Post;
use MrCache\Tests\TestCase;

/** Real Redis, never a mock. Each test owns a random prefix and an in-memory DB. */
class CachingTest extends TestCase
{
    use \MrCache\Tests\Concerns\UsesRedisDatabase;

    public function test_warm_hit_preserves_hidden_attributes_casts_dates_and_connection(): void
    {
        $cold = Post::orderBy('id')->get();
        $warm = Post::orderBy('id')->get();
        $this->assertSame(1, $this->selects());
        $this->assertSame($cold::class, $warm::class);
        $this->assertSame($cold->first()->getAttributes(), $warm->first()->getAttributes());
        $this->assertSame('private', $warm->first()->secret);
        $this->assertArrayNotHasKey('secret', $warm->first()->toArray());
        $this->assertTrue($warm->first()->active);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $warm->first()->created_at);
        $this->assertSame('testdb', $warm->first()->getConnectionName());
        $this->assertFalse($warm->first()->isDirty());
    }

    public function test_columns_bindings_order_limit_offset_and_types_have_distinct_identity(): void
    {
        $this->assertSame(['title' => 'First'], Post::where('id', 1)->first(['title'])->getAttributes());
        $this->assertSame(1, Post::where('id', 1)->first(['id'])->id);
        $this->assertSame('Second', Post::where('id', 2)->first()->title);
        $this->assertSame('Second', Post::orderByDesc('id')->limit(1)->get()->first()->title);
        $this->assertSame('Second', Post::orderBy('id')->offset(1)->limit(1)->get()->first()->title);
        $generator = app(KeyGeneratorInterface::class);
        $this->assertNotSame($generator->generateQueryKey(Post::where('id', 1)), $generator->generateQueryKey(Post::where('id', '1')));
        $this->assertNotSame($generator->generateQueryKey(Post::where('id', 1)), $generator->generateQueryKey(Post::where('id', true)));
    }

    public function test_connections_and_namespaces_are_isolated(): void
    {
        Post::all();
        config(['database.connections.other' => config('database.connections.testdb')]);
        $this->assertNotSame(app(KeyGeneratorInterface::class)->generateQueryKey(Post::query()), app(KeyGeneratorInterface::class)->generateQueryKey(Post::on('other')));
        $this->assertNotSame((new \MrCache\Services\KeyGenerator(['prefix' => 'a']))->generateQueryKey(Post::query()), (new \MrCache\Services\KeyGenerator(['prefix' => 'b']))->generateQueryKey(Post::query()));
    }

    public function test_mutations_invalidate_query_membership_and_ordered_limits(): void
    {
        $this->assertCount(1, Post::where('active', true)->get());
        Post::where('id', 2)->update(['active' => true]);
        $this->assertCount(2, Post::where('active', true)->get());
        $this->assertSame('Second', Post::latest('id')->first()->title);
        Post::create(['title' => 'Third', 'active' => true]);
        $this->assertSame('Third', Post::latest('id')->first()->title);
        Post::where('id', 1)->increment('counter');
        $this->assertSame(1, Post::find(1)->counter);
        Post::where('id', 1)->decrement('counter');
        $this->assertSame(0, Post::find(1)->counter);
        Post::where('id', 2)->delete();
        $this->assertCount(2, Post::all());
        Post::withTrashed()->find(2)->restore();
        $this->assertCount(3, Post::all());
        Post::find(2)->forceDelete();
        $this->assertCount(2, Post::all());
    }

    public function test_model_save_builder_raw_upsert_insert_and_delete_are_observed(): void
    {
        $post = Post::find(1);
        $post->title = 'Saved';
        $post->save();
        $this->assertSame('Saved', Post::find(1)->title);
        DB::table('posts')->where('id', 1)->update(['title' => 'Builder']);
        $this->assertSame('Builder', Post::find(1)->title);
        DB::update('update posts set title = ? where id = ?', ['Raw', 1]);
        $this->assertSame('Raw', Post::find(1)->title);
        Post::upsert([['id' => 1, 'title' => 'Upsert']], ['id'], ['title']);
        $this->assertSame('Upsert', Post::find(1)->title);
        $this->assertCount(2, Post::all());
        DB::table('posts')->insert(['title' => 'Inserted']);
        $this->assertCount(3, Post::all());
        DB::table('posts')->where('id', 1)->delete();
        $this->assertNull(Post::find(1));
    }

    public function test_transactions_bypass_cache_and_commit_and_rollback_remain_correct(): void
    {
        Post::all();
        DB::beginTransaction();
        $this->assertCount(2, Post::all());
        Post::create(['title' => 'Uncommitted']);
        $this->assertCount(3, Post::all());
        DB::rollBack();
        $this->assertCount(2, Post::all());
        DB::beginTransaction();
        DB::beginTransaction();
        Post::create(['title' => 'Committed']);
        DB::commit();
        $this->assertCount(3, Post::all());
        DB::commit();
        $this->assertCount(3, Post::all());
        $this->assertCount(3, Post::all());
    }

    public function test_slow_fill_after_write_cannot_resurrect_old_generation(): void
    {
        $builder = Post::where('id', 1);
        $oldResults = $builder->withoutCaching()->get();
        $builder->mrcache_without_caching = false;
        app(CacheManager::class)->rememberQuery($builder, function () use ($oldResults) {
            DB::table('posts')->where('id', 1)->update(['title' => 'New']);
            return $oldResults;
        });
        $this->assertSame('New', Post::find(1)->title);
    }

    public function test_evicted_generation_does_not_reuse_previous_entries_and_flush_is_logical(): void
    {
        Post::all();
        $generation = new Generation($this->client, config('mrcache'));
        $before = $generation->current();
        $this->client->del(config('mrcache.prefix').':v2:generation');
        $this->assertNotSame($before, $generation->current());
        $before = $generation->current();
        app(InvalidationInterface::class)->invalidateRow('posts', 0);
        $this->assertNotSame($before, $generation->current());
        Post::all();
        $this->assertSame(2, $this->selects());
    }

    public function test_corrupt_legacy_and_oversized_payloads_are_safe_misses(): void
    {
        Post::all();
        $keys = iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*'));
        $this->assertCount(1, $keys);
        foreach (['{broken', '{"version":2,"data":[null]}', '{"version":2,"data":[{"id":{}}]}', '{"data":[]}', 'C::bad', str_repeat('x', 1048577)] as $raw) {
            $this->client->set($keys[0], $raw, 30);
            $this->assertCount(2, Post::all());
        }
        $this->assertSame(7, $this->selects());
    }

    public function test_empty_results_and_scalars_are_not_cached_or_conflated(): void
    {
        $this->assertNull(Post::find(999));
        $this->assertTrue(Post::where('id', 999)->get()->isEmpty());
        $this->assertSame(2, Post::count());
        $this->assertSame(0, (int) Post::sum('counter'));
        $this->assertFalse(Post::where('id', 999)->exists());
        $this->assertSame([], iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*')));
    }

    public function test_unsupported_queries_and_streams_bypass_cache(): void
    {
        foreach ([Post::whereRaw('id > 0'), Post::selectRaw('random() as number'), Post::query()->lockForUpdate(), Post::with('children'), Post::query()->withoutCaching(), Post::query()->withCustomTTL(0), Post::query()->useWritePdo(), Post::orderByRaw('id desc'), Post::where('id', DB::raw('1'))] as $builder) {
            $builder->get();
            $builder->get();
        }
        Post::cursor()->all();
        $this->assertSame([], iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*')));
    }

    public function test_ttl_precedence_and_bounded_expiry(): void
    {
        config(['mrcache.default_ttl' => 90]);
        Post::query()->withCustomTTL(7)->get();
        $keys = iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*'));
        $this->assertCount(1, $keys);
        $this->assertGreaterThanOrEqual(6, $this->client->getTtl($keys[0]));
        $this->assertLessThanOrEqual(7, $this->client->getTtl($keys[0]));
        $this->expectException(\InvalidArgumentException::class);
        Post::withCustomTTL(-1)->get();
    }

    public function test_callbacks_run_once_on_cold_and_warm_queries(): void
    {
        if (!method_exists(\Illuminate\Database\Eloquent\Builder::class, 'afterQuery')) {
            $this->markTestSkipped('Laravel 10 does not expose the Eloquent after-query callback API.');
        }
        $calls = 0;
        $builder = Post::query()->afterQuery(function ($results) use (&$calls) { $calls++; return $results; });
        $builder->get();
        $builder->get();
        $this->assertSame(2, $calls);
        $this->assertSame(1, $this->selects());
    }

    public function test_redis_adapter_eval_scan_sets_pipeline_ttl_and_delete(): void
    {
        $key = config('mrcache.prefix').':adapter';
        $this->assertTrue($this->client->set($key, 'value', 30));
        $this->assertSame('value', $this->client->eval("return redis.call('GET', KEYS[1])", [$key]));
        $this->assertSame(1, $this->client->sAdd($key.':set', 'a', 'b') > 0 ? 1 : 0);
        $this->assertCount(2, $this->client->sMembers($key.':set'));
        $this->assertSame(1, $this->client->sRem($key.':set', 'a'));
        $this->assertCount(2, $this->client->pipeline(fn ($pipe) => $pipe->set($key.':pipe', '1')->expire($key.':pipe', 30)));
        $this->assertGreaterThan(0, $this->client->getTtl($key));
        $this->assertContains($key, iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':*')));
        $this->assertSame(1, $this->client->del($key));
    }

    public function test_non_strict_connection_failure_falls_back_and_strict_failure_throws(): void
    {
        $config = config('mrcache');
        $config['redis']['port'] = 0;
        $config['strict_mode'] = false;
        $client = new RedisNativeClient($config);
        $this->app->instance(CacheClientInterface::class, $client);
        $this->app->forgetInstance(CacheManager::class);
        $this->assertCount(2, Post::all());
        $this->assertNull($client->get('any'));
        $config['strict_mode'] = true;
        $this->expectException(\MrCache\Exceptions\RedisConnectionException::class);
        new RedisNativeClient($config);
    }

    public function test_configuration_publishing_and_disabled_path(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'mrcache-config', '--force' => true])->assertSuccessful();
        $this->assertFileExists(config_path('mrcache.php'));
        config(['mrcache.enabled' => false]);
        Post::all();
        Post::all();
        $this->assertSame(2, $this->selects());
    }
    public function test_runtime_command_failure_is_bounded_logged_once_and_degrades_to_database(): void
    {
        $config = config('mrcache');
        $config['strict_mode'] = false;
        $client = new RedisNativeClient($config);
        $key = config('mrcache.prefix').':wrongtype';
        $client->sAdd($key, 'private-binding-value');
        \Illuminate\Support\Facades\Log::spy();
        $this->assertNull($client->get($key));
        $this->assertNull($client->get($key));
        $this->assertFalse($client->set($key, 'anything'));
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once()->with('MrCache: Redis command failed; caching disabled for this client.');
        $this->app->instance(CacheClientInterface::class, $client);
        $this->app->forgetInstance(CacheManager::class);
        $this->assertCount(2, Post::all());
    }

    public function test_strict_runtime_failure_remains_strict_on_subsequent_calls(): void
    {
        $client = new RedisNativeClient(config('mrcache'));
        $key = config('mrcache.prefix').':wrongtype';
        $client->sAdd($key, 'a');
        for ($i = 0; $i < 2; $i++) {
            try {
                $client->get($key);
                $this->fail('Strict failures must throw.');
            } catch (\MrCache\Exceptions\RedisConnectionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_programming_and_database_errors_are_not_swallowed(): void
    {
        try {
            $this->client->pipeline(function ($pipe) { throw new \LogicException('application failure'); });
            $this->fail('Programming error was swallowed.');
        } catch (\LogicException $error) {
            $this->assertSame('application failure', $error->getMessage());
        }
        $key = config('mrcache.prefix').':aftererror';
        $this->assertTrue($this->client->set($key, 'ok', 30));
        $this->assertSame('ok', $this->client->get($key));
        $this->expectException(\Illuminate\Database\QueryException::class);
        Post::query()->from('missing_table')->get();
    }

    public function test_base_query_hooks_and_replica_config_bypass_cache(): void
    {
        $query = Post::query();
        $query->getQuery()->beforeQuery(fn ($q) => $q->selectRaw('random() as number'));
        $query->get();
        $query = Post::query();
        $calls = 0;
        if (method_exists($query->getQuery(), 'afterQuery')) {
            $query->getQuery()->afterQuery(function ($rows) use (&$calls) { $calls++; return $rows; });
            $query->get();
            $query->get();
            $this->assertSame(2, $calls);
        }
        config(['database.connections.replica' => array_merge(config('database.connections.testdb'), ['read' => ['database' => ':memory:'], 'write' => ['database' => ':memory:']])]);
        DB::connection('replica')->getReadPdo()->exec('create table posts (id integer, title text, deleted_at text)');
        DB::connection('replica')->getReadPdo()->exec("insert into posts (id, title) values (1, 'Replica')");
        $this->assertSame('Replica', Post::on('replica')->get()->first()->title);
        $this->assertSame('Replica', Post::on('replica')->get()->first()->title);
        $this->assertSame([], iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*')));
    }

    public function test_default_model_ttl_scopes_custom_keys_collections_and_lazy_loading_policy(): void
    {
        $builder = (new class extends Post {
            protected $cacheTTL = 13;
            protected $primaryKey = 'title';
            protected $keyType = 'string';
            public $incrementing = false;
            public function newCollection(array $models = []) { return new class($models) extends \Illuminate\Database\Eloquent\Collection {}; }
        })->newQuery();
        $builder->withGlobalScope('active', fn ($q) => $q->where('active', true));
        $cold = $builder->get();
        $warm = $builder->get();
        $this->assertSame($cold::class, $warm::class);
        $this->assertCount(1, $warm);
        $this->assertSame('First', $warm->first()->getKey());
        $keys = iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*'));
        $this->assertGreaterThanOrEqual(12, $this->client->getTtl($keys[0]));
        \Illuminate\Database\Eloquent\Model::preventLazyLoading();
        try {
            Post::all();
            $this->assertTrue(Post::all()->first()->preventsLazyLoading);
        } finally {
            \Illuminate\Database\Eloquent\Model::preventLazyLoading(false);
        }
    }

    public function test_tenant_scopes_filter_on_warm_hits(): void
    {
        $firstTenant = Post::query()->withGlobalScope('tenant', fn ($q) => $q->where('id', 1));
        $secondTenant = Post::query()->withGlobalScope('tenant', fn ($q) => $q->where('id', 2));
        $firstTenant->get();
        $secondTenant->get();
        $this->assertSame('First', $firstTenant->get()->first()->title);
        $this->assertSame('Second', $secondTenant->get()->first()->title);
        $this->assertSame(2, $this->selects());
    }

    public function test_related_and_pivot_writes_invalidate_and_relations_stay_on_database_path(): void
    {
        Schema::create('post_links', function (Blueprint $table) {
            $table->integer('source_id');
            $table->integer('related_id');
        });
        $parent = Post::find(1);
        Post::where('id', 2)->update(['parent_id' => 1]);
        $this->assertSame('Second', $parent->children()->get()->first()->title);
        Post::where('id', 2)->update(['title' => 'Child changed']);
        $this->assertSame('Child changed', $parent->children()->get()->first()->title);
        $generation = new Generation($this->client, config('mrcache'));
        $before = $generation->current();
        $parent->peers()->attach(2);
        $this->assertNotSame($before, $generation->current());
        $this->assertSame('Child changed', $parent->peers()->get()->first()->title);
        $parent->peers()->detach(2);
        $this->assertTrue($parent->peers()->get()->isEmpty());
    }

    public function test_existing_application_macros_are_preserved(): void
    {
        $builder = \Illuminate\Database\Eloquent\Builder::class;
        $original = $builder::getGlobalMacro('cache');
        $builder::macro('cache', fn () => 'application macro');
        try {
            (new \MrCache\Providers\MrCacheServiceProvider($this->app))->boot();
            $this->assertSame('application macro', Post::query()->cache());
        } finally {
            $builder::macro('cache', $original);
        }
    }

    public function test_flush_command_validates_models_and_accepts_a_zero_primary_key(): void
    {
        $this->artisan('mrcache:flush', ['--pk' => '0'])->assertFailed();
        $this->artisan('mrcache:flush', ['--model' => \stdClass::class])->assertFailed();
        $generation = new Generation($this->client, config('mrcache'));
        $before = $generation->current();
        $this->artisan('mrcache:flush', ['--model' => Post::class, '--pk' => '0'])->assertSuccessful();
        $this->assertNotSame($before, $generation->current());
    }

    public function test_metrics_and_stats_report_eligible_hits_and_misses(): void
    {
        Post::all();
        Post::all();
        $keys = app(KeyGeneratorInterface::class);
        $this->assertSame('1', $this->client->get($keys->getMetricsKey('hits')));
        $this->assertSame('1', $this->client->get($keys->getMetricsKey('misses')));
        $this->artisan('mrcache:stats')->assertSuccessful();
        config(['mrcache.store_metrics' => false]);
        $this->artisan('mrcache:stats')->assertFailed();
    }

    public function test_global_ttl_validation_and_default_expiry(): void
    {
        config(['mrcache.default_ttl' => '19']);
        $this->app->forgetInstance(CacheManager::class);
        Post::all();
        $keys = iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*'));
        $this->assertGreaterThanOrEqual(18, $this->client->getTtl($keys[0]));
        $this->assertLessThanOrEqual(19, $this->client->getTtl($keys[0]));
        config(['mrcache.default_ttl' => 'invalid']);
        $this->app->forgetInstance(CacheManager::class);
        $this->expectException(\InvalidArgumentException::class);
        Post::all();
    }

    public function test_binary_and_large_results_are_returned_without_storage(): void
    {
        DB::table('posts')->where('id', 1)->update(['secret' => "\xff\xfe"]);
        $this->assertSame("\xff\xfe", Post::find(1)->secret);
        $this->assertSame([], iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*')));
        config(['mrcache.max_payload_bytes' => 32]);
        $this->app->forgetInstance(CacheManager::class);
        $this->assertSame('Second', Post::find(2)->title);
        $this->assertSame([], iterator_to_array($this->client->scanKeys(config('mrcache.prefix').':query:*')));
    }

}
