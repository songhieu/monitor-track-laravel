<?php

namespace MonitorTrack\Transport;

/**
 * Local disk spool for http batches the ingest could not take.
 *
 * A batch that fails for a reason a retry can fix (no connection, timeout,
 * 429, 502-504) is written here instead of being dropped, and any process of
 * the same app on the same host sends it again after its own next successful
 * POST. One file is one batch, named "<spooled at, µs>-<idempotency key>.ndjson"
 * so the resend carries the original key and the oldest batch goes first.
 *
 * Processes share the directory without locks: a sender takes a file by
 * renaming it (atomic on one filesystem), so exactly one process sends each
 * batch. A taken file whose process died is taken again after CLAIM_TIMEOUT.
 *
 * Bounded like the rest of the SDK: when the directory holds max_bytes the
 * new batch is dropped, and a batch older than max_age is deleted unsent.
 * Every method swallows its errors; a spool that can't be written simply
 * behaves like no spool (the batch is dropped and counted).
 */
final class Spool
{
    /** Seconds after which a batch taken by another process is taken again. */
    public const CLAIM_TIMEOUT = 120;

    private const NAME = '/^(\d{16})-([0-9a-f]{1,64})\.ndjson(?:\.(\d{13})-[0-9a-f]+\.sending)?$/';

    private ?bool $ready = null;

    public function __construct(
        private string $dir,
        private int $maxBytes = 20 * 1024 * 1024,
        private int $maxAge = 3600,
    ) {
    }

    /**
     * One directory per ingest and token under $base (default: the system
     * temp dir): two apps sharing a host must never send each other's
     * batches with the wrong token.
     */
    public static function forIngest(?string $base, string $endpoint, ?string $token, int $maxBytes, int $maxAge): self
    {
        $base = rtrim((string) $base, '/\\');
        if ($base === '') {
            $base = rtrim(sys_get_temp_dir(), '/\\').'/monitor-track-spool';
        }

        return new self($base.'/'.substr(sha1($endpoint."\n".(string) $token), 0, 16), $maxBytes, $maxAge);
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Stores one batch. False when it could not be kept (spool off or full,
     * disk error): the caller counts it as dropped.
     *
     * @param  list<string>  $lines  encoded lines, each ending in "\n"
     */
    public function put(array $lines, string $key): bool
    {
        try {
            if ($lines === [] || ! $this->ready()) {
                return false;
            }

            $body = implode('', $lines);
            if ($this->bytes() + strlen($body) > $this->maxBytes) {
                return false;
            }

            $key = strtolower($key);
            if (! preg_match('/^[0-9a-f]{1,64}$/', $key)) {
                $key = bin2hex(random_bytes(8));
            }
            $name = sprintf('%016d-%s.ndjson', (int) (microtime(true) * 1_000_000), $key);
            $tmp = $this->dir.'/.'.$name.'.'.bin2hex(random_bytes(4)).'.tmp';

            if (@file_put_contents($tmp, $body) !== strlen($body)) {
                @unlink($tmp);

                return false;
            }
            if (! @rename($tmp, $this->dir.'/'.$name)) {
                @unlink($tmp);

                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Takes the oldest batch for this process: [file, idempotency key, body].
     * Batches past max_age met on the way are deleted, and their line count
     * is added to $expired. Null when there is nothing to send.
     *
     * @return array{0:string, 1:string, 2:string}|null
     */
    public function claim(int &$expired = 0): ?array
    {
        try {
            // Called after every successful send: only a directory that
            // exists is read, nothing is created here.
            $files = @scandir($this->dir);
            if (! is_array($files)) {
                return null;
            }

            $now = microtime(true);
            // scandir sorts by name, and names start with the spool time.
            foreach ($files as $file) {
                if (! preg_match(self::NAME, $file, $m)) {
                    continue;
                }
                $path = $this->dir.'/'.$file;

                if ($now - ((int) $m[1]) / 1_000_000 > $this->maxAge) {
                    $body = @file_get_contents($path);
                    if (@unlink($path) && is_string($body)) {
                        $expired += substr_count($body, "\n");
                    }

                    continue;
                }

                if (isset($m[3]) && $now - ((int) $m[3]) / 1000 < self::CLAIM_TIMEOUT) {
                    continue; // another process is sending it
                }

                $claimed = sprintf('%s/%s-%s.ndjson.%013d-%s.sending',
                    $this->dir, $m[1], $m[2], (int) ($now * 1000), bin2hex(random_bytes(4)));
                if (! @rename($path, $claimed)) {
                    continue; // another process took it first
                }

                $body = @file_get_contents($claimed);
                if (! is_string($body) || $body === '') {
                    @unlink($claimed);

                    continue;
                }

                return [$claimed, $m[2], $body];
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /** The batch was delivered (or refused for good): forget it. */
    public function done(string $claimed): void
    {
        try {
            @unlink($claimed);
        } catch (\Throwable) {
        }
    }

    /** The batch could not be sent: put it back for a later process. */
    public function release(string $claimed): void
    {
        // If the rename fails the file stays taken, and CLAIM_TIMEOUT hands it
        // to another process.
        try {
            $original = preg_replace('/\.\d{13}-[0-9a-f]+\.sending$/', '', $claimed);
            if (is_string($original) && $original !== $claimed) {
                @rename($claimed, $original);
            }
        } catch (\Throwable) {
        }
    }

    /** Number of spooled batches, including ones being sent. */
    public function count(): int
    {
        try {
            $files = @scandir($this->dir);

            return is_array($files) ? count(preg_grep(self::NAME, $files) ?: []) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function bytes(): int
    {
        $total = 0;
        foreach (@scandir($this->dir) ?: [] as $file) {
            if (preg_match(self::NAME, $file)) {
                $total += (int) @filesize($this->dir.'/'.$file);
            }
        }

        return $total;
    }

    /** Creates the directory when the first batch is stored. */
    private function ready(): bool
    {
        if ($this->ready !== null) {
            return $this->ready;
        }

        try {
            if ($this->maxBytes <= 0 || $this->maxAge <= 0) {
                return $this->ready = false;
            }
            if (! is_dir($this->dir)) {
                @mkdir($this->dir, 0700, true);
            }
            if (! is_dir($this->dir) || is_link($this->dir) || ! is_writable($this->dir)) {
                return $this->ready = false;
            }
            // The default lives in a shared temp dir: write only into a
            // directory this process owns, never one someone else prepared.
            if (function_exists('posix_geteuid') && @fileowner($this->dir) !== posix_geteuid()) {
                return $this->ready = false;
            }

            return $this->ready = true;
        } catch (\Throwable) {
            return $this->ready = false;
        }
    }
}
