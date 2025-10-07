<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Database\Eloquent\Builder;
use MrCache\Contracts\KeyGeneratorInterface;

/**
 * Generates deterministic cache keys based on Eloquent queries.
 *
 * @internal
 */
final class KeyGenerator implements KeyGeneratorInterface
{
    private readonly string $prefix;
    private readonly string $hashAlgo;

    public function __construct(array $config)
    {
        $this->prefix = $config['prefix'] ?? 'mrcache';
        $this->hashAlgo = $config['hash_algo'] ?? 'md5';
    }

    public function generateQueryKey(Builder $builder): string
    {
        $canonical = $this->canonicalize($builder);
        $hash = hash($this->hashAlgo, $canonical);
        return "{$this->prefix}:query:{$hash}";
    }

    public function generateTableIndexKey(string $table): string
    {
        return "{$this->prefix}:index:table:{$table}";
    }

    public function generateRowIndexKey(string $table, string|int $primaryKey): string
    {
        return "{$this->prefix}:rowindex:table:{$table}:pk:{$primaryKey}";
    }

    public function getMetricsKey(string $metric): string
    {
        return "{$this->prefix}:metrics:{$metric}";
    }

    public function parseKey(string $queryKey): array
    {
        $parts = explode(':', $queryKey, 3);
        return [
            'prefix' => $parts[0] ?? null,
            'type' => $parts[1] ?? null,
            'hash' => $parts[2] ?? null,
        ];
    }

    /**
     * Creates a stable, serializable representation of the query.
     */
    private function canonicalize(Builder $builder): string
    {
        $eagerLoad = $builder->getEagerLoads();
        ksort($eagerLoad); // Sort relations alphabetically for consistency

        return json_encode([
            'sql' => $builder->toSql(),
            'bindings' => $builder->getBindings(),
            'relations' => $eagerLoad,
            // We might add more factors here in the future, e.g., soft-delete status
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
