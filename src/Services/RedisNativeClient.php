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
                $this->redis->connect(
                    $connectionConfig['host'],
                    (int) $connectionConfig['port'],
                    (float) $connectionConfig['timeout']
                );

                if (!empty($connectionConfig['password'])) {
                    $this->redis->auth($connectionConfig['password']);
                }

                $this->redis->select((int) $connectionConfig['database']);
                $this->isPhpRedis = true;
            } elseif (class_exists(PredisClient::class)) {
                $this->redis = new PredisClient([
                    'scheme' => 'tcp',
                    'host'   => $connectionConfig['host'],
                    'port'   => $connectionConfig['port'],
                    'timeout' => $connectionConfig['timeout'],
                    'database' => $connectionConfig['database'],
                    ...($connectionConfig['password'] ? ['password' => $connectionConfig['password']] : []),
                ]);
                $this->redis->connect();
                $this->isPhpRedis = false;
            }
        } catch (\Throwable $e) {
            $this->redis = null;
            if ($this->config['strict_mode']) {
                throw new RedisConnectionException('MrCache: Failed to connect to Redis. ' . $e->getMessage(), 0, $e);
            }
            Log::warning('MrCache: Failed to connect to Redis. Caching is disabled.', ['exception' => $e]);
        }
    }

    private function execute(callable $callback)
    {
        if (!$this->redis) {
            return null;
        }

        try {
            return $callback($this->redis);
        } catch (\Throwable $e) {
            if ($this->config['strict_mode']) {
                throw new RedisConnectionException('MrCache: Redis command failed. ' . $e->getMessage(), 0, $e);
            }
            Log::warning('MrCache: Redis command failed.', ['exception' => $e]);
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
        if (!$this->redis) {
            yield from [];
            return;
        }

        if ($this->isPhpRedis) {
            $iterator = null;
            while ($keys = $this->redis->scan($iterator, $pattern)) {
                foreach ($keys as $key) {
                    yield $key;
                }
            }
        } else {
            // Predis SCAN returns an iterator
            foreach ($this->redis->scan('MATCH', $pattern) as $key) {
                yield $key;
            }
        }
    }

    public function eval(string $script, array $keys = [], array $args = []): mixed
    {
        return $this->execute(fn($redis) => $redis->eval($script, array_merge($keys, $args), count($keys)));
    }

    public function info(?string $section = null): array
    {
        return (array) $this->execute(fn($redis) => $redis->info($section));
    }

    public function pipeline(callable $callback): ?array
    {
        return $this->execute(function ($redis) use ($callback) {
            $pipe = $this->isPhpRedis ? $redis->multi(\Redis::PIPELINE) : $redis->pipeline();
            $callback($pipe);
            return $pipe->exec();
        });
    }
}
