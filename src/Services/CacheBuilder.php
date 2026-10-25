<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Database\Eloquent\Builder;
use Closure;

/** @internal */
class CacheBuilder extends Builder
{
    public bool $mrcache_without_caching = false;
    public ?int $mrcache_custom_ttl = null;

    public array $mrcache_policy = [];
    public array $mrcache_dependencies = [];
    public ?string $mrcache_context = null;

    public function cachePolicy(array $policy): static
    {
        $previous = $this->mrcache_policy;
        $this->mrcache_policy = array_replace($previous, $policy);
        try {
            (new PolicyResolver(config('mrcache')))->resolve($this);
        } catch (\Throwable $error) {
            $this->mrcache_policy = $previous;
            throw $error;
        }
        return $this;
    }

    public function cacheConsistency(string $mode): static
    {
        return $this->cachePolicy(['consistency' => $mode]);
    }

    public function refreshAhead(int $seconds): static
    {
        return $this->cachePolicy(['refresh_ahead_seconds' => $seconds]);
    }

    public function cacheRelations(array $names): static
    {
        return $this->cachePolicy(['relations' => $names]);
    }

    public function cacheDependsOn(array $tables): static
    {
        $this->mrcache_dependencies = TableNames::list($tables);
        return $this;
    }

    public function cacheContext(string|int|array $context): static
    {
        $normalize = function ($value, int $depth = 0) use (&$normalize) {
            if ($depth > 8 || (is_array($value) && count($value) > 64)) {
                throw new \InvalidArgumentException('MrCache context exceeds structural limits.');
            }
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $normalize($item, $depth + 1);
                }
            } elseif ((!is_scalar($value) && $value !== null) || (is_float($value) && !is_finite($value))) {
                throw new \InvalidArgumentException('MrCache context must contain finite scalar values only.');
            }
            return $value;
        };
        $serialized = serialize($normalize($context));
        if (strlen($serialized) > 8192) {
            throw new \InvalidArgumentException('MrCache context exceeds 8192 bytes.');
        }
        $this->mrcache_context = hash('sha256', $serialized);
        return $this;
    }

    public function explainCache(): array
    {
        return app(CacheInspector::class)->explain($this);
    }

    protected function eagerLoadRelation(array $models, $name, Closure $constraints)
    {
        $policy = (new PolicyResolver(config('mrcache')))->resolve($this);
        return parent::eagerLoadRelation($models, $name, function ($relation) use ($constraints, $policy, $name) {
            $constraints($relation);
            $query = $relation->getQuery();
            if ($query instanceof self) {
                if ($this->mrcache_context !== null) {
                    $query->mrcache_context = hash('sha256', serialize([$this->mrcache_context, $query->mrcache_context]));
                }
                $query->mrcache_without_caching = $query->mrcache_without_caching || $this->mrcache_without_caching
                    || !config('mrcache.enabled', false) || !in_array($name, $policy->relations, true)
                    || $policy->consistency === 'database' || $policy->ttl === 0 || $this->getConnection()->transactionLevel() > 0;
                $child = (new PolicyResolver(config('mrcache')))->resolve($query);
                $query->mrcache_custom_ttl = min($policy->ttl, $child->ttl);
            }
        });
    }

    public function get($columns = ['*'])
    {
        if (!config('mrcache.enabled', false) || $this->mrcache_without_caching) {
            app(CacheObserver::class)->record('bypass', $this->mrcache_without_caching ? 'explicit_bypass' : 'disabled');
            return parent::get($columns);
        }

        // Apply scopes exactly once, before calculating SQL identity.
        $builder = $this->applyScopes();
        $query = $builder->getQuery();
        $original = $query->columns;
        $query->columns ??= (array) $columns;
        try {
            $results = app(CacheManager::class)->rememberQuery($builder, function () use ($builder, $columns) {
                $models = $builder->getModels($columns);
                return $builder->getModel()->newCollection($models);
            });
            // Relation queries run separately; nested snapshots never enter root payloads.
            if ($results->isNotEmpty() && $builder->getEagerLoads()) {
                $results = $builder->getModel()->newCollection($builder->eagerLoadRelations($results->all()));
            }
            return method_exists($this, 'applyAfterQueryCallbacks') ? $this->applyAfterQueryCallbacks($results) : $results;
        } finally {
            $query->columns = $original;
        }
    }
}
