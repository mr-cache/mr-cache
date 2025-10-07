<?php

declare(strict_types=1);

namespace MrCache\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Defines the contract for generating cache keys and metadata keys.
 * Ensures a consistent and deterministic key structure across the package.
 */
interface KeyGeneratorInterface
{
    /**
     * Generates the primary query cache key from an Eloquent builder instance.
     *
     * @param Builder $builder
     * @return string The hashed query key (e.g., "mrcache:query:abcdef123...").
     */
    public function generateQueryKey(Builder $builder): string;

    /**
     * Generates the key for the set that indexes all queries for a specific table.
     *
     * @param string $table
     * @return string The table index key (e.g., "mrcache:index:table:users").
     */
    public function generateTableIndexKey(string $table): string;

    /**
     * Generates the key for the set that indexes all queries for a specific row.
     *
     * @param string $table
     * @param string|int $primaryKey
     * @return string The row index key (e.g., "mrcache:rowindex:table:users:pk:123").
     */
    public function generateRowIndexKey(string $table, string|int $primaryKey): string;

    /**
     * Generates the key for storing a specific metric (e.g., hits, misses).
     *
     * @param string $metric
     * @return string The metric key (e.g., "mrcache:metrics:hits").
     */
    public function getMetricsKey(string $metric): string;

    /**
     * Parses a query key to extract metadata if possible.
     * This is useful for debugging but not used in the core logic.
     *
     * @param string $queryKey
     * @return array An array containing the prefix, type, and hash.
     */
    public function parseKey(string $queryKey): array;
}
