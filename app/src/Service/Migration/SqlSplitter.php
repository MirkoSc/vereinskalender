<?php

declare(strict_types=1);

namespace App\Service\Migration;

/**
 * Splits a SQL script into individual statements.
 *
 * Aware of single/double/backtick quotes (incl. backslash escapes, which
 * do not apply inside backticks), line comments (-- and #) and block
 * comments. DELIMITER and stored routines are deliberately NOT supported
 * in migrations.
 */
final class SqlSplitter
{
    /**
     * @return list<string> non-empty statements, trimmed, without trailing semicolon
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // line comments (until end of line)
            if ($char === '#' || ($char === '-' && $next === '-')) {
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // block comments
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            // quoted strings and identifiers
            if ($char === "'" || $char === '"' || $char === '`') {
                $current .= $char;
                $i++;
                while ($i < $length) {
                    $current .= $sql[$i];
                    if ($sql[$i] === '\\' && $char !== '`' && $i + 1 < $length) {
                        $current .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $char) {
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
