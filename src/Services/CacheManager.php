<?php

declare(strict_types=1);

namespace MrCache\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\KeyGeneratorInterface;

/** @internal Cache only raw attributes of conservative, deterministic model reads. */
final class CacheManager
{
    public function __construct(
        private readonly CacheClientInterface $client,
        private readonly KeyGeneratorInterface $keyGenerator,
        private readonly QueryColumnMatcher $queryColumnMatcher,
        private readonly array $config
    ) {
        $limit = $config['max_payload_bytes'] ?? 1048576;
        if (!is_int($limit) || $limit < 1 || $limit > 16777216) {
            throw new \InvalidArgumentException('MrCache max_payload_bytes must be an integer between 1 and 16777216.');
        }
    }

    public function rememberQuery(Builder $builder, Closure $executeQuery): mixed
    {
        $start = hrtime(true);
        $observer = app(CacheObserver::class);
        $metadata = ['model' => $builder->getModel()::class, 'connection' => $builder->getConnection()->getName()];
        $record = fn (string $operation, ?string $reason = null) => $observer->record($operation, $reason,
            $metadata + ['elapsed_ms' => (hrtime(true) - $start) / 1000000]);
        $policy = (new PolicyResolver($this->config))->resolve($builder);
        $eligibility = new QueryEligibility();
        if (($reason = $eligibility->reason($builder, $policy)) !== null) {
            $record('bypass', $reason);
            return $executeQuery();
        }
        $dependencies = ($this->config['invalidation']['mode'] ?? 'global') === 'tables'
            ? $eligibility->dependencies($builder) : [];
        $generation = (new Generation($this->client, $this->config))->current($dependencies);
        if ($generation === null) {
            $record('bypass', 'redis_unavailable');
            return $executeQuery();
        }
        $key = $this->keyGenerator->generateQueryKey($builder).':'.$policy->ttl.':'.$generation;
        $read = function () use ($key, $policy): array {
            $raw = $this->client->get($key);
            if ($raw === null) {
                return [];
            }
            $payload = $this->decodePayload($raw, $policy->maxPayloadBytes);
            if ($payload === []) {
                $this->client->del($key);
            }
            return $payload;
        };
        $payload = $read();
        $refresh = false;
        if ($payload !== []) {
            $this->metric('hits');
            $record('hit');
            if ($policy->refreshAheadSeconds > 0) {
                $remaining = $this->client->getTtl($key);
                $refresh = $remaining !== null && $remaining >= 0 && $remaining <= $policy->refreshAheadSeconds;
            }
            if (!$refresh) {
                return $builder->hydrate($payload['data']);
            }
        }

        $locking = $refresh || ($this->config['stampede']['enabled'] ?? true);
        $lock = new QueryLock($this->client, ($this->config['prefix'] ?? 'mrcache').':lock:'.hash('sha256', $key),
            QueryPolicy::integer($this->config['stampede']['lease_ms'] ?? 10000, 50, 60000, 'lease_ms'));
        $token = $locking ? $lock->acquire() : null;
        if ($locking && $token === null) {
            if ($refresh) {
                return $builder->hydrate($payload['data']);
            }
            $record('lock_wait');
            $waitMs = QueryPolicy::integer($this->config['stampede']['wait_ms'] ?? 100, 0, 2000, 'wait_ms');
            $deadline = hrtime(true) + $waitMs * 1000000;
            while (hrtime(true) < $deadline) {
                usleep(min(10000, max(1, (int) (($deadline - hrtime(true)) / 1000))));
                if (($payload = $read()) !== []) {
                    $this->metric('hits');
                    $record('hit', 'after_wait');
                    return $builder->hydrate($payload['data']);
                }
            }
            $this->metric('misses');
            $record('bypass', 'lock_wait_timeout');
            return $executeQuery(); // No ownership: never publish an uncoordinated fill.
        }
        try {
            // Another owner may have filled between GET and lock acquisition.
            if (!$refresh && $locking && ($payload = $read()) !== []) {
                $this->metric('hits');
                $record('hit', 'after_lock');
                return $builder->hydrate($payload['data']);
            }
            if (!$refresh) {
                $this->metric('misses');
            }
            $record($refresh ? 'refresh' : 'miss');
            $results = $executeQuery();
            if ($results instanceof Collection && $results->isNotEmpty()) {
                try {
                    $encoded = json_encode(['version' => 2,
                        'data' => $results->map(fn ($model) => $model->getRawOriginal())->all(),
                    ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    if (strlen($encoded) <= $policy->maxPayloadBytes) {
                        // Captured generation prevents resurrection after a write;
                        // token verification also prevents an expired lock owner publishing.
                        $stored = $locking ? $lock->publish($token, $key, $encoded, $policy->ttl)
                            : $this->client->set($key, $encoded, $policy->ttl);
                        $record($stored ? 'stored' : 'store_skipped', $stored ? null : 'ownership_or_redis_failure');
                    } else {
                        $record('store_skipped', 'payload_limit');
                    }
                } catch (\JsonException) {
                    $record('store_skipped', 'binary_attributes');
                }
            } else {
                $record('store_skipped', 'empty_result');
            }
            return $results;
        } finally {
            if ($token !== null) {
                $lock->release($token);
            }
        }
    }

    private function metric(string $name): void
    {
        if ($this->config['store_metrics'] ?? false) {
            $this->client->incr($this->keyGenerator->getMetricsKey($name));
        }
    }

    /** Invalid, oversized and legacy entries are misses; no PHP object deserialization. */
    public function decodePayload(string $rawPayload, ?int $maxBytes = null): array
    {
        if (strlen($rawPayload) > ($maxBytes ?? $this->config['max_payload_bytes'] ?? 1048576)) {
            return [];
        }
        try {
            $payload = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($payload) || ($payload['version'] ?? null) !== 2 || !is_array($payload['data'] ?? null)
            || !array_is_list($payload['data']) || $payload['data'] === []) {
            return [];
        }
        foreach ($payload['data'] as $row) {
            if (!is_array($row) || $row === []) {
                return [];
            }
            foreach ($row as $attribute => $value) {
                if (!is_string($attribute) || (!is_scalar($value) && $value !== null)) {
                    return [];
                }
            }
        }
        return $payload;
    }
}
