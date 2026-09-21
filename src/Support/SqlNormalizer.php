<?php

namespace MonitorTrack\Support;

/**
 * Reduces a SQL statement to its shape. The result is what the N+1 counter
 * groups by and the only SQL that is sent: bindings are never read, and
 * values written into raw SQL are replaced too.
 *
 *   select * from `users` where `id` in (?, ?, ?)   →  select * from `users` where `id` in (?)
 *   update users set name = 'Ann', age = 42         →  update users set name = ?, age = ?
 */
final class SqlNormalizer
{
    public const MAX_LENGTH = 2000;

    /** Longer statements (bulk inserts, huge IN lists) are cut before normalizing. */
    private const MAX_INPUT = 16384;

    /**
     * Quoted identifiers are skipped; string literals (an unterminated one
     * runs to the end), hex and decimal numbers become "?". Digits that are
     * part of a name (`t1`, `order_2024`, `$1`) are left alone.
     */
    private const LITERALS = <<<'RE'
        /
            `(?:[^`]++|``)*+`? (*SKIP)(*FAIL)
          | %s
          | '(?:[^'\\]++|\\.|'')*+(?:'|$)
          | (?<![\w$])(?:0x[0-9a-f]++|(?:\d++(?:\.\d*+)?|\.\d++)(?:e[+-]?\d++)?)(?![\w$])
        /xis
        RE;

    /** ANSI identifier (pgsql, sqlite, sqlsrv). */
    private const DOUBLE_QUOTED_IDENTIFIER = '"(?:[^"]++|"")*+"? (*SKIP)(*FAIL)';

    /** String literal (mysql, mariadb). */
    private const DOUBLE_QUOTED_STRING = '"(?:[^"\\\\]++|\\\\.|"")*+(?:"|$)';

    /**
     * @param  bool  $doubleQuotedStrings  "…" is a string literal (MySQL), not an identifier
     */
    public static function normalize(string $sql, bool $doubleQuotedStrings = false): string
    {
        $sql = substr($sql, 0, self::MAX_INPUT);

        $pattern = sprintf(self::LITERALS, $doubleQuotedStrings ? self::DOUBLE_QUOTED_STRING : self::DOUBLE_QUOTED_IDENTIFIER);
        $out = preg_replace($pattern, '?', $sql);

        // in (?, ?, ?) → in (?); so is a list that was cut off at the end.
        $out = is_string($out) ? preg_replace('/\b(in)\s*+\(\s*+\?(?:\s*+,\s*+\?)*+(?:\s*+\)|\s*+,?\s*+$)/i', '$1 (?)', $out) : null;
        $out = is_string($out) ? preg_replace('/\s++/', ' ', $out) : null;

        if (! is_string($out)) {
            // A regex limit was hit: keep only the statement's first word
            // rather than risk sending values.
            return strtolower((string) strtok(trim($sql), " \t\r\n(")).' …';
        }

        return EnvelopeEncoder::head(trim($out), self::MAX_LENGTH);
    }
}
