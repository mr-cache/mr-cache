<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Database\Eloquent\Builder;

final class CacheInspector
{
    public function __construct(private readonly PolicyResolver $policies, private readonly QueryEligibility $eligibility)
    {
    }

    /** No Redis access. Application scopes/pre-query hooks may execute application code. */
    public function explain(Builder $builder, array $columns = ['*']): array
    {
        $query = (clone $builder)->applyScopes();
        $query->getQuery()->columns ??= $columns;
        $policy = $this->policies->resolve($query);
        $reason = !config('mrcache.enabled', false) ? 'disabled' : $this->eligibility->reason($query, $policy);
        return ['cacheable' => $reason === null, 'reason' => $reason, 'model' => $query->getModel()::class,
            'connection' => $query->getConnection()->getName(), 'policy' => $policy->toArray(),
            'dependencies' => $reason === null ? $this->eligibility->dependencies($query) : [],
            'context_hash' => $query->mrcache_context ?? null];
    }
}
