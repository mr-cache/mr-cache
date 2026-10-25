<?php

declare(strict_types=1);

namespace MrCache\Services;

use MrCache\Contracts\CacheClientInterface;
use MrCache\Exceptions\RedisConnectionException;

/** Token ownership is checked for both publishing and unlocking after lease expiry. */
final class QueryLock
{
    public function __construct(private readonly CacheClientInterface $client, private readonly string $key, private readonly int $leaseMs)
    {
    }

    public function acquire(): ?string
    {
        $token = bin2hex(random_bytes(16));
        return $this->client->eval("if redis.call('SET', KEYS[1], ARGV[1], 'NX', 'PX', ARGV[2]) then return 1 else return 0 end", [$this->key], [$token, $this->leaseMs]) === 1 ? $token : null;
    }

    public function publish(string $token, string $queryKey, string $payload, int $ttl): bool
    {
        return $this->client->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then redis.call('SETEX', KEYS[2], ARGV[2], ARGV[3]); return 1 else return 0 end", [$this->key, $queryKey], [$token, $ttl, $payload]) === 1;
    }

    public function release(string $token): void
    {
        try {
            $this->client->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end", [$this->key], [$token]);
        } catch (RedisConnectionException) {
            // Best-effort cleanup must not replace a database/application exception.
            // The lease bounds abandoned locks, including during strict-mode failures.
        }
    }
}
