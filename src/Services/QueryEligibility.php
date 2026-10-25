<?php

declare(strict_types=1);

namespace MrCache\Services;

use Illuminate\Database\Eloquent\Builder;

final class QueryEligibility
{
    public function reason(Builder $builder, QueryPolicy $policy): ?string
    {
        if ($builder->mrcache_without_caching ?? false) {
            return 'explicit_bypass';
        }
        if ($policy->consistency === 'database') {
            return 'database_consistency';
        }
        if ($policy->ttl === 0) {
            return 'zero_ttl';
        }
        $q = $builder->getQuery();
        $q->applyBeforeQueryCallbacks();
        // Laravel 10 has no base query after-query callback property.
        if (property_exists($q, 'afterQueryCallbacks')) {
            static $callbacks = null;
            $callbacks ??= new \ReflectionProperty(\Illuminate\Database\Query\Builder::class, 'afterQueryCallbacks');
            if ($callbacks->getValue($q) !== []) {
                return 'base_result_callback';
            }
        }
        if ($builder->getConnection()->transactionLevel() > 0) {
            return 'transaction';
        }
        if ($q->lock !== null) {
            return 'locking_read';
        }
        if ($builder->getConnection()->getRawReadPdo() !== null || $q->useWritePdo) {
            return 'connection_policy';
        }
        if ($q->joins || $q->unions || $q->groups || $q->havings || $q->aggregate) {
            return 'complex_query';
        }
        if (array_diff(array_keys($builder->getEagerLoads()), $policy->relations)) {
            return 'relations_not_allowed';
        }
        try {
            if (!is_string($q->from)) {
                return 'raw_table';
            }
            TableNames::normalize($q->from);
        } catch (\InvalidArgumentException) {
            return 'raw_table';
        }
        if (TableNames::normalize($q->from) === TableNames::normalize(config('mrcache.outbox.table', 'mrcache_outbox'))) {
            return 'internal_outbox';
        }
        foreach ($q->orders ?? [] as $order) {
            if (($order['type'] ?? null) === 'Raw' || !is_string($order['column'] ?? null)) {
                return 'raw_order';
            }
        }
        foreach ($q->columns ?? ['*'] as $column) {
            if (!is_string($column) || !preg_match('/^(?:\*|[\w.]+(?:\s+as\s+\w+)?)$/iD', $column)) {
                return 'raw_projection';
            }
        }
        foreach ($q->wheres ?? [] as $where) {
            foreach ($where as $part) {
                if (is_object($part)) {
                    return 'expression';
                }
            }
            foreach ($where['values'] ?? [] as $value) {
                if (!is_scalar($value) && $value !== null) {
                    return 'non_scalar_binding';
                }
            }
            if (!in_array($where['type'], ['Basic', 'Null', 'NotNull', 'Column', 'In', 'NotIn', 'InRaw', 'NotInRaw', 'Between'], true)) {
                return 'unsupported_predicate';
            }
        }
        foreach ($builder->getBindings() as $binding) {
            if (!is_scalar($binding) && $binding !== null) {
                return 'non_scalar_binding';
            }
        }
        // Permit only the parentheses emitted for scalar IN lists. Functions,
        // nested predicates and subqueries still fail the SQL guard.
        $sql = preg_replace('/\bin\s*\(\s*(?:\?(?:\s*,\s*\?)*|-?\d+(?:\s*,\s*-?\d+)*)\s*\)/i', 'in_list', $builder->toSql());
        return preg_match('/[();]|--|\/\*|\b(current_timestamp|current_date|current_time|returning)\b/i', $sql)
            ? 'unsupported_sql' : null;
    }

    public function dependencies(Builder $builder): array
    {
        $parts = explode('.', TableNames::normalize($builder->getQuery()->from));
        $parts[count($parts) - 1] = $builder->getConnection()->getTablePrefix().end($parts);
        return TableNames::list(array_merge([implode('.', $parts)], $builder->mrcache_dependencies ?? []));
    }
}
