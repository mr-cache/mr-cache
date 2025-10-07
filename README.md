# MrCache for PHP frameworks 

An advanced, native Redis caching layer for PHP frameworks Eloquent queries, bypassing the standard Laravel Cache facade for maximum performance and control.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrcache/mrcache.svg?style=flat-square)](https://packagist.org/packages/mrcache/mrcache)
[![Total Downloads](https://img.shields.io/packagist/dt/mrcache/mrcache.svg?style=flat-square)](https://packagist.org/packages/mrcache/mrcache)

---

### 1. Goal

MrCache provides an automatic, highly configurable caching layer for your Eloquent models. It's designed to be "plug-and-play" with intelligent, granular cache invalidation. All queries are cached by default, giving you immediate performance gains.

### 2. Installation

```bash
composer require mrcache/mrcache
```

Next, publish the configuration file:

```bash
php artisan vendor:publish --provider="MrCache\Providers\MrCacheServiceProvider" --tag="mrcache-config"
```

This will create a `config/mrcache.php` file where you can configure Redis connection, default TTL, and other settings.

### 3. Quick Start

1. **Use the Trait:** Add the `CacheableModel` trait to any Eloquent model you want to cache.

   ```php
   use Illuminate\Database\Eloquent\Model;
   use MrCache\Traits\CacheableModel;

   class Post extends Model
   {
       use CacheableModel;

       // Optional: Define a model-specific TTL in seconds
       public function getCacheTTL(): int
       {
           return 3600; // 1 hour
       }
   }
   ```

2. **That's it!** All queries for the `Post` model are now automatically cached.

   ```php
   // First run: Hits the database and caches the result.
   $posts = Post::where('is_published', true)->get();

   // Second run (identical query): Fetches the result directly from Redis.
   $posts = Post::where('is_published', true)->get();
   ```

### 4. Advanced Usage

#### Per-Query Control

You can control caching behavior on a per-query basis using fluent macros.

* **Disable Caching for a Query:**

  ```php
  $uncachedPosts = Post::where('live_views', '>', 1000)->withoutCaching()->get();
  ```

* **Set a Custom TTL for a Query:**

  ```php
  // Cache this specific result for only 60 seconds
  $breakingNews = Post::latest()->withCustomTTL(60)->first();
  ```

### 4.2 Relationships and Cascading Invalidation

MrCache automatically invalidates cache for related models:

```php
$author = Author::with('posts')->find(1);
$author->delete(); // invalidates the author AND all related posts
```

Works for:

* `hasOne` / `belongsTo`
* `hasMany` / `belongsToMany`
* Nested relationships

No manual listing of relationships required; MrCache inspects the Eloquent model.

---

### 4.3 Conditional Table/Row Invalidation

* Updates to a single row only invalidate queries that include that row.
* Mass updates or deletes with where conditions now automatically invalidate only the affected rows.

```php
// Automatically invalidates cache for all rows where 'is_archived' = true
Post::where('is_archived', true)->update(['is_published' => false]);

// Similarly for mass deletes
Post::where('is_archived', true)->delete();
```
* ✅ No need to manually flush the cache for affected rows; MrCache handles it automatically.


### 5. Invalidation Rules

Invalidation is automatic and granular.

* **Single Row Invalidation:** When you update or delete a model instance, only the cache entries that include that specific row are invalidated.

  ```php
  $post = Post::find(1); // Automatically stores cache for Post #1
  $post->title = 'New Title';
  $post->save(); // Automatically invalidates cache for Post #1
  ```

* **Mass Row Invalidation:** Mass updates or deletes via the query builder now automatically invalidate the cache for only the affected rows.

  ```php
  // Automatically invalidates cache for all rows where 'is_archived' = true
  Post::where('is_archived', true)->update(['is_published' => false]);
  Post::where('is_archived', true)->delete();
  ```

* ✅ MrCache now ensures cache consistency for all affected rows, without manual flushes.

---

## 6. Artisan Commands

| Command                                                    | Description                                       |
| ---------------------------------------------------------- | ------------------------------------------------- |
| `php artisan mrcache:flush`                                    | Flush all cache                                   |
| `php artisan mrcache:flush --model="App\Models\Post"`          | Flush cache for a specific model/table            |
| `php artisan mrcache:flush --model="App\Models\Post" --pk=123` | Flush a specific row                              |
| `php artisan mrcache:stats`                                    | View cache hits/misses (requires metrics enabled) |

---

### 7. Configuration (`config/mrcache.php`)

```php
<?php

declare(strict_types=1);

return [
    'enabled' => env('MRCACHE_ENABLED', true),

    'redis' => [
        'client'   => env('MRCACHE_REDIS_CLIENT', 'phpredis'),
        'host'     => env('MRCACHE_REDIS_HOST', '127.0.0.1'),
        'port'     => env('MRCACHE_REDIS_PORT', 6379),
        'password' => env('MRCACHE_REDIS_PASSWORD'),
        'database' => env('MRCACHE_REDIS_DB', 0),
        'timeout'  => env('MRCACHE_REDIS_TIMEOUT', 1.0),
    ],

    'prefix' => env('MRCACHE_PREFIX', 'mrcache'),

    'default_ttl' => env('MRCACHE_TTL', 3600),

    'strict_mode' => env('MRCACHE_STRICT_MODE', false),

    'hash_algo' => 'md5',

    'compress_threshold' => env('MRCACHE_COMPRESS_THRESHOLD', 10240),

    'relations_default_depth' => 2,

    'store_metrics' => env('MRCACHE_STORE_METRICS', true),
];
```

---

---

## 8. Notes & Recommendations

* Queries like `count()`, `sum()`, `avg()` are **not cached by default**, because their invalidation is row-sensitive.
* Empty query results are **never cached** to avoid unnecessary Redis usage.
* Use compressed payloads (`gzencode`) for large datasets to save memory.
* TTL priority:

  1. Query-level TTL (`withCustomTTL()`)
  2. Model-level TTL (`$cacheTTL`)
  3. Global default TTL (`config('mrcache.default_ttl')`)

---
