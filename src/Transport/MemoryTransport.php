<?php

namespace MonitorTrack\Transport;

/**
 * Keeps lines in memory. Used by MonitorTrack::fake() and the test suite.
 */
final class MemoryTransport implements Transport
{
    /** @var list<string> */
    private array $lines = [];

    public function __construct(private readonly int $max = 10000)
    {
    }

    public function send(string $line): bool
    {
        if (count($this->lines) >= $this->max) {
            return false;
        }

        $this->lines[] = $line;

        return true;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * Decoded envelopes, optionally only those of one type.
     *
     * @return list<array<string, mixed>>
     */
    public function events(?string $type = null): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && ($type === null || ($event['type'] ?? null) === $type)) {
                $out[] = $event;
            }
        }

        return $out;
    }

    public function clear(): void
    {
        $this->lines = [];
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
        return 'memory';
    }
}
