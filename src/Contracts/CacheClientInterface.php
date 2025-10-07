<?php

declare(strict_types=1);

namespace MrCache\Contracts;

/**
 * Defines the contract for a direct Redis client.
 * This interface abstracts the underlying Redis driver (ext-redis or Predis),
 * providing a consistent API for the cache manager.
 */
interface CacheClientInterface
{
    /**
     * Get the value of a key.
     *
     * @param string $key
     * @return string|null The value or null if the key does not exist.
     */
    public function get(string $key): ?string;

    /**
     * Set the string value of a key.
     *
     * @param string $key
     * @param string $value
     * @param int|null $ttl Time-to-live in seconds. Null means it persists forever.
     * @return bool
     */
    public function set(string $key, string $value, ?int $ttl = null): bool;

    /**
     * Delete one or more keys.
     *
     * @param string ...$keys
     * @return int The number of keys that were removed.
     */
    public function del(string ...$keys): int;

    /**
     * Add one or more members to a set.
     *
     * @param string $setKey The key of the set.
     * @param string ...$members The members to add.
     * @return int The number of elements that were added to the set.
     */
    public function sAdd(string $setKey, string ...$members): int;

    /**
     * Get all the members in a set.
     *
     * @param string $setKey
     * @return array<string>
     */
    public function sMembers(string $setKey): array;

    /**
     * Remove one or more members from a set.
     *
     * @param string $setKey
     * @param string ...$members
     * @return int The number of members that were removed from the set.
     */
    public function sRem(string $setKey, string ...$members): int;

    /**
     * Increment the integer value of a key by one.
     *
     * @param string $key
     * @return int The value of key after the increment.
     */
    public function incr(string $key): int;

    /**
     * Iterates the keyspace for keys matching a pattern.
     * SHOULD use SCAN instead of KEYS for production safety.
     *
     * @param string $pattern
     * @return \Generator<string>
     */
    public function scanKeys(string $pattern): \Generator;

    /**
     * Execute a Lua script server-side.
     *
     * @param string $script
     * @param array $keys
     * @param array $args
     * @return mixed
     */
    public function eval(string $script, array $keys = [], array $args = []): mixed;

    /**
     * Get information and statistics about the server.
     *
     * @param string|null $section
     * @return array
     */
    public function info(?string $section = null): array;

    /**
     * Execute a callable within a Redis pipeline.
     *
     * @param callable $callback
     * @return array|null The results of the commands in the pipeline.
     */
    public function pipeline(callable $callback): ?array;
}
