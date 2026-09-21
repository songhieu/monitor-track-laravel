<?php

namespace MonitorTrack\Support;

/**
 * Turns arbitrary context into JSON-safe data and masks secrets
 * (docs/sdk-spec.md §3).
 */
final class Masker
{
    public const MAX_DEPTH = 5;

    /** Items kept per array/object, so a huge collection can't stall a request. */
    public const MAX_ITEMS = 100;

    private const SENSITIVE = [
        'password', 'passwd', 'secret', 'token', 'authorization',
        'api_key', 'apikey', 'cookie', 'card', 'cvv',
    ];

    public static function isSensitive(string|int $key): bool
    {
        $key = str_replace('-', '_', strtolower((string) $key));

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public static function context(array $context): array
    {
        $out = self::walk($context, 1);

        return is_array($out) ? $out : [];
    }

    private static function walk(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_array($value)) {
            if ($depth > self::MAX_DEPTH) {
                return '[depth]';
            }

            $out = [];
            $n = 0;
            foreach ($value as $k => $v) {
                if (++$n > self::MAX_ITEMS) {
                    break;
                }
                $out[$k] = self::isSensitive($k) ? '***' : self::walk($v, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            return self::object($value, $depth);
        }

        if (is_resource($value)) {
            return '[resource '.get_resource_type($value).']';
        }

        return get_debug_type($value);
    }

    private static function object(object $value, int $depth): mixed
    {
        try {
            if ($value instanceof \Throwable) {
                return get_class($value).': '.$value->getMessage();
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d\TH:i:s.vP');
            }

            if ($value instanceof \BackedEnum) {
                return $value->value;
            }

            if ($value instanceof \UnitEnum) {
                return $value->name;
            }

            if ($depth > self::MAX_DEPTH) {
                return '[depth]';
            }

            if ($value instanceof \JsonSerializable) {
                return self::walk($value->jsonSerialize(), $depth);
            }

            if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
                return self::walk($value->toArray(), $depth);
            }

            if ($value instanceof \Stringable || method_exists($value, '__toString')) {
                return (string) $value;
            }

            if ($value instanceof \stdClass) {
                return self::walk(get_object_vars($value), $depth);
            }
        } catch (\Throwable) {
            // fall through to the class name
        }

        return '['.get_class($value).']';
    }
}
