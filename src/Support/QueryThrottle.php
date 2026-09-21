<?php

namespace MonitorTrack\Support;

/**
 * At most one query event per key and window on one server. PHP-FPM keeps
 * no state between requests, so the window lives in the mtime of a marker
 * file in the temp dir: an N+1 endpoint at 100 requests/s still sends one
 * event per minute. Without a writable temp dir, 1% of the events are sent.
 */
final class QueryThrottle
{
    private string $dir;

    public function __construct(private int $seconds = 60, ?string $dir = null, private float $fallbackRate = 0.01)
    {
        $this->dir = rtrim($dir ?? sys_get_temp_dir(), '/\\');
    }

    public function allow(string $key): bool
    {
        if ($this->seconds <= 0) {
            return true;
        }

        try {
            $path = $this->path($key);
            clearstatcache(true, $path);
            $mtime = @filemtime($path);
            $now = time();

            if ($mtime !== false && $now - $mtime >= 0 && $now - $mtime < $this->seconds) {
                return false;
            }

            if (@touch($path)) {
                if ($mtime === false) {
                    // FPM and CLI (workers, cron) often run as different users.
                    @chmod($path, 0666);
                }

                return true;
            }
        } catch (\Throwable) {
        }

        return $this->fallbackRate > 0 && mt_rand() / mt_getrandmax() < $this->fallbackRate;
    }

    public function path(string $key): string
    {
        return $this->dir.DIRECTORY_SEPARATOR.'mt-q-'.md5($key);
    }
}
