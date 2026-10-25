<?php

declare(strict_types=1);

namespace MrCache\Services;

use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\InvalidationInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use Illuminate\Support\Traits\Macroable;

/** @internal All legacy invalidation entry points conservatively rotate the namespace. */
final class InvalidationManager implements InvalidationInterface
{
    use Macroable;
    public function __construct(
        private readonly CacheClientInterface $client,
        private readonly KeyGeneratorInterface $keyGenerator,
        private readonly array $config,
        private readonly CacheManager $cacheManager
    ) {
    }

    public function invalidateTable(string $table): void
    {
        $this->flushAll();
    }

    public function invalidateRow(string $table, string|int $primaryKey): void
    {
        $this->flushAll();
    }

    public function invalidateGeneralQueries(string $table): void
    {
        $this->flushAll();
    }

    public function invalidateIKQueries(string $table, string $ikName, string $ikValue): void
    {
        $this->flushAll();
    }

    public function invalidateIndexesQueries(string $table, array $indexes): void
    {
        $this->flushAll();
    }

    public function flushAll(): void
    {
        // Logical invalidation, bounded O(1). Old entries expire by their own TTL.
        (new Generation($this->client, $this->config))->rotate();
    }
}
