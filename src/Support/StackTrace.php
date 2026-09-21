<?php

namespace MonitorTrack\Support;

/**
 * Converts a Throwable into wire frames: innermost first, paths relative to
 * the app base path, in_app = not vendor/.
 */
final class StackTrace
{
    public const MAX_FRAMES = 50;

    private const MAX_STRING = 512;

    /**
     * @return list<array{file:string, line:int, func:string, in_app:bool}>
     */
    public static function frames(\Throwable $e, string $basePath, int $max = self::MAX_FRAMES): array
    {
        $basePath = rtrim($basePath, '/\\');
        $trace = $e->getTrace();

        // PHP's trace entry i holds the call site of function i. The function
        // executing at a file:line is therefore the one from entry i-1's
        // point of view: shift by one so each frame pairs file:line with the
        // function that was running there.
        $raw = [[
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'func' => isset($trace[0]) ? self::func($trace[0]) : '{main}',
        ]];

        foreach ($trace as $i => $entry) {
            $raw[] = [
                'file' => $entry['file'] ?? '',
                'line' => (int) ($entry['line'] ?? 0),
                'func' => isset($trace[$i + 1]) ? self::func($trace[$i + 1]) : '{main}',
            ];
        }

        $frames = [];
        $sdkDir = dirname(__DIR__);
        foreach ($raw as $f) {
            $frames[] = [
                'file' => $f['file'],
                'line' => $f['line'],
                'func' => $f['func'],
                'sdk' => $f['file'] !== '' && str_starts_with($f['file'], $sdkDir.DIRECTORY_SEPARATOR),
            ];
        }

        // Drop the SDK's own frames, unless that would leave nothing (an
        // exception created inside the SDK, e.g. by `artisan mt:test`).
        $withoutSdk = array_values(array_filter($frames, static fn ($f) => ! $f['sdk']));
        if ($withoutSdk !== []) {
            $frames = $withoutSdk;
        }

        $out = [];
        foreach (array_slice($frames, 0, $max) as $f) {
            $out[] = self::frame($f['file'], $f['line'], $f['func'], $basePath);
        }

        return $out;
    }

    /**
     * @return array{file:string, line:int, func:string, in_app:bool}
     */
    private static function frame(string $file, int $line, string $func, string $basePath): array
    {
        if ($file === '') {
            return ['file' => '[internal]', 'line' => 0, 'func' => self::short($func), 'in_app' => false];
        }

        $normalized = str_replace('\\', '/', $file);
        $base = str_replace('\\', '/', $basePath);
        $relative = null;
        if ($base !== '' && str_starts_with($normalized, $base.'/')) {
            $relative = substr($normalized, strlen($base) + 1);
        }

        $inApp = $relative !== null
            && ! str_starts_with($relative, 'vendor/')
            && ! str_contains($relative, '/vendor/')
            && ! str_starts_with($relative, 'node_modules/');

        return [
            'file' => self::short($relative ?? $normalized),
            'line' => $line,
            'func' => self::short($func),
            'in_app' => $inApp,
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function func(array $entry): string
    {
        $function = (string) ($entry['function'] ?? '');

        if (isset($entry['class']) && $entry['class'] !== '') {
            return $entry['class'].($entry['type'] ?? '::').$function;
        }

        return $function;
    }

    private static function short(string $s): string
    {
        // Anonymous class names embed "\0/full/path.php:12$0"; keep "class@anonymous".
        if (str_contains($s, "\0")) {
            $s = (string) preg_replace('/\x00[^$]*\$[0-9a-f]+/', '', $s);
            $s = str_replace("\0", '', $s);
        }

        return EnvelopeEncoder::head($s, self::MAX_STRING);
    }
}
