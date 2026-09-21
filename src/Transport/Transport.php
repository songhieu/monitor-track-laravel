<?php

namespace MonitorTrack\Transport;

/**
 * A transport receives fully encoded envelope lines (ending in "\n").
 * Implementations must never throw.
 */
interface Transport
{
    /**
     * Write or buffer one line. Returns false when the line was dropped.
     */
    public function send(string $line): bool;

    /**
     * Deliver buffered lines, giving up at the deadline (a microtime(true)
     * value). No-op for unbuffered transports.
     */
    public function flush(?float $deadline = null): void;

    /**
     * Deliver buffered lines if the flush interval elapsed or the batch size
     * is reached (long-running queue workers).
     */
    public function flushIfDue(): void;

    /** Number of buffered lines not yet delivered. */
    public function pending(): int;

    /** Human description of where lines go, e.g. "stream php://stderr". */
    public function describe(): string;
}
