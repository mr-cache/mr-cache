<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enable MrCache
    |--------------------------------------------------------------------------
    |
    | This is the master switch to enable or disable the entire caching system.
    | When set to false, all caching operations will be bypassed, and queries
    | will hit the database directly without any performance overhead.
    |
    */
    'enabled' => env('MRCACHE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Details
    |--------------------------------------------------------------------------
    |
    | Configure the connection to your Redis server. This package connects
    | directly to Redis and does not use Laravel's built-in connection pools.
    | The 'client' can be 'phpredis' (for ext-redis) or 'predis'.
    |
    */
    'redis' => [
        'client'   => env('MRCACHE_REDIS_CLIENT', 'phpredis'),
        'host'     => env('MRCACHE_REDIS_HOST', '127.0.0.1'),
        'port'     => env('MRCACHE_REDIS_PORT', 6379),
        'password' => env('MRCACHE_REDIS_PASSWORD'),
        'database' => env('MRCACHE_REDIS_DB', 0),
        'timeout'  => env('MRCACHE_REDIS_TIMEOUT', 1.0), // Connection timeout in seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be prepended to all Redis keys created by this package.
    | This helps in namespacing and avoiding key collisions if the same Redis
    | server is used by multiple applications or for other purposes.
    |
    */
    'prefix' => env('MRCACHE_PREFIX', 'mrcache'),

    /*
    |--------------------------------------------------------------------------
    | Default Cache TTL (Time-To-Live)
    |--------------------------------------------------------------------------
    |
    | The default duration in seconds for which cached items will be stored.
    | This value is used when no specific TTL is provided at the model level
    | ($cacheTTL property) or at the query level (->withCustomTTL()).
    |
    */
    'default_ttl' => env('MRCACHE_TTL', 3600), // 1 hour

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    |
    | When strict_mode is true, any Redis connection error or operational
    | failure will throw a MrCache\Exceptions\RedisConnectionException.
    | When false, the package will silently fail, log the error, and fetch
    | results directly from the database (fallback mode).
    |
    */
    'strict_mode' => env('MRCACHE_STRICT_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Hashing Algorithm for Keys
    |--------------------------------------------------------------------------
    |
    | The algorithm used to hash the canonicalized SQL query to generate a
    | unique and fixed-length cache key. MD5 is fast and sufficient for
    | this purpose.
    |
    */
    'hash_algo' => 'md5',

    /*
    |--------------------------------------------------------------------------
    | Payload Compression Threshold
    |--------------------------------------------------------------------------
    |
    | If the serialized JSON payload size (in bytes) exceeds this threshold,
    | it will be compressed using gzencode() before storing in Redis.
    | This can save memory but adds a small CPU overhead.
    | Set to null or 0 to disable compression completely.
    |
    */
    'compress_threshold' => env('MRCACHE_COMPRESS_THRESHOLD', 10240), // 10 KB

    /*
    |--------------------------------------------------------------------------
    | Eager-loaded Relations Default Depth
    |--------------------------------------------------------------------------
    |
    | The default maximum depth for caching eager-loaded relationships. This
    | helps prevent accidentally caching deeply nested or circular relations.
    | This can be overridden at the model or query level.
    |
    */
    'relations_default_depth' => 2,

    /*
    |--------------------------------------------------------------------------
    | Store Cache Metrics
    |--------------------------------------------------------------------------
    |
    | If enabled, the package will maintain counters in Redis for cache hits
    | and misses. This is useful for monitoring performance but adds a very
    | minor overhead to each query. Use the `mrcache:stats` command
    | to view these metrics.
    |
    */
    'store_metrics' => env('MRCACHE_STORE_METRICS', true),
];
