<?php

declare(strict_types=1);

namespace MrCache\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\KeyGeneratorInterface;

/**
 * The core engine for handling query caching logic.
 *
 * @internal
 */
final class CacheManager
{
    private bool $storeMetrics;
    private ?int $compressThreshold;
    private int $defaultTtl;

    public function __construct(
        private readonly CacheClientInterface $client,
        private readonly KeyGeneratorInterface $keyGenerator,
        private readonly array $config
    ) {
        $this->storeMetrics = $config['store_metrics'] ?? false;
        $this->compressThreshold = $config['compress_threshold'] ?? null;
        $this->defaultTtl = $config['default_ttl'] ?? 3600;
    }

    /**
     * Attempt to retrieve results from cache, or execute the query and cache its results.
     *
     * @param Builder $builder The Eloquent query builder.
     * @param Closure $executeQuery A closure that executes the actual database query.
     * @return mixed The query results.
     */
    public function rememberQuery(Builder $builder, \Closure $executeQuery): mixed
    {
        if (property_exists($builder, 'mrcache_without_caching') && $builder->mrcache_without_caching === true) {
            return $executeQuery();
        }

        $queryKey = $this->keyGenerator->generateQueryKey($builder);
        $cached = $this->client->get($queryKey);

        if ($cached !== null) {
            if ($this->storeMetrics) {
                $this->client->incr($this->keyGenerator->getMetricsKey('hits'));
            }

            $payload = $this->decodePayload($cached);

            $models = array_map(
                fn($item) => $builder->getModel()->newFromBuilder($item),
                $payload['data']
            );

            return $builder->getModel()->newCollection($models);
        }

        if ($this->storeMetrics) {
            $this->client->incr($this->keyGenerator->getMetricsKey('misses'));
        }

        // Execute query against database
        $results = $executeQuery();

        if ($results instanceof Collection && $results->isEmpty()) {
            return $results;
        }

        $this->store($builder, $queryKey, $results);

        return $results;
    }

    /**
     * Stores the query results in Redis.
     */
    private function store(Builder $builder, string $queryKey, Collection $results): void
    {
        $model = $builder->getModel();
        $table = $model->getTable();
        $primaryKeyName = $model->getKeyName();
        $primaryKeys = $results->pluck($primaryKeyName)->unique()->filter()->all();

        $relations = array_keys($builder->getEagerLoads());

        $payload = $this->encodePayload([
            'table' => $table,
            'pks' => $primaryKeys,
            'relations' => $relations,
            'created_at' => time(),
            'data' => $results->toArray(),
        ]);

        $ttl = $this->determineTtl($builder);

        $this->client->pipeline(function ($pipe) use ($queryKey, $payload, $ttl, $table, $primaryKeys) {
            $ttl > 0 ? $pipe->setex($queryKey, $ttl, $payload) : $pipe->set($queryKey, $payload);

            $tableIndexKey = $this->keyGenerator->generateTableIndexKey($table);
            $pipe->sAdd($tableIndexKey, $queryKey);

            foreach ($primaryKeys as $pk) {
                $rowIndexKey = $this->keyGenerator->generateRowIndexKey($table, $pk);
                $pipe->sAdd($rowIndexKey, $queryKey);
            }
        });
    }

    private function determineTtl(Builder $builder): int
    {
        if (property_exists($builder, 'mrcache_custom_ttl')) {
            return (int) $builder->mrcache_custom_ttl;
        }

        if (method_exists($builder->getModel(), 'getCacheTTL')) {
            return (int) $builder->getModel()->getCacheTTL() ?: $this->defaultTtl;
        }

        return $this->defaultTtl;
    }

    private function encodePayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($this->compressThreshold > 0 && strlen($json) > $this->compressThreshold) {
            $compressed = gzencode($json, 6);
            return 'C::' . $compressed; // 'C::' prefix indicates compression
        }

        return $json;
    }

    public function decodePayload(string $rawPayload): array
    {
        if (str_starts_with($rawPayload, 'C::')) {
            $rawPayload = substr($rawPayload, 3);
            $rawPayload = gzdecode($rawPayload);
        }

        return json_decode($rawPayload, true);
    }
}
