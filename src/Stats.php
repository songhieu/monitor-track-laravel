<?php

namespace MonitorTrack;

/**
 * Counters shared by the client and its transport (docs/sdk-spec.md §6).
 */
final class Stats
{
    /** Events written, or accepted into the http buffer. */
    public int $emitted = 0;

    /** Events lost: buffer full, line too large, failed http batch, write error. */
    public int $dropped = 0;

    /** Log events skipped by MT_SAMPLE_RATE. */
    public int $sampled = 0;

    /** Internal errors the SDK swallowed. */
    public int $errors = 0;

    /**
     * @return array{emitted:int,dropped:int,sampled:int,errors:int}
     */
    public function toArray(): array
    {
        return [
            'emitted' => $this->emitted,
            'dropped' => $this->dropped,
            'sampled' => $this->sampled,
            'errors' => $this->errors,
        ];
    }
}
