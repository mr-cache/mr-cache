# MrCache

Redis query caching for Laravel and Eloquent, with explicit lifetimes, cache policies and invalidation controls.

[العربية](README_AR.md) · [Packagist](https://packagist.org/packages/mrcache/mrcache) · [Upgrade notes](CHANGELOG.md)

## Compatibility

| Laravel / Illuminate | Minimum PHP |
| --- | --- |
| 10 | 8.1 |
| 11–12 | 8.2 |
| 13 | 8.3 |

MrCache depends on Predis `^2.0`; phpredis is optional. Framework compatibility does not extend Laravel's upstream security support. Review dependency advisories when using older framework versions.

## Installation

This README documents the `5.x` development branch. Install that branch with:

```sh
composer require mrcache/mrcache:5.x-dev
php artisan vendor:publish --provider="MrCache\Providers\MrCacheServiceProvider" --tag="mrcache-config"
```

For a tagged release, use `composer require mrcache/mrcache` and follow the README for that release.

Configure Redis and a unique application/environment namespace before enabling caching:

```dotenv
MRCACHE_ENABLED=true
MRCACHE_PREFIX=myapp_production
MRCACHE_REDIS_CLIENT=predis
MRCACHE_REDIS_HOST=127.0.0.1
MRCACHE_REDIS_PORT=6379
MRCACHE_TTL=60
```

Caching is disabled by default. Run `php artisan mrcache:doctor` to probe Redis connectivity and required commands.

## Quick start

```php
use Illuminate\Database\Eloquent\Model;
use MrCache\Traits\CacheableModel;

class Post extends Model
{
    use CacheableModel;

    protected $cacheTTL = 60;
}

$posts = Post::where('is_published', true)->withCustomTTL(30)->get();
$fresh = Post::withoutCaching()->find(1);
```

TTL precedence, from lowest to highest: global default, model TTL, central model policy, query policy, explicit `withCustomTTL()`. Zero bypasses caching; negative and overflowing values are rejected.

## Policies and context

```php
$posts = Post::query()->where('tenant_id', $tenantId)
    ->cacheContext(['tenant' => $tenantId, 'permission_version' => $permissionVersion])
    ->withCustomTTL(60)->refreshAhead(5)->get();

$fresh = Post::query()->cacheConsistency('database')->find($id);
$decision = Post::query()->explainCache();
```

`cacheContext()` separates cache identities; it does not filter rows or enforce authorization. Include tenant and authorization predicates in SQL. `database` consistency bypasses cached reads; `bounded` uses TTL and observed invalidation. `cache()` alone does not enable caching.

Central policies are configured in `mrcache.policies[Model::class]`. Query policies accept `ttl`, `consistency`, `max_payload_bytes`, `refresh_ahead_seconds` and `relations`. Refresh ahead is synchronous, requires a threshold below TTL, and does not serve expired data.

## Queries and relationships

Eligible model reads through `get()`, `first()` and `find()` cache raw attributes and hydrate through Eloquent. Casts, dates, hidden fields and model collection behavior are preserved. Empty results are not stored. The default payload limit is 1 MiB.

Scalar IN queries are supported. Transactions, locks, joins, subqueries, raw expressions, functions, read replicas and unsupported query forms bypass caching. Direct aggregate and cursor calls remain database paths.

```php
$posts = Post::with('children')->cacheRelations(['children'])->get();
```

Relationships require an explicit top-level allowlist. Eligible related models using `CacheableModel` cache their rows separately, inherit the parent's context, and use a TTL capped by the parent's. Nested eager relations and pivot/join reads bypass caching.

## Invalidation and consistency

By default, observed non-SELECT Laravel SQL events and outer transaction commits invalidate the namespace. Captured generations prevent a slow reader from repopulating the current generation after invalidation. Row/model flush commands also invalidate the namespace. Old entries expire by TTL; a flush is not physical deletion.

```php
use MrCache\Services\CacheInvalidator;

$ok = app(CacheInvalidator::class)->invalidateAll();
$ok = app(CacheInvalidator::class)->invalidateTables(['posts']);
```

Check the boolean result for non-strict Redis failures. Optional table mode requires a complete `mrcache.invalidation.writes` map of physical table dependencies, including triggers and cascades. Unknown writes and outer commits fall back to global invalidation. `cacheDependsOn()` declares additional physical table dependencies. Use a new prefix when changing invalidation modes.

Database writes and Redis invalidation are separate operations. External/PDO writers, suppressed events, side-effecting SELECT functions, process crashes and failed invalidation can leave stale entries until expiry or a successful flush. Use direct database reads when that window is unacceptable.

### Durable invalidation

```sh
php artisan mrcache:install --outbox
# Review and run the published migration on each participating database.
php artisan migrate
```

```php
use MrCache\Services\InvalidationOutbox;

app(InvalidationOutbox::class)->transaction(null, function ($connection) use ($id) {
    $connection->table('posts')->where('id', $id)->update(['is_published' => true]);
}, ['posts']);
```

Schedule `php artisan mrcache:outbox --batch=100` to replay pending invalidations. Outbox participation is explicit and records invalidation in the same database transaction. Failed records remain retryable. This provides eventual invalidation, not atomic database/Redis reads. Migrations and workers are never started automatically.

## Redis and operations

Cold fills use token-owned locks with bounded leases and waits. A waiter that times out queries the database without publishing; expired owners cannot overwrite successors. Database capacity and application rate limits remain necessary during outages.

Non-strict Redis failures fall back to the database and disable that client until recreation; restart long-running workers after recovery. Strict mode throws, including when a database write has already succeeded. Programming/database errors propagate separately.

Set `redis.scheme=tls` for TLS and `redis.username` for named ACL authentication. Optional TLS options are `cafile`, `capath`, `peer_name`, `local_cert` and `local_pk`; certificate and hostname verification cannot be disabled. Cluster and Sentinel are not supported.

Payloads contain selected raw attributes, including hidden fields, and are unsigned. Protect Redis access and retention. Malformed, legacy, oversized and non-JSON payloads are treated as misses or skipped storage.

```sh
php artisan mrcache:doctor
php artisan mrcache:flush
php artisan mrcache:stats  # requires store_metrics=true
```

`CacheEvent` provides sanitized outcomes, reasons and read timings without SQL, bindings, returned rows or raw context. Applications can connect these events to their monitoring tools. Consumer tests can use `MrCache\Testing\InteractsWithMrCache`.

## Development and benchmarks

```sh
composer validate --strict
composer install
composer lint
MRCACHE_TEST_REDIS_PORT=6379 composer test
MRCACHE_TEST_REDIS_PORT=6379 MRCACHE_TEST_REDIS_CLIENT=phpredis composer test
composer audit
MRCACHE_TEST_REDIS_PORT=6379 vendor/bin/phpunit benchmarks/PerformanceTest.php
```

Tests use SQLite and isolated Redis prefixes. Redis tests skip explicitly without the port variable. TLS tests additionally require `MRCACHE_TEST_TLS_PORT`, `MRCACHE_TEST_TLS_CA` and `MRCACHE_TEST_TLS_PASSWORD_FILE`; generate disposable fixtures with `tests/Support/tls-fixture.sh`. Concurrency tests require pcntl. The CI matrix defines matching PHP/Laravel environments for versions 10–13 and both Redis clients.

Benchmark output includes cold/warm reads, writes and concurrent hot-key scenarios. Measure against your application's real database and workload; caching is not guaranteed to outperform a fast database query.

MIT license: [LICENSE](LICENSE).
