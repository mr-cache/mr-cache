<?php

declare(strict_types=1);

namespace MrCache\Services;

use MrCache\Contracts\CacheClientInterface;

/** @internal Random tokens prevent ABA after metadata eviction. All reads include the global epoch. */
final class Generation
{
    public function __construct(private readonly CacheClientInterface $client, private readonly array $config)
    {
    }

    private function metadataTtl(): int
    {
        return QueryPolicy::integer($this->config['invalidation']['metadata_ttl'] ?? 86400, 1, 2147483647, 'metadata_ttl');
    }

    private function keys(array $tables): array
    {
        $prefix = ($this->config['prefix'] ?? 'mrcache').':v2:';
        return array_merge([$prefix.'generation'], array_map(fn ($table) => $prefix.'table:'.hash('sha256', $table), TableNames::list($tables)));
    }

    public function current(array $tables = []): ?string
    {
        $keys = $this->keys($tables);
        $tokens = $this->client->eval(
            "local v = {}; for i,k in ipairs(KEYS) do local t = redis.call('GET', k); if not t then t = ARGV[i]; redis.call('SET', k, t); end; if i > 1 then redis.call('EXPIRE', k, ARGV[#KEYS + 1]); end; v[i] = t; end; return v",
            $keys, array_merge(array_map(fn () => bin2hex(random_bytes(16)), $keys), [$this->metadataTtl()])
        );
        if (!is_array($tokens) || count($tokens) !== count($keys)) {
            return null;
        }
        foreach ($tokens as $token) {
            if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
                return null;
            }
        }
        return count($tokens) === 1 ? $tokens[0] : hash('sha256', serialize($tokens));
    }

    public function rotate(): bool
    {
        return $this->client->set($this->keys([])[0], bin2hex(random_bytes(16)));
    }

    public function rotateTables(array $tables): bool
    {
        if ($tables === []) {
            return $this->rotate();
        }
        $keys = array_slice($this->keys($tables), 1);
        return $this->client->eval("for i,k in ipairs(KEYS) do redis.call('SET', k, ARGV[i], 'EX', ARGV[#KEYS + 1]); end; return 1", $keys,
            array_merge(array_map(fn () => bin2hex(random_bytes(16)), $keys), [$this->metadataTtl()])) === 1;
    }
}
