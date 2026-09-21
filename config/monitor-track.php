<?php

// monitor-track SDK settings. Every value comes from the same MT_* variables
// the Go and Python SDKs use (docs/sdk-spec.md §5).

return [

    // false / 0 / off turns every call into a no-op and registers no listeners.
    'enabled' => env('MT_ENABLED', true),

    // stream (default): one JSON line per event to stderr, read by the collector.
    // file: append to a local .jsonl file (VMs).  http: push to the ingest API.
    'transport' => env('MT_TRANSPORT', 'stream'),

    // stream transport target: stderr | stdout
    'stream' => env('MT_STREAM', 'stderr'),

    // file transport path; empty = storage/logs/monitor-track.jsonl
    'file' => env('MT_FILE'),

    // http transport: base URL (…/api/v1/logs/ingest is appended) and push token.
    'endpoint' => env('MT_ENDPOINT'),
    'token' => env('MT_TOKEN'),

    // Envelope identity. Empty app / env fall back to config('app.name') and
    // the application environment.
    'app' => env('MT_APP'),
    'env' => env('MT_ENV'),
    'release' => env('MT_RELEASE'),

    // 0..1, applied to type=log only. Exceptions, jobs, cron runs and
    // heartbeats are never sampled.
    'sample_rate' => env('MT_SAMPLE_RATE', 1.0),

    // http transport: maximum events buffered per PHP process (drop newest).
    'queue_size' => env('MT_QUEUE_SIZE', 1000),

    // Queue worker heartbeat interval.
    'heartbeat_seconds' => env('MT_HEARTBEAT_SECONDS', 15),

    // Minimum level for the "monitor-track" logging channel.
    'log_level' => env('MT_LOG_LEVEL', 'debug'),

    // Database queries (type=query events). A query at least this slow (ms)
    // is reported; so is a statement run this many times in one request,
    // job, command or scheduled task (N+1). 0 turns either check off.
    'slow_query_ms' => env('MT_SLOW_QUERY_MS', 500),
    'n_plus_one' => env('MT_N_PLUS_ONE', 10),

    // At most one query event per statement and call site per this many
    // seconds on one server (a marker file in the temp dir). 0 = every one.
    'query_throttle_seconds' => env('MT_QUERY_THROTTLE_SECONDS', 60),

    // Automatic integrations.
    'capture' => [
        'exceptions' => true,   // ExceptionHandler::reportable()
        'queue' => true,        // job runs + worker heartbeats
        'schedule' => true,     // scheduled task runs
        'queries' => true,      // slow queries + N+1
    ],

    // http transport timeouts (per request; one quick retry on connection errors).
    'http' => [
        'connect_timeout_ms' => 500,
        'timeout_ms' => 1000,
    ],
];
