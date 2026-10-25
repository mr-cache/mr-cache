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
        if (!preg_match('/^[A-Za-z0-9:_-]{1,200}$/D', $this->prefix)) {
            throw new \InvalidArgumentException('MrCache prefix must contain 1-200 letters, digits, colons, underscores or hyphens.');
        }
        $this->hashAlgo = 'sha256';
    }

    public function generateQueryKey(Builder $builder): string
    {
        $canonical = $this->canonicalize($builder);
        $hash = hash($this->hashAlgo, $canonical);
        return "{$this->prefix}:query:{$hash}";
    }
    
    public function generateCustomedIndexesKey(array $indexes): string
    {
        $key = $this->prefix;
        foreach ($indexes as $k => $v) {
            if (! $v) {
                continue;
            }
            $key .= ":{$k}={$v}";
        }
        
        return $key;
    }

    public function generateTableIndexKey(string $table): string
    {
        return "{$this->prefix}:index:table:{$table}";
    }

    public function generateRowIndexKey(string $table, string|int $primaryKey): string
    {
        return "{$this->prefix}:rowindex:table:{$table}:pk:{$primaryKey}";
    }
    
    /** @Deprecated */
    public function generateIKIndexKey(string $table, string $ikName, string $ikValue): string
    {
        return "{$this->prefix}:ikindex:table:{$table}:ik:{$ikName}={$ikValue}";
    }
    
    public function generateMultiRowsIndexKey(string $table): string
    {
        return "{$this->prefix}:multirowsindex:table:{$table}";
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
        $connection = $builder->getConnection();
        return serialize([
            'version' => 3,
            'context_hash' => $builder->mrcache_context ?? null,
            'model' => $builder->getModel()::class,
            'connection' => $connection->getName(),
            'database' => $connection->getDatabaseName(),
            'context' => array_intersect_key($connection->getConfig(), array_flip([
                'driver', 'host', 'port', 'unix_socket', 'username', 'schema', 'search_path',
                'charset', 'collation', 'prefix', 'timezone', 'read', 'write',
            ])),
            'grammar' => $connection->getQueryGrammar()::class,
            'sql' => $builder->toSql(),
            // PHP serialization is used only for hashing, never unserialized.
            // It preserves scalar types, binary strings and field boundaries.
            'bindings' => $builder->getBindings(),
        ]);
    }
}
