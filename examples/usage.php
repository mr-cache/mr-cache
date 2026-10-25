<?php

declare(strict_types=1);

// Run inside a Laravel application after configuring its Post model and schema.
use App\Models\Post;
use MrCache\Services\CacheInvalidator;
use MrCache\Services\InvalidationOutbox;

$posts = Post::query()
    ->where('tenant_id', $tenantId)
    ->cacheContext(['tenant' => $tenantId, 'permission_version' => $permissionVersion])
    ->withCustomTTL(60)
    ->refreshAhead(5)
    ->get();

$fresh = Post::query()->cacheConsistency('database')->find($postId);
$explanation = Post::query()->where('tenant_id', $tenantId)->explainCache();

// External writers can use the same deployment namespace. Check the boolean result.
$invalidated = app(CacheInvalidator::class)->invalidateTables(['posts']);

// Optional: publish/migrate the outbox on this database before using it.
app(InvalidationOutbox::class)->transaction(null, function ($connection) use ($postId) {
    $connection->table('posts')->where('id', $postId)->update(['is_published' => true]);
}, ['posts']);
// Schedule: php artisan mrcache:outbox --batch=100
