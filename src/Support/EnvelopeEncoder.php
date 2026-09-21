<?php

namespace MonitorTrack\Support;

/**
 * Encodes an envelope array into exactly one NDJSON line and enforces the
 * size limits of docs/sdk-spec.md §2. The array must already be in wire key
 * order ("_mt" first); context must already be masked.
 */
final class EnvelopeEncoder
{
    public const SOFT_LIMIT = 16384;

    public const HARD_LIMIT = 32768;

    private const FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Returns the line including its trailing "\n", or null when the event
     * cannot be made to fit (the caller counts it as dropped).
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function encode(array $envelope): ?string
    {
        // Step 1: always-on limits.
        $envelope = self::limit($envelope, 8192, 4096, 8192, 50, null);
        $json = self::json($envelope);

        // Step 2: over the soft limit.
        if ($json !== null && strlen($json) + 1 > self::SOFT_LIMIT) {
            $envelope = self::limit($envelope, 2048, 1024, 2048, 50, 256);
            $json = self::json($envelope);
        }

        // Step 3: over the hard limit.
        if ($json !== null && strlen($json) + 1 > self::HARD_LIMIT) {
            if (array_key_exists('context', $envelope)) {
                $envelope['context'] = ['_truncated' => true];
            }
            $envelope = self::limit($envelope, 2048, 1024, 2048, 20, null);
            $json = self::json($envelope);
        }

        // Step 4: give up.
        if ($json === null || strlen($json) + 1 > self::HARD_LIMIT) {
            return null;
        }

        return $json."\n";
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, mixed>
     */
    private static function limit(array $e, int $message, int $excMessage, int $output, int $frames, ?int $contextStrings): array
    {
        if (isset($e['message']) && is_string($e['message'])) {
            $e['message'] = self::head($e['message'], $message);
        }

        if (isset($e['exception']) && is_array($e['exception'])) {
            if (isset($e['exception']['message']) && is_string($e['exception']['message'])) {
                $e['exception']['message'] = self::head($e['exception']['message'], $excMessage);
            }
            if (isset($e['exception']['frames']) && is_array($e['exception']['frames'])
                && count($e['exception']['frames']) > $frames) {
                $e['exception']['frames'] = array_slice($e['exception']['frames'], 0, $frames);
            }
        }

        if (isset($e['query']['frames']) && is_array($e['query']['frames'])
            && count($e['query']['frames']) > $frames) {
            $e['query']['frames'] = array_slice($e['query']['frames'], 0, $frames);
        }

        if (isset($e['cron']['output']) && is_string($e['cron']['output'])) {
            $e['cron']['output'] = self::tail($e['cron']['output'], $output);
        }

        if ($contextStrings !== null && isset($e['context']) && is_array($e['context'])) {
            $e['context'] = self::shortenStrings($e['context'], $contextStrings);
        }

        return $e;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function shortenStrings(array $value, int $max): array
    {
        foreach ($value as $k => $v) {
            if (is_string($v)) {
                $value[$k] = self::head($v, $max);
            } elseif (is_array($v)) {
                $value[$k] = self::shortenStrings($v, $max);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private static function json(array $envelope): ?string
    {
        if (isset($envelope['context']) && is_array($envelope['context'])) {
            // context is always a JSON object, even for list-shaped arrays.
            $envelope['context'] = (object) $envelope['context'];
        }

        $json = json_encode($envelope, self::FLAGS);

        return is_string($json) ? $json : null;
    }

    /**
     * Keeps the first $max bytes (UTF-8 safe) and appends "…".
     */
    public static function head(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return self::cut($s, max(0, $max - 3)).'…';
    }

    /**
     * Keeps the last $max bytes (UTF-8 safe) and prepends "…".
     */
    public static function tail(string $s, int $max): string
    {
        $len = strlen($s);
        if ($len <= $max) {
            return $s;
        }

        $start = $len - max(0, $max - 3);
        while ($start < $len && (ord($s[$start]) & 0xC0) === 0x80) {
            $start++;
        }

        return '…'.substr($s, $start);
    }

    private static function cut(string $s, int $len): string
    {
        if (strlen($s) <= $len) {
            return $s;
        }

        // Byte $len must start a character, not continue one.
        while ($len > 0 && (ord($s[$len]) & 0xC0) === 0x80) {
            $len--;
        }

        return substr($s, 0, $len);
    }
}
