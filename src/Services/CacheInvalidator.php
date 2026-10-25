<?php

declare(strict_types=1);

namespace MrCache\Services;

use MrCache\Contracts\CacheClientInterface;

/** Public external-writer API; false explicitly reports non-strict Redis failure. */
final class CacheInvalidator
{
    public function __construct(private readonly CacheClientInterface $client, private readonly array $config, private readonly CacheObserver $observer)
    {
        if (!in_array($config['invalidation']['mode'] ?? 'global', ['global', 'tables'], true)) {
            throw new \InvalidArgumentException('MrCache invalidation mode must be global or tables.');
        }
        foreach ($config['invalidation']['writes'] ?? [] as $source => $dependencies) {
            TableNames::normalize($source);
            if (!is_array($dependencies)) {
                throw new \InvalidArgumentException('MrCache write dependencies must be table arrays.');
            }
            TableNames::list($dependencies);
        }
    }

    public function invalidateAll(): bool
    {
        $ok = (new Generation($this->client, $this->config))->rotate();
        $this->observer->record('invalidate', $ok ? 'namespace' : 'redis_unavailable');
        return $ok;
    }

    public function invalidateTables(array $tables): bool
    {
        $tables = TableNames::list($tables);
        $ok = ($this->config['invalidation']['mode'] ?? 'global') === 'tables'
            ? (new Generation($this->client, $this->config))->rotateTables($tables)
            : (new Generation($this->client, $this->config))->rotate();
        $this->observer->record('invalidate', $ok ? 'tables' : 'redis_unavailable', ['dependency_count' => count($tables)]);
        return $ok;
    }

    /** Explicit write maps must include trigger/cascade dependencies; unknown SQL falls back globally. */
    public function observe(string $sql): bool
    {
        $table = TableNames::mutation($sql);
        $map = $this->config['invalidation']['writes'] ?? [];
        if (($this->config['invalidation']['mode'] ?? 'global') === 'tables' && $table !== null && isset($map[$table]) && !preg_match('/;|\bjoin\b/i', $sql)) {
            return $this->invalidateTables(array_merge([$table], $map[$table]));
        }
        return $this->invalidateAll();
    }
}
