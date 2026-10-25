<?php

declare(strict_types=1);

namespace MrCache\Services;

final class QueryPolicy
{
    public readonly int $ttl;
    public readonly int $maxPayloadBytes;
    public readonly string $consistency;
    public readonly int $refreshAheadSeconds;
    public readonly array $relations;

    public function __construct(array $values)
    {
        $this->ttl = self::integer($values['ttl'] ?? 3600, 0, 2147483647, 'ttl');
        $this->maxPayloadBytes = self::integer($values['max_payload_bytes'] ?? 1048576, 1, 16777216, 'max_payload_bytes');
        $this->refreshAheadSeconds = self::integer($values['refresh_ahead_seconds'] ?? 0, 0, 2147483647, 'refresh_ahead_seconds');
        $this->consistency = $values['consistency'] ?? 'bounded';
        if (!in_array($this->consistency, ['bounded', 'database'], true)) {
            throw new \InvalidArgumentException('MrCache consistency must be bounded or database.');
        }
        if ($this->ttl > 0 && $this->refreshAheadSeconds >= $this->ttl) {
            throw new \InvalidArgumentException('MrCache refresh threshold must be less than TTL.');
        }
        $relations = $values['relations'] ?? [];
        if (!is_array($relations) || count($relations) > 16) {
            throw new \InvalidArgumentException('MrCache relations must be an array of at most 16 names.');
        }
        foreach ($relations as $relation) {
            if (!is_string($relation) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $relation)) {
                throw new \InvalidArgumentException('MrCache supports explicit top-level relation names only.');
            }
        }
        $this->relations = array_values(array_unique($relations));
    }

    public static function integer(mixed $value, int $min, int $max, string $name): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || $value < $min || $value > $max) {
            throw new \InvalidArgumentException("MrCache {$name} must be an integer between {$min} and {$max}.");
        }
        return (int) $value;
    }

    public function toArray(): array
    {
        return ['ttl' => $this->ttl, 'max_payload_bytes' => $this->maxPayloadBytes, 'consistency' => $this->consistency,
            'refresh_ahead_seconds' => $this->refreshAheadSeconds, 'relations' => $this->relations];
    }
}
