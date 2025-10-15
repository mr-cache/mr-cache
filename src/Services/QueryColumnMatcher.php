<?php

declare(strict_types=1);

namespace MrCache\Services;

/**
 * @version: 4.x
 */
final class QueryColumnMatcher
{
    public function matches(string $sql, array $cols): bool
    {
        $where = $this->extractWhereClause($sql);
        if ($where === null) {
            return false;
        }

        $where = $this->stripOuterParentheses($where);

        $parts = $this->splitTopLevelAnd($where);
        
        
        if ($parts === null) {
            return false;
        }

        $parts = array_values(array_filter(array_map('trim', $parts), static fn($p) => $p !== ''));

        if (count($parts) !== count($cols)) {
            return false;
        }

        $parsedCols = [];
        foreach ($parts as $part) {
            $col = $this->parseConditionAndGetColumn($part);
            if ($col === null) return false;
            $parsedCols[] = strtolower($col);
        }

        $expected = array_map(static fn($c) => strtolower((string)$c), $cols);
        return $parsedCols === $expected;
    }
    
    private function extractWhereClause(string $sql): ?string 
    {
        if (!preg_match('/\bWHERE\b(.*?)(?:\bORDER\b|\bGROUP\b|\bLIMIT\b|;|$)/is', $sql, $m)) {
            return null;
        }
        
        return trim($m[1]);
    }
    
    private function stripOuterParentheses(string $where): string 
    {
        $where = trim($where);
        $len = strlen($where);
        
        if ($len >= 2 && $where[0] === '(' && $where[$len - 1] === ')') {
            $depth = 0;
            for ($i = 0; $i < $len; $i++) {
                $ch = $where[$i];
                if ($ch === '(') $depth++;
                elseif ($ch === ')') $depth--;
                if ($i === $len - 1 && $depth === 0) {
                    return trim(substr($where, 1, -1));
                }
            }
        }
        
        return $where;
    }
    
    private function splitTopLevelAnd(string $where): ?array
    {
        $len = strlen($where);
        $buf = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $parts = [];
        $i = 0;

        while ($i < $len) {
            $ch = $where[$i];

            if ($ch === "'" && !$inDouble) {
                $prev = $i > 0 ? $where[$i - 1] : null;
                if ($prev !== '\\') $inSingle = !$inSingle;
                $buf .= $ch; $i++; continue;
            }
            if ($ch === '"' && !$inSingle) {
                $prev = $i > 0 ? $where[$i - 1] : null;
                if ($prev !== '\\') $inDouble = !$inDouble;
                $buf .= $ch; $i++; continue;
            }

            if (!$inSingle && !$inDouble) {
                if ($ch === '(') { $depth++; $buf .= $ch; $i++; continue; }
                if ($ch === ')') { $depth = max(0, $depth - 1); $buf .= $ch; $i++; continue; }

                if ($depth === 0) {
                    $rest = substr($where, $i);
                    if (preg_match('/\AOR\b/i', $rest)) return null;

                    if (preg_match('/\AAND\b/i', $rest)) {
                        $afterIndex = $i + 3;
                        while ($afterIndex < $len && ctype_space($where[$afterIndex])) $afterIndex++;
                        $afterChar = $afterIndex < $len ? $where[$afterIndex] : '';

                        if ($afterChar !== '' && preg_match('/["\']|\d/', $afterChar)) {
                            $buf .= 'AND';
                            $i += 3;
                            continue;
                        }

                        $parts[] = trim($buf);
                        $buf = '';
                        $i += 3;
                        while ($i < $len && ctype_space($where[$i])) $i++;
                        continue;
                    }
                }
            }

            $buf .= $ch;
            $i++;
        }

        if (trim($buf) !== '') $parts[] = trim($buf);
        return $parts;
    }
    
    private function parseConditionAndGetColumn(string $cond): ?string 
    {
        $p = trim($cond);

        if (preg_match('/\b(<>|!=|<|>|<=|>=)\b/i', $p)) {
            return null;
        }

        // IN(...) pattern
        if (preg_match('/^\s*(?:`?[\w]+`?\.)?`?([\w]+)`?\s+IN\s*\((.*?)\)\s*$/is', $p, $mm)) {
            $col = $mm[1];
            $list = trim($mm[2]);
            $vals = preg_split('/\s*,\s*/', $list);
            $norm = [];
            foreach ($vals as $v) {
                $v = $this->stripQuotes(trim($v));
                $norm[] = $v;
            }
            $unique = array_values(array_unique($norm, SORT_REGULAR));
            if (count($unique) !== 1) return null;
            return $col;
        }

        // BETWEEN x AND y
        if (preg_match('/^\s*(?:`?[\w]+`?\.)?`?([\w]+)`?\s+BETWEEN\s+(.+?)\s+AND\s+(.+)$/is', $p, $mm)) {
            $col = $mm[1];
            $a = rtrim(trim($mm[2]), " );\t\n\r");
            $b = rtrim(trim($mm[3]), " );\t\n\r");
            $a = $this->stripQuotes($a);
            $b = $this->stripQuotes($b);
            if ($a !== $b) return null;
            return $col;
        }

        // equality: col = value
        if (preg_match('/^\s*(?:`?[\w]+`?\.)?`?([\w]+)`?\s*=\s*(.+)$/is', $p, $mm)) {
            $col = $mm[1];
            return $col;
        }

        return null;
    }
    
    private function stripQuotes(string $s): string 
    {
        $s = trim($s);
        if (strlen($s) >= 2) {
            $first = $s[0];
            $last = $s[strlen($s) - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return substr($s, 1, -1);
            }
        }
        return $s;
    }
}