<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Support\Facades\Log;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\InvalidationInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use Illuminate\Support\Traits\Macroable;

/**
 * Handles the logic for cache invalidation.
 *
 * It provides methods to surgically remove cache entries based on table or row,
 * ensuring that index sets are kept consistent.
 *
 * @internal
 */
final class InvalidationManager implements InvalidationInterface
{
    use Macroable;
    /**
     * Lua script for atomically deleting a query key and removing it from all associated index sets.
     *
     * KEYS[1]: The query key to delete (e.g., mrcache:query:hash).
     * ARGV[1...N]: The index set keys this query key belongs to (e.g., table index, row indexes).
     */
    private const ATOMIC_DELETE_LUA = <<<LUA
        -- Delete the main query key itself
        redis.call('DEL', KEYS[1])

        -- Remove the query key from all provided index sets
        for i = 1, #ARGV do
            redis.call('SREM', ARGV[i], KEYS[1])
        end

        return 1
    LUA;

    public function __construct(
        private readonly CacheClientInterface $client,
        private readonly KeyGeneratorInterface $keyGenerator,
        private readonly array $config,
        private readonly CacheManager $cacheManager // Used for decoding payloads
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateTable(string $table): void
    {
        $tableIndexKey = $this->keyGenerator->generateTableIndexKey($table);
        $queryKeys = $this->client->sMembers($tableIndexKey);

        if (!empty($queryKeys)) {
            // This is a heavy operation. We delete each key and its references.
            $this->deleteQueryKeysAtomically(...$queryKeys);
        }

        // Also delete all row indexes for this table as a cleanup.
        $pattern = $this->keyGenerator->generateRowIndexKey($table, '*');
        $this->deleteKeysByPattern($pattern);

        // Finally, delete the main table index set itself.
        $this->client->del($tableIndexKey);
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateRow(string $table, string|int $primaryKey): void
    {
        $rowIndexKey = $this->keyGenerator->generateRowIndexKey($table, $primaryKey);
        $queryKeys = $this->client->sMembers($rowIndexKey);

        if (!empty($queryKeys)) {
            $this->deleteQueryKeysAtomically(...$queryKeys);
        }

        // The query keys are gone, so the index set can be removed.
        $this->client->del($rowIndexKey);
    }

    /**
     * {@inheritdoc}
     */
    public function flushAll(): void
    {
        $prefix = $this->config['prefix'] ?? 'mrcache';
        $this->deleteKeysByPattern("{$prefix}:*");
    }

    /**
     * Deletes one or more query keys and surgically removes their references from all parent indexes.
     * This is the core routine for maintaining consistency.
     *
     * @param string ...$queryKeys
     */
    private function deleteQueryKeysAtomically(string ...$queryKeys): void
    {
        // This process requires fetching the payload for each key to find out which
        // indexes it belongs to. This is slow but necessary for correctness.
        // We can optimize by fetching multiple keys at once if the driver supports MGET.
        foreach ($queryKeys as $queryKey) {
            $rawPayload = $this->client->get($queryKey);

            if (!$rawPayload) {
                // Key might have expired or been deleted by another process.
                continue;
            }

            try {
                // We need to decode the payload to find the table and PKs
                $payload = $this->cacheManager->decodePayload($rawPayload);
                $table = $payload['table'] ?? null;
                $pks = $payload['pks'] ?? [];

                if (!$table) {
                    // If metadata is corrupt, just delete the key itself.
                    $this->client->del($queryKey);
                    continue;
                }

                // Reconstruct all index keys this query key belongs to.
                $indexKeys = [$this->keyGenerator->generateTableIndexKey($table)];
                foreach ($pks as $pk) {
                    $indexKeys[] = $this->keyGenerator->generateRowIndexKey($table, $pk);
                }

                // Execute the atomic Lua script.
                $this->client->eval(self::ATOMIC_DELETE_LUA, [$queryKey], $indexKeys);

            } catch (\Throwable $e) {
                Log::warning('MrCache: Failed to decode payload or run atomic delete for key.', [
                    'key' => $queryKey,
                    'error' => $e->getMessage()
                ]);
                // Fallback to simple deletion if something goes wrong.
                $this->client->del($queryKey);
            }
        }
    }

    /**
     * Deletes all keys matching a given pattern using SCAN for safety.
     *
     * @param string $pattern
     */
    private function deleteKeysByPattern(string $pattern): void
    {
        $keysToDelete = [];
        $count = 0;

        foreach ($this->client->scanKeys($pattern) as $key) {
            $keysToDelete[] = $key;
            $count++;
            // Delete in batches of 500 to avoid memory issues and long-held connections.
            if ($count >= 500) {
                $this->client->del(...$keysToDelete);
                $keysToDelete = [];
                $count = 0;
            }
        }

        if (!empty($keysToDelete)) {
            $this->client->del(...$keysToDelete);
        }
    }
}
