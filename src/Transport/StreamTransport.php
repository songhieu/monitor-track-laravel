<?php

namespace MonitorTrack\Transport;

use MonitorTrack\Stats;

/**
 * Default transport: one fwrite of one line to stderr/stdout. The collector
 * reads container output, so there is no network and no buffering.
 */
final class StreamTransport implements Transport
{
    /** @var resource|null */
    private $handle;

    private string $target;

    private float $retryOpenAt = 0.0;

    /**
     * @param  string|resource  $target  a php:// stream name or an open stream resource
     */
    public function __construct($target, private readonly Stats $stats)
    {
        if (is_resource($target)) {
            $this->handle = $target;
            $this->target = 'resource';
        } else {
            $this->target = (string) $target;
        }
    }

    public function send(string $line): bool
    {
        try {
            if ($this->handle === null && ! $this->open()) {
                return false;
            }

            $written = @fwrite($this->handle, $line);

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

    private function open(): bool
    {
        // Don't retry a failing open on every event.
        if (microtime(true) < $this->retryOpenAt) {
            return false;
        }

        $handle = @fopen($this->target, 'ab');

        if ($handle === false) {
            $this->stats->errors++;
            $this->retryOpenAt = microtime(true) + 5.0;

            return false;
        }

        $this->handle = $handle;

        return true;
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
        return 'stream '.$this->target;
    }
}
