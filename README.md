# MrCache for PHP frameworks 

An advanced, native Redis caching layer for PHP frameworks Eloquent queries, bypassing the standard Laravel Cache facade for maximum performance and control.

![PHP Tests](https://github.com/mr-cache/mr-cache/actions/workflows/php.yml/badge.svg)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/mrcache/mrcache.svg?style=flat-square)](https://packagist.org/packages/mrcache/mrcache)
[![Total Downloads](https://img.shields.io/packagist/dt/mrcache/mrcache.svg?style=flat-square)](https://packagist.org/packages/mrcache/mrcache)

---
[عرض التوثيق العربي](README_AR.md)
### 1. Introduction
"The difference between an ultra-fast MySQL database and one with mediocre performance often lies in the efficiency of its query cache management. Haphazard management can turn this feature into an obstacle.
​MrCache offers the optimal solution to this equation: an intelligent and automated management system that restores the power of your cache and ensures you get the most out of every query. Benefit from the power of smart automation, while retaining the ability to customize everything to fit your vision."

Most libraries that offer a caching layer for database queries—especially in the **PHP** environment, including for the **Laravel** framework—treat the cache like a naive notebook: they save a query to memory the first time it's called, then wipe everything clean at the first modification to the table! Update a row? Delete a record? Add new data? No problem, let's just drop the entire cache!
But what's the point then? A database isn't just for reading; it's also for writing and modifying. The result is that the cache becomes a temporary visitor that doesn't live for more than a few moments, offering no real benefit. 
Instead, it adds overhead to the system with repeated write and delete operations. 
Worse yet, some of these libraries don't even give you the simplest forms of control—like setting a key's Time-To-Live (TTL). Why? **Because it makes no difference as long as the cache is constantly being cleared.**

This is where **MrCache** emerges as a mature exception in this landscape. 
It doesn't handle caching randomly but with intelligent management that understands the difference between data that has actually changed and data that hasn't been touched.
When reading, **MrCache** first checks if the data exists in the cache:
- If it exists, it's returned immediately without any additional query to the database.
- If it doesn't exist, it acts intelligently:
- If the database returns no results, it stores nothing in the cache. An empty cache is a useless burden.
- If results are found, it checks if the query relies on the Primary Key to determine whether the result pertains to a single row or multiple rows.
- If the result is for a single row, it's stored in the unique rows section.
- If it's a general query, it's stored in the general queries section.
When write operations occur, the real magic begins:
- If a specific row is modified via its Primary Key, only the cache for that specific row is invalidated—nothing more.
- If the modification affects more than one row, only the cache related to those rows is invalidated without touching others.
- If a new row is added, **MrCache** only invalidates general queries to update their results on subsequent calls.
With this approach, **MrCache** maintains a delicate balance: the cached data remains constantly synchronized with the database without excessive invalidation or wasted memory. 
It's not just a caching intermediary but an intelligent cache management system designed specifically for MySQL queries—where performance is not sacrificed for accuracy, nor is accuracy lost in the name of speed.

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
       protected $cacheTTL = 3600; // 1 hour
       
       // Optional: Define indexes
       protected $indexes = [
           ['col1'], // Single index
           ['col1', 'col2'], // Multiple index
       ];
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
