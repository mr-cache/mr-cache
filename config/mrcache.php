<?php

declare(strict_types=1);

return [
    // Opt in only after reviewing the bounded-staleness and external-writer policy.
    'enabled' => env('MRCACHE_ENABLED', false),
    'redis' => [
        'client' => env('MRCACHE_REDIS_CLIENT', 'predis'),
        'host' => env('MRCACHE_REDIS_HOST', '127.0.0.1'),
        'port' => env('MRCACHE_REDIS_PORT', 6379),
        'scheme' => env('MRCACHE_REDIS_SCHEME', 'tcp'),
        'username' => env('MRCACHE_REDIS_USERNAME'),
        // TLS verification cannot be disabled through this package.
        'tls' => [], // optional cafile, capath, peer_name, local_cert, local_pk
        'password' => env('MRCACHE_REDIS_PASSWORD'),
        'database' => env('MRCACHE_REDIS_DB', 0),
        'timeout' => env('MRCACHE_REDIS_TIMEOUT', 1.0),
        'read_timeout' => env('MRCACHE_REDIS_READ_TIMEOUT', 1.0),
    ],
    // Must be unique per application, environment and authorization context.
    'prefix' => env('MRCACHE_PREFIX', 'mrcache'),
    // Query > model > global. Zero bypasses; negative TTLs are rejected.
    'default_ttl' => env('MRCACHE_TTL', 3600),
    'strict_mode' => env('MRCACHE_STRICT_MODE', false),
    'max_payload_bytes' => 1048576,
    // bounded: cache with documented TTL limits; database: bypass all cached reads.
    'consistency' => 'bounded',
    'policies' => [], // Model class => ttl, consistency, max_payload_bytes, relations, refresh_ahead_seconds
    'stampede' => ['enabled' => true, 'lease_ms' => 10000, 'wait_ms' => 100],
    'refresh_ahead_seconds' => 0, // synchronous single-owner refresh before expiry; must be < TTL
    // Global is conservative. Table mode requires an explicit complete write dependency map.
    'invalidation' => ['mode' => 'global', 'writes' => [], 'metadata_ttl' => 86400],
    'outbox' => ['table' => 'mrcache_outbox'],
    'store_metrics' => env('MRCACHE_STORE_METRICS', false),
];
