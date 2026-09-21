<?php

namespace MonitorTrack\Transport;

use MonitorTrack\Stats;

/**
 * Appends one line per event to a local .jsonl file (VMs; Vector or Fluent
 * Bit ships it). The file is opened per write so log rotation just works, and
 * an exclusive lock keeps lines from concurrent FPM workers whole.
 */
final class FileTransport implements Transport
{
    private float $retryAt = 0.0;

    public function __construct(private string $path, private Stats $stats)
    {
    }

    public function send(string $line): bool
    {
        if (microtime(true) < $this->retryAt) {
            return false;
        }

        try {
            $handle = @fopen($this->path, 'ab');

            if ($handle === false) {
                $dir = dirname($this->path);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $handle = @fopen($this->path, 'ab');
            }

            if ($handle === false) {
                $this->stats->errors++;
                $this->retryAt = microtime(true) + 5.0;

                return false;
            }

            try {
                @flock($handle, LOCK_EX);
                $written = @fwrite($handle, $line);
                @flock($handle, LOCK_UN);
            } finally {
                @fclose($handle);
            }

            if ($written !== strlen($line)) {
                $this->stats->errors++;

                return false;
            }

            return true;
        } catch (\Throwable) {
            $this->stats->errors++;

            return false;
        }
    }

    public function flush(?float $deadline = null): void
    {
    }

    public function flushIfDue(): void
    {
    }

    public function pending(): int
    {
        return 0;
    }

    public function describe(): string
    {
        return 'file '.$this->path;
    }
}
