<?php

declare(strict_types=1);

namespace MrCache\Services;

use Predis\Client as PredisClient;
use MrCache\Contracts\CacheClientInterface;
use MrCache\Exceptions\RedisConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * A direct Redis client that prioritizes the native PHP ext-redis extension
 * and falls back to Predis. It handles connection errors based on the
 * configured strict_mode.
 *
 * @internal This class is not meant to be used directly by the end-user.
 */
final class RedisNativeClient implements CacheClientInterface
{
    /**
     * The active Redis client instance (\Redis or Predis\Client).
     */
    private \Redis|PredisClient|null $redis = null;

    private bool $isPhpRedis = false;

    /**
     * @param array $config The package configuration array.
     * @throws RedisConnectionException
     */
    public function __construct(
        private readonly array $config
    ) {
        if (!in_array($config['redis']['client'] ?? null, ['predis', 'phpredis'], true)) {
            throw new \InvalidArgumentException('MrCache Redis client must be predis or phpredis.');
        }
        foreach (['timeout', 'read_timeout'] as $option) {
            $value = $config['redis'][$option] ?? $config['redis']['timeout'] ?? 1.0;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) {
                throw new \InvalidArgumentException('MrCache Redis timeouts must be finite and greater than zero.');
            }
        }
        $scheme = $config['redis']['scheme'] ?? 'tcp';
        if (!in_array($scheme, ['tcp', 'tls'], true)) {
            throw new \InvalidArgumentException('MrCache Redis scheme must be tcp or tls.');
        }
        $tls = $config['redis']['tls'] ?? [];
        if (!is_array($tls) || array_diff(array_keys($tls), ['cafile', 'capath', 'peer_name', 'local_cert', 'local_pk'])) {
            throw new \InvalidArgumentException('MrCache TLS options must preserve certificate and hostname verification.');
        }
        $this->connect();
    }

    /**
     * Attempts to establish a connection to Redis.
     *
     * @throws RedisConnectionException
     */
    private function connect(): void
    {
        $connectionConfig = $this->config['redis'];
        $usePhpRedis = ($connectionConfig['client'] === 'phpredis') && extension_loaded('redis');

        try {
            if ($usePhpRedis) {
                $this->redis = new \Redis();
                if (!@$this->redis->connect(
                    (($connectionConfig['scheme'] ?? 'tcp') === 'tls' ? 'tls://' : '').$connectionConfig['host'],
                    (int) $connectionConfig['port'],
                    (float) $connectionConfig['timeout'],
                    null, 0, (float) ($connectionConfig['read_timeout'] ?? $connectionConfig['timeout']),
                    ($connectionConfig['scheme'] ?? 'tcp') === 'tls' ? ['stream' => $this->tlsOptions()] : null
                )) {
                    throw new \RedisException('Redis connection failed.');
                }

                if (!empty($connectionConfig['password'])) {
                    if (!$this->redis->auth(!empty($connectionConfig['username']) ? [$connectionConfig['username'], $connectionConfig['password']] : $connectionConfig['password'])) {
                        throw new \RedisException('Redis authentication failed.');
                    }
                }

                if (!$this->redis->select((int) $connectionConfig['database'])) {
                    throw new \RedisException('Redis database selection failed.');
                }
                $this->redis->setOption(\Redis::OPT_READ_TIMEOUT, (float) ($connectionConfig['read_timeout'] ?? $connectionConfig['timeout']));
                $this->isPhpRedis = true;
            } elseif (class_exists(PredisClient::class)) {
                $this->redis = new PredisClient([
                    'scheme' => $connectionConfig['scheme'] ?? 'tcp',
                    'ssl' => $this->tlsOptions(),
                    ...(!empty($connectionConfig['username']) ? ['username' => $connectionConfig['username']] : []),
                    'host'   => $connectionConfig['host'],
                    'port'   => $connectionConfig['port'],
                    'timeout' => $connectionConfig['timeout'],
                    'read_write_timeout' => $connectionConfig['read_timeout'] ?? $connectionConfig['timeout'],
                    'database' => $connectionConfig['database'],
                    ...($connectionConfig['password'] ? ['password' => $connectionConfig['password']] : []),
                ]);
                @$this->redis->connect();
                $this->isPhpRedis = false;
            }
        } catch (\RedisException|\Predis\PredisException $e) {
            $this->redis = null;
            if ($this->config['strict_mode']) {
                throw new RedisConnectionException('MrCache: Failed to connect to Redis.', 0, $e);
            }
            Log::warning('MrCache: Redis connection unavailable; caching disabled for this client.');
            $this->unavailableEvent('connection');
        }
    }

    private function unavailableEvent(string $reason): void
    {
        if (function_exists('app') && app()->bound(CacheObserver::class)) {
            app(CacheObserver::class)->record('redis_unavailable', $reason);
        }
    }

    private function tlsOptions(): array
    {
        return ['verify_peer' => true, 'verify_peer_name' => true] + ($this->config['redis']['tls'] ?? []);
    }

    private function execute(callable $callback)
    {
        if (!$this->redis) {
            if ($this->config['strict_mode']) {
                throw new RedisConnectionException('MrCache: Redis client is unavailable.');
            }
            return null;
        }

        try {
            if ($this->isPhpRedis) {
                $this->redis->clearLastError();
            }
            $result = $callback($this->redis);
            // phpredis may return false for a server error instead of throwing.
            if ($this->isPhpRedis && $this->redis->getLastError() !== null && $this->redis->getLastError() !== false) {
                throw new \RedisException('Redis server returned a command error.');
            }
            return $result;
        } catch (\RedisException|\Predis\PredisException $e) {
            $this->redis = null; // Circuit breaker for this client lifetime; no repeated error logging.
            if ($this->config['strict_mode']) {
                throw new RedisConnectionException('MrCache: Redis command failed.', 0, $e);
            }
            Log::warning('MrCache: Redis command failed; caching disabled for this client.');
            $this->unavailableEvent('command');
            return null;
        }
    }

    public function get(string $key): ?string
    {
        $result = $this->execute(fn($redis) => $redis->get($key));
        return $result === false ? null : $result;
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        return (bool) $this->execute(function ($redis) use ($key, $value, $ttl) {
            if ($ttl > 0) {
                return $redis->setex($key, $ttl, $value);
            }
            return $redis->set($key, $value);
        });
    }

    public function del(string ...$keys): int
    {
        return (int) $this->execute(fn($redis) => $redis->del($keys));
    }

    public function sAdd(string $setKey, string ...$members): int
    {
        return (int) $this->execute(fn($redis) => $redis->sAdd($setKey, ...$members));
    }

    public function sMembers(string $setKey): array
    {
        return (array) $this->execute(fn($redis) => $redis->sMembers($setKey));
    }

    public function sRem(string $setKey, string ...$members): int
    {
        return (int) $this->execute(fn($redis) => $redis->sRem($setKey, ...$members));
    }

    public function incr(string $key): int
    {
        return (int) $this->execute(fn($redis) => $redis->incr($key));
    }

    public function scanKeys(string $pattern): \Generator
    {
        if ($this->isPhpRedis) {
            $cursor = null;
            do {
                $keys = $this->execute(function ($redis) use (&$cursor, $pattern) { return $redis->scan($cursor, $pattern, 100); });
                // SCAN needs a cursor passed by reference, including across empty pages.
                if ($keys === null) {
                    return;
                }
                foreach ($keys ?: [] as $key) {
                    yield $key;
                }
            } while ($cursor !== 0);
            return;
        }
        $cursor = '0';
        do {
            $page = $this->execute(fn ($redis) => $redis->scan($cursor, ['match' => $pattern, 'count' => 100]));
            if ($page === null) {
                return;
            }
            [$cursor, $keys] = $page;
            yield from $keys;
        } while ((string) $cursor !== '0');
    }

    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->execute(fn ($redis) => $this->isPhpRedis
            ? $redis->eval($script, array_merge($keys, $args), count($keys))
            : $redis->eval($script, count($keys), ...array_merge($keys, $args)));
    }

    public function info(?string $section = null): array
    {
        return (array) $this->execute(fn($redis) => $section === null ? $redis->info() : $redis->info($section));
    }

    public function pipeline(callable $callback): ?array
    {
        return $this->execute(function ($redis) use ($callback) {
            if ($this->isPhpRedis) {
                $pipe = $redis->multi(\Redis::PIPELINE);
                try {
                    $callback($pipe);
                    return $pipe->exec();
                } catch (\Throwable $error) {
                    $pipe->discard();
                    throw $error;
                }
            }
    
            // Predis mode
            return $redis->pipeline(function ($pipe) use ($callback) {
                $callback($pipe);
            });
        });
    }
    
        /**
     * Retrieves the TTL (time to live) of a given key in seconds.
     *
     * @param string $key
     * @return int|null Returns TTL in seconds, -1 if key has no TTL, null if key doesn't exist or Redis unavailable.
     */
    public function getTtl(string $key): ?int
    {
        return $this->execute(function ($redis) use ($key) {
            $ttl = $this->isPhpRedis ? $redis->ttl($key) : $redis->ttl($key);

            // PhpRedis & Predis: 
            // -1 = key exists but has no TTL
            // -2 = key does not exist
            return $ttl >= 0 || $ttl === -1 ? $ttl : null;
        });
    }

    /**
     * Sets or updates the TTL of a given key.
     *
     * @param string $key
     * @param int $ttl Time to live in seconds
     * @return bool Returns true on success, false otherwise
     */
    public function expire(string $key, int $ttl): bool
    {
        return (bool) $this->execute(function ($redis) use ($key, $ttl) {
            if ($this->isPhpRedis) {
                return $redis->expire($key, $ttl);
            }
            return $redis->expire($key, $ttl);
        });
    }
}
