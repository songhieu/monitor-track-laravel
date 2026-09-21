<?php

namespace MonitorTrack\Transport;

/**
 * Used when MT_ENABLED=false or the client could not be configured.
 */
final class NullTransport implements Transport
{
    public function send(string $line): bool
    {
        return false;
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
        return 'disabled';
    }
}
