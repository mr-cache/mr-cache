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
        private readonly QueryColumnMatcher $queryColumnMatcher,
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
     * @version: 3.0.0
     * Checks if query is general
     */
    private function isGeneralQuery($builderOrModel): bool 
    {
        if ($builderOrModel instanceof \Illuminate\Database\Eloquent\Model) {
            return false;
        }
    
        if (!($builderOrModel instanceof Builder)) {
            return true;
        }
    
        $sql = $builderOrModel->toSql();
        $primaryKey = $builderOrModel->getModel()->getKeyName();
    
        $pattern = '/\b' . preg_quote($primaryKey, '/') . '\b/i';
    
        if (preg_match($pattern, $sql)) {
            return false; 
        }
    
        return true;
    }
    
    private function isSingleRowByPK($builderOrModel): bool
    {
        $primaryKey = $builderOrModel->getModel()->getKeyName();
        $sql = $builderOrModel->toSql();
        $s = $sql;
        $len = strlen($s);
    
        $s = preg_replace('#/\*.*?\*/#s', ' ', $s);
        $s = preg_replace('/--.*(\r?\n|$)/', ' ', $s);
    
        $inSingle = $inDouble = $inBacktick = false;
        $depth = 0;
    
        $findTopKeyword = function(string $kw, int $start = 0) use ($s, $len) {
            $kwLow = strtolower($kw);
            $inSingle = $inDouble = $inBacktick = false;
            $depth = 0;
            for ($i = $start; $i < $len; $i++) {
                $ch = $s[$i];
                if (!$inDouble && !$inBacktick && $ch === "'") {
                    if ($inSingle && ($i + 1 < $len) && $s[$i+1] === "'") { $i++; continue; }
                    $inSingle = !$inSingle; continue;
                }
                if (!$inSingle && !$inBacktick && $ch === '"') {
                    if ($inDouble && ($i + 1 < $len) && $s[$i+1] === '"') { $i++; continue; }
                    $inDouble = !$inDouble; continue;
                }
                if (!$inSingle && !$inDouble && $ch === '`') { $inBacktick = !$inBacktick; continue; }
    
                if (!$inSingle && !$inDouble && !$inBacktick) {
                    if ($ch === '(') { $depth++; continue; }
                    if ($ch === ')') { if ($depth>0) $depth--; continue; }
                }
    
                if (!$inSingle && !$inDouble && !$inBacktick && $depth === 0) {
                    $lenKw = strlen($kwLow);
                    $segment = strtolower(substr($s, $i, $lenKw));
                    if ($segment === $kwLow) {
                        $before = ($i === 0) ? ' ' : $s[$i-1];
                        $after = ($i + $lenKw < $len) ? $s[$i + $lenKw] : ' ';
                        if (!preg_match('/[A-Za-z0-9_`]/', $before) && !preg_match('/[A-Za-z0-9_`]/', $after)) {
                            return $i;
                        }
                    }
                }
            }
            return null;
        };
    
        $selectPos = $findTopKeyword('select', 0);
        if ($selectPos === null) return false;
    
        $fromPos = $findTopKeyword('from', $selectPos + 6);
        if ($fromPos === null) $fromPos = $len;
    
    
        $hasJoin = $findTopKeyword('join', $fromPos) !== null;
        $hasGroup = $findTopKeyword('group', $fromPos) !== null;
        $hasHaving = $findTopKeyword('having', $fromPos) !== null;
        $hasDistinct = preg_match('/\bselect\s+distinct\b/i', $s) === 1;
        $hasUnion = $findTopKeyword('union', 0) !== null;
    
        if ($hasUnion || $hasGroup || $hasHaving || $hasDistinct) {
            $selectList = substr($s, $selectPos + 6, max(0, $fromPos - ($selectPos + 6)));
            if (preg_match('/\b(count|sum|avg|min|max)\s*\(/i', $selectList) && !$hasGroup) {
                return true;
            }
            return false;
        }
    
        if ($hasJoin) {
            return false;
        }
    
    
        $wherePos = $findTopKeyword('where', $fromPos);
        if ($wherePos === null) {
            return false;
        }
    
        $endPos = $len;
        $endKeywords = ['group','having','order','limit','union'];
        foreach ($endKeywords as $kw) {
            $p = $findTopKeyword($kw, $wherePos + 5);
            if ($p !== null && $p < $endPos) $endPos = $p;
        }
        $whereStr = substr($s, $wherePos + 5, $endPos - ($wherePos + 5));
    
        $pk = preg_quote($primaryKey, '/');
    
        if (preg_match("/(?<![A-Za-z0-9_`\\.])([A-Za-z0-9_`\\.]+)\\s*=\\s*([^\\s)]+)/i", $whereStr, $m)) {
            $identifier = $m[1];
            $parts = preg_split('/\\./', $identifier);
            $last = trim(end($parts), "`\" \t\n\r");
            if (strcasecmp($last, $primaryKey) === 0) {
                return true;
            }
        }
    
        if (preg_match_all("/(?<![A-Za-z0-9_`\\.])([A-Za-z0-9_`\\.]+)\\s+IN\\s*\\(([^\\)]*)\\)/i", $whereStr, $ins, PREG_SET_ORDER)) {
            foreach ($ins as $entry) {
                $identifier = $entry[1];
                $content = trim($entry[2]);
                $parts = preg_split('/\\./', $identifier);
                $last = trim(end($parts), "`\" \t\n\r");
                if (strcasecmp($last, $primaryKey) === 0) {
                    $items = preg_split("/,(?=(?:[^']*'[^']*')*[^']*\$)/", $content);
                    $nonEmpty = array_filter(array_map('trim', $items), fn($v)=>$v !== '');
                    if (count($nonEmpty) === 1) return true;
                }
            }
        }
    
        if (preg_match("/([A-Za-z0-9_`\\.]+)\\s+BETWEEN\\s+([^\\s]+)\\s+AND\\s+([^\\s]+)/i", $whereStr, $b)) {
            $identifier = $b[1]; $v1 = $b[2]; $v2 = $b[3];
            $parts = preg_split('/\\./', $identifier);
            $last = trim(end($parts), "`\" \t\n\r");
            if (strcasecmp($last, $primaryKey) === 0) {
                if ($v1 === $v2) return true;
            }
        }
    
        return false;
    }
    
    /**
     * @version: 3.0.0
     * Remembers general queries.
     */
    private function rememberGeneralQuery(string $table, string $queryKey)
    {
        $keyOfSet = $this->keyGenerator->generateMultiRowsIndexKey($table);
        $this->client->pipeline(function ($pipe) use ($keyOfSet, $queryKey) {
            $pipe->sAdd($keyOfSet, $queryKey);
        });
    }
    
    private function rememberIKQuery(Builder $builder, string $queryKey, Collection $results): void
    {
        $independentKeys = $builder->getModel()->getIndependentKeys();
        $iKeys = $results->pluck($independentKeys[0])->unique()->filter()->all();
        
        foreach ($iKeys as $ik) {
            $ikIndex = $this->keyGenerator->generateIKIndexKey(
                table: $builder->getModel()->getTable(),
                ikName: $independentKeys[0],
                ikValue: $ik
            );
            
            $this->client->pipeline(function ($pipe) use($queryKey, $ikIndex) {
                $pipe->sAdd($ikIndex, $queryKey);
            });
            
            $this->putTtl($builder, $ikIndex);
        }
    }
    
    private function putTtl(Builder $builder, string $cacheKey): void 
    {
        $ttlOfSet   = $this->getSetTTL($builder);
        $currentTtl = $this->client->getTtl($cacheKey);
        
        if (! $currentTtl || $currentTtl === -1) {
            $this->client->expire($cacheKey, $ttlOfSet);
        } else if ($currentTtl < $ttlOfSet) {
            $this->client->expire($cacheKey, $ttlOfSet);
        }
    }
    
    private function containsIK($builder, $results): bool
    {
        if ($builder->getModel()->isContaintIKs() == false) {
            return false;
        }
        
        $sql = $builder->toSql();
        $IK = $builder->getModel()->getIndependentKeys();
        
        return $this->queryColumnMatcher->matches($sql, $IK);
        // return $this->isByIndependentKeys()
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
        $ttlOfSet = $this->getSetTTL($builder);

        $this->client->pipeline(function ($pipe) use ($queryKey, $payload, $ttl, $table, $primaryKeys) {
            $ttl > 0 ? $pipe->setex($queryKey, $ttl, $payload) : $pipe->set($queryKey, $payload);

            $tableIndexKey = $this->keyGenerator->generateTableIndexKey($table);
            $pipe->sAdd($tableIndexKey, $queryKey);

            $rowIndexKey = null;
            
            foreach ($primaryKeys as $pk) {
                $rowIndexKey = $this->keyGenerator->generateRowIndexKey($table, $pk);
                $pipe->sAdd($rowIndexKey, $queryKey);
            }
        });
        
        foreach ($primaryKeys as $pk) {
            $rowIndexKey = $this->keyGenerator->generateRowIndexKey($table, $pk);
            if ($rowIndexKey) {
                $currentTtl = $this->client->getTtl($rowIndexKey);
                if (! $currentTtl || $currentTtl === -1) {
                    $this->client->expire($rowIndexKey, $ttlOfSet);
                } else if ($currentTtl < $ttlOfSet) {
                    $this->client->expire($rowIndexKey, $ttlOfSet);
                }
            }
        }
        
        if ($this->containsIK($builder, $results)) {
            $this->rememberIKQuery($builder, $queryKey, $results);
            return;
        }
        
        if (! $this->isSingleRowByPK($builder)) {
            $this->rememberGeneralQuery($table, $queryKey);
        }
        
    }
    
    private function getSetTTL(Builder $builder): int
    {
        $biggestTTL = 0;
        
        if (property_exists($builder, 'mrcache_custom_ttl')) {
            $biggestTTL = (int) $builder->mrcache_custom_ttl;
        }
        
        if (method_exists($builder->getModel(), 'getCacheTTL')) {
            if ( (int) $builder->getModel()->getCacheTTL() > $biggestTTL) {
                $biggestTTL =  (int) $builder->getModel()->getCacheTTL();
            }
        } else if ($this->defaultTtl > $biggestTTL) {
            $biggestTTL = $this->defaultTtl;
        }
        
        return $biggestTTL;
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
