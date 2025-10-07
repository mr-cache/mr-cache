<?php

declare(strict_types=1);

namespace MrCache\Contracts;

/**
 * Defines the contract for cache invalidation logic.
 * Provides methods to surgically remove cache entries based on table, row, or other criteria.
 */
interface InvalidationInterface
{
    /**
     * Invalidate all cache entries associated with a specific database table.
     *
     * @param string $table The name of the table.
     * @return void
     */
    public function invalidateTable(string $table): void;

    /**
     * Invalidate all cache entries associated with a specific primary key (row) in a table.
     *
     * @param string $table The name of the table.
     * @param string|int $primaryKey The primary key of the row.
     * @return void
     */
    public function invalidateRow(string $table, string|int $primaryKey): void;

    /**
     * Flushes the entire cache managed by this package (based on the configured prefix).
     *
     * @return void
     */
    public function flushAll(): void;
}
