<?php

declare(strict_types=1);

namespace MrCache\Services;

/** Identifiers only. Aliases/expressions require a conservative global fallback. */
final class TableNames
{
    public static function normalize(string $table): string
    {
        $table = str_replace(['`', '"', '[', ']'], '', $table);
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_$]*(?:\.[A-Za-z_][A-Za-z0-9_$]*)*$/D', $table)) {
            throw new \InvalidArgumentException('MrCache dependency must be a simple table identifier.');
        }
        return strtolower($table);
    }

    public static function list(array $tables): array
    {
        if (count($tables) > 32) {
            throw new \InvalidArgumentException('MrCache supports at most 32 explicit dependencies.');
        }
        $names = [];
        foreach ($tables as $table) {
            if (!is_string($table)) {
                throw new \InvalidArgumentException('MrCache table dependencies must be strings.');
            }
            $names[] = self::normalize($table);
        }
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);
        return $names;
    }

    public static function mutation(string $sql): ?string
    {
        $part = '(?:"[A-Za-z_][A-Za-z0-9_$]*"|`[A-Za-z_][A-Za-z0-9_$]*`|\[[A-Za-z_][A-Za-z0-9_$]*\]|[A-Za-z_][A-Za-z0-9_$]*)';
        if (preg_match('/^\s*(?:insert(?:\s+or\s+\w+)?\s+into|replace\s+into|update|delete\s+from)\s+('.$part.'(?:\.'.$part.')*)(?=\s|\()/i', $sql, $match)) {
            return self::normalize($match[1]);
        }
        return null;
    }
}
