<?php

namespace MonitorTrack;

use MonitorTrack\Support\EnvelopeEncoder;
use MonitorTrack\Support\Masker;
use MonitorTrack\Support\StackTrace;
use MonitorTrack\Transport\FileTransport;
use MonitorTrack\Transport\HttpTransport;
use MonitorTrack\Transport\MemoryTransport;
use MonitorTrack\Transport\NullTransport;
use MonitorTrack\Transport\StreamTransport;
use MonitorTrack\Transport\Transport;

/**
 * Builds monitor-track envelopes (docs/sdk-spec.md) and hands them to a
 * transport. No public method throws: every failure is swallowed and counted
 * in stats().
 */
class Client
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    private const FATAL_CLASSES = [
        'Symfony\Component\ErrorHandler\Error\FatalError',
    ];

    private bool $enabled;

    private Transport $transport;

    private Stats $stats;

    /** @var array{app:string, env:string, release:string, host:string} */
    private array $base;

    private float $sampleRate;

    private int $heartbeatSeconds;

    private string $basePath;

    /** @var (callable(): array<string, string>)|null */
    private $scopeResolver = null;

    /** @var \WeakMap<\Throwable, true> */
    private \WeakMap $captured;

    /** Re-entrancy guard: an event emitted while emitting is ignored. */
    private bool $busy = false;

    /**
     * @param  array<string, mixed>  $options  see normalizeOptions()
     * @param  Stats|null  $stats  counters shared with an injected transport
     */
    public function __construct(array $options = [], ?Transport $transport = null, ?Stats $stats = null)
    {
        $this->stats = $stats ?? new Stats;
        $this->captured = new \WeakMap;

        $o = self::normalizeOptions($options);
        $this->enabled = $o['enabled'];
        $this->sampleRate = $o['sample_rate'];
        $this->heartbeatSeconds = $o['heartbeat_seconds'];
        $this->basePath = $o['base_path'];
        $this->base = [
            'app' => $o['app'],
            'env' => $o['env'],
            'release' => $o['release'],
            'host' => $o['host'],
        ];

        try {
            $this->transport = $transport ?? ($this->enabled ? $this->makeTransport($o) : new NullTransport);
        } catch (\Throwable) {
            $this->stats->errors++;
            $this->transport = new NullTransport;
        }
    }

    /**
     * A client that ignores everything (MT_ENABLED=false or broken config).
     */
    public static function disabled(): self
    {
        return new self(['enabled' => false]);
    }

    /**
     * Accepts raw config/env values and returns typed options.
     *
     * @param  array<string, mixed>  $raw
     * @return array{enabled:bool, transport:string, stream:string, file:string, endpoint:string, token:string,
     *     app:string, env:string, release:string, host:string, sample_rate:float, queue_size:int,
     *     heartbeat_seconds:int, base_path:string, http_connect_timeout_ms:int, http_timeout_ms:int}
     */
    public static function normalizeOptions(array $raw): array
    {
        $str = static fn ($v): string => is_scalar($v) ? trim((string) $v) : '';

        $enabled = $raw['enabled'] ?? true;
        if (! is_bool($enabled)) {
            $enabled = ! in_array(strtolower($str($enabled)), ['false', '0', 'off', 'no', '(false)'], true);
        }

        $transport = strtolower($str($raw['transport'] ?? 'stream'));
        if (! in_array($transport, ['stream', 'file', 'http'], true)) {
            $transport = 'stream';
        }

        $endpoint = $str($raw['endpoint'] ?? '');
        if ($transport === 'http' && $endpoint === '') {
            $transport = 'stream';
        }

        $sample = $raw['sample_rate'] ?? 1.0;
        $sample = is_numeric($sample) ? (float) $sample : 1.0;

        $queue = $raw['queue_size'] ?? 1000;
        $queue = is_numeric($queue) && (int) $queue > 0 ? (int) $queue : 1000;

        $heartbeat = $raw['heartbeat_seconds'] ?? 15;
        $heartbeat = is_numeric($heartbeat) && (int) $heartbeat > 0 ? (int) $heartbeat : 15;

        $host = $str($raw['host'] ?? '');
        if ($host === '') {
            $host = (string) (@gethostname() ?: '');
        }

        return [
            'enabled' => $enabled,
            'transport' => $transport,
            'stream' => strtolower($str($raw['stream'] ?? 'stderr')) === 'stdout' ? 'stdout' : 'stderr',
            'file' => $str($raw['file'] ?? '') ?: 'monitor-track.jsonl',
            'endpoint' => $endpoint,
            'token' => $str($raw['token'] ?? ''),
            'app' => $str($raw['app'] ?? ''),
            'env' => $str($raw['env'] ?? ''),
            'release' => $str($raw['release'] ?? ''),
            'host' => $host,
            'sample_rate' => max(0.0, min(1.0, $sample)),
            'queue_size' => $queue,
            'heartbeat_seconds' => $heartbeat,
            'base_path' => rtrim($str($raw['base_path'] ?? ''), '/\\'),
            'http_connect_timeout_ms' => (int) ($raw['http_connect_timeout_ms'] ?? 500),
            'http_timeout_ms' => (int) ($raw['http_timeout_ms'] ?? 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $o
     */
    private function makeTransport(array $o): Transport
    {
        return match ($o['transport']) {
            'file' => new FileTransport($o['file'], $this->stats),
            'http' => new HttpTransport(
                $o['endpoint'], $o['token'], $o['queue_size'], $this->stats,
                $o['http_connect_timeout_ms'], $o['http_timeout_ms'],
            ),
            default => new StreamTransport('php://'.$o['stream'], $this->stats),
        };
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    public function setTransport(Transport $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Send events as JSON lines to another stream, keeping the counters
     * (`mt:test --pod-log` writes to the container's own stderr).
     *
     * @param  string|resource  $target
     */
    public function streamTo($target): void
    {
        $this->transport = new StreamTransport($target, $this->stats);
    }

    /**
     * Swap the transport for an in-memory one and enable the client
     * (application tests: MonitorTrack::fake()->events('job')).
     */
    public function fake(): MemoryTransport
    {
        $memory = new MemoryTransport;
        $this->transport = $memory;
        $this->enabled = true;

        return $memory;
    }

    public function heartbeatSeconds(): int
    {
        return $this->heartbeatSeconds;
    }

    /**
     * @param  (callable(): array<string, string>)|null  $resolver  returns trace_id / user_id / route
     */
    public function setScopeResolver(?callable $resolver): void
    {
        $this->scopeResolver = $resolver;
    }

    /**
     * @return array{emitted:int,dropped:int,sampled:int,errors:int}
     */
    public function stats(): array
    {
        return $this->stats->toArray();
    }

    /**
     * Send buffered events (http transport). Returns by the timeout even if
     * the server hangs; what is left is dropped and counted.
     */
    public function flush(float $timeout = 2.0): void
    {
        try {
            $this->transport->flush(microtime(true) + max(0.0, $timeout));
        } catch (\Throwable) {
            $this->stats->errors++;
        }
    }

    /**
     * Flush if 2 s passed or 500 events are buffered (queue workers).
     */
    public function flushIfDue(): void
    {
        try {
            $this->transport->flushIfDue();
        } catch (\Throwable) {
            $this->stats->errors++;
        }
    }

    // ---------------------------------------------------------------- events

    /**
     * A log record (type=log), subject to MT_SAMPLE_RATE.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->record('log', $level, $message, ['context' => $context]);
    }

    /**
     * An exception with its stack frames (type=exception). Each Throwable is
     * sent once, even if both the exception handler and a log call see it.
     *
     * @param  array<array-key, mixed>  $context
     */
    public function captureException(\Throwable $e, array $context = [], ?string $level = null): void
    {
        if (! $this->enabled) {
            return;
        }

        try {
            if (isset($this->captured[$e])) {
                return;
            }
            $this->captured[$e] = true;

            if (method_exists($e, 'context')) {
                try {
                    $extra = $e->context();
                    if (is_array($extra)) {
                        $context = $context + $extra;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }

            $class = get_class($e);
            if (str_contains($class, "\0")) {
                $class = 'class@anonymous';
            }

            $level ??= in_array($class, self::FATAL_CLASSES, true) ? 'critical' : 'error';

            $this->record('exception', $level, $class.': '.$e->getMessage(), [
                'context' => $context,
                'exception' => [
                    'class' => $class,
                    'message' => $e->getMessage(),
                    'frames' => StackTrace::frames($e, $this->basePath),
                ],
            ]);
        } catch (\Throwable) {
            $this->stats->errors++;
        }
    }

    public function hasCaptured(\Throwable $e): bool
    {
        return isset($this->captured[$e]);
    }

    /**
     * A queue job phase (type=job).
     *
     * @param  string  $status  start | done | failed | retry
     * @param  array{queue?:string, connection?:string, class?:string, id?:string, runtime_ms?:int, attempts?:int, timeout_ms?:int}  $job
     * @param  array<array-key, mixed>  $context
     */
    public function job(string $status, array $job, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $class = (string) ($job['class'] ?? '') ?: 'unknown';
        $runtime = (int) ($job['runtime_ms'] ?? 0);

        [$level, $message] = match ($status) {
            'start' => ['info', "Job started: {$class}"],
            'done' => ['info', "Job done: {$class} ({$runtime}ms)"],
            'failed' => ['error', "Job failed: {$class} ({$runtime}ms)"],
            'retry' => ['warning', "Job released for retry: {$class} ({$runtime}ms)"],
            default => ['info', "Job {$status}: {$class}"],
        };

        $payload = [
            'queue' => $job['queue'] ?? null,
            'connection' => $job['connection'] ?? null,
            'class' => $class,
            // Pass the same id to start and done; without one each event gets
            // a fresh random id and the backend cannot pair them.
            'id' => isset($job['id']) && $job['id'] !== '' ? (string) $job['id'] : $this->randomId(),
            'status' => $status,
            'runtime_ms' => $status === 'start' ? null : $runtime,
            'attempts' => max(1, (int) ($job['attempts'] ?? 1)),
            'timeout_ms' => isset($job['timeout_ms']) ? (int) $job['timeout_ms'] : null,
        ];

        $this->record('job', $level, $message, ['context' => $context, 'job' => $payload]);
    }

    /**
     * A scheduled task phase (type=cron).
     *
     * @param  string  $phase  start | success | fail | skip
     * @param  array{name?:string, expr?:string, tz?:string, exit_code?:int|null, duration_ms?:int|null, output?:string|null}  $cron
     * @param  array<array-key, mixed>  $context
     */
    public function cron(string $phase, array $cron, array $context = []): void
    {
        if (! $this->enabled) {
            return;
        }

        $name = (string) ($cron['name'] ?? '') ?: 'unknown';
        $finish = $phase === 'success' || $phase === 'fail';
        $exit = $finish ? (int) ($cron['exit_code'] ?? ($phase === 'fail' ? 1 : 0)) : null;
        $duration = $finish && isset($cron['duration_ms']) ? (int) $cron['duration_ms'] : null;
        $durationText = $duration === null ? '' : "{$duration}ms";

        [$level, $message] = match ($phase) {
            'start' => ['info', "Scheduled task started: {$name}"],
            'success' => ['info', "Scheduled task succeeded: {$name}".($duration === null ? '' : " ({$durationText})")],
            'fail' => ['error', "Scheduled task failed: {$name} (exit {$exit}".($duration === null ? '' : ", {$durationText}").')'],
            'skip' => ['info', "Scheduled task skipped: {$name}"],
            default => ['info', "Scheduled task {$phase}: {$name}"],
        };

        $payload = [
            'name' => $name,
            'expr' => $cron['expr'] ?? null,
            'tz' => $cron['tz'] ?? null,
            'phase' => $phase,
            'exit_code' => $exit,
            'duration_ms' => $duration,
            'output' => $phase === 'fail' ? ($cron['output'] ?? null) : null,
        ];

        $this->record('cron', $level, $message, ['context' => $context, 'cron' => $payload]);
    }

    /**
     * Worker liveness (type=heartbeat).
     *
     * @param  array{queues?:list<string>, state?:string, job?:string|null, job_started_at?:string|null, memory_mb?:float, processed?:int}  $heartbeat
     */
    public function heartbeat(array $heartbeat): void
    {
        if (! $this->enabled) {
            return;
        }

        $state = ($heartbeat['state'] ?? 'idle') === 'busy' ? 'busy' : 'idle';

        $payload = [
            'queues' => array_values(array_map('strval', $heartbeat['queues'] ?? [])),
            'state' => $state,
            'job' => $state === 'busy' ? ($heartbeat['job'] ?? null) : null,
            'job_started_at' => $state === 'busy' ? ($heartbeat['job_started_at'] ?? null) : null,
            'memory_mb' => isset($heartbeat['memory_mb'])
                ? (float) $heartbeat['memory_mb']
                : round(memory_get_usage(true) / 1048576, 1),
            'processed' => (int) ($heartbeat['processed'] ?? 0),
        ];

        $this->record('heartbeat', 'debug', "Worker heartbeat: {$state}", ['heartbeat' => $payload]);
    }

    /**
     * Runs $fn as a tracked job outside Laravel's queue (start, then done or
     * failed with runtime). An exception from $fn is captured and rethrown
     * unchanged: the SDK observes, it does not alter control flow.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  array{connection?:string, id?:string, attempts?:int, timeout_ms?:int}  $options
     * @return T
     */
    public function trackJob(string $queue, string $class, callable $fn, array $options = []): mixed
    {
        $job = ['queue' => $queue, 'class' => $class] + $options;
        $job['id'] = (string) ($job['id'] ?? '') ?: $this->randomId();
        $this->job('start', $job);
        $start = hrtime(true);

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->job('failed', $job + ['runtime_ms' => self::elapsedMs($start)]);
            $this->captureException($e);

            throw $e;
        }

        $this->job('done', $job + ['runtime_ms' => self::elapsedMs($start)]);

        return $result;
    }

    /**
     * Runs $fn as a tracked scheduled task (start, then success or fail with
     * duration). Use it for cron entries that don't go through Laravel's
     * scheduler. Exceptions are captured and rethrown unchanged.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function trackCron(string $name, ?string $expr, callable $fn, ?string $tz = null): mixed
    {
        $cron = ['name' => $name, 'expr' => $expr, 'tz' => $tz ?? date_default_timezone_get()];
        $this->cron('start', $cron);
        $start = hrtime(true);

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->cron('fail', $cron + [
                'exit_code' => 1,
                'duration_ms' => self::elapsedMs($start),
                'output' => get_class($e).': '.$e->getMessage(),
            ]);
            $this->captureException($e);

            throw $e;
        }

        $this->cron('success', $cron + ['exit_code' => 0, 'duration_ms' => self::elapsedMs($start)]);

        return $result;
    }

    public static function elapsedMs(int|float $hrtimeStart): int
    {
        return (int) round((hrtime(true) - $hrtimeStart) / 1e6);
    }

    private function randomId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return md5(uniqid('', true));
        }
    }

    /**
     * Low-level: build, encode and send one envelope.
     *
     * @param  array<string, mixed>  $fields  context, exception, job, cron, heartbeat, trace_id, user_id, route
     */
    public function record(string $type, string $level, string $message, array $fields = []): void
    {
        if (! $this->enabled || $this->busy) {
            return;
        }

        $this->busy = true;

        try {
            if ($type === 'log' && $this->sampleRate < 1.0
                && ($this->sampleRate <= 0.0 || mt_rand() / mt_getrandmax() >= $this->sampleRate)) {
                $this->stats->sampled++;

                return;
            }

            $line = EnvelopeEncoder::encode($this->envelope($type, $level, $message, $fields));

            if ($line === null) {
                $this->stats->dropped++;

                return;
            }

            if ($this->transport->send($line)) {
                $this->stats->emitted++;
            } else {
                $this->stats->dropped++;
            }
        } catch (\Throwable) {
            $this->stats->errors++;
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function envelope(string $type, string $level, string $message, array $fields): array
    {
        $e = [
            '_mt' => 1,
            'type' => $type,
            'ts' => self::timestamp(),
            'level' => self::normalizeLevel($level),
            'message' => $message,
        ];

        foreach ($this->base as $key => $value) {
            if ($value !== '') {
                $e[$key] = $value;
            }
        }

        $e['pid'] = (int) getmypid();

        $scope = [];
        if ($this->scopeResolver !== null) {
            try {
                $scope = (array) ($this->scopeResolver)();
            } catch (\Throwable) {
                $this->stats->errors++;
            }
        }

        foreach (['trace_id', 'user_id', 'route'] as $key) {
            $value = $fields[$key] ?? $scope[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                $e[$key] = (string) $value;
            }
        }

        if (! empty($fields['context']) && is_array($fields['context'])) {
            $context = Masker::context($fields['context']);
            if ($context !== []) {
                $e['context'] = $context;
            }
        }

        foreach (['exception', 'job', 'cron', 'heartbeat'] as $key) {
            if ($key === $type && isset($fields[$key]) && is_array($fields[$key])) {
                $e[$key] = self::withoutEmpty($fields[$key]);
            }
        }

        return $e;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withoutEmpty(array $payload): array
    {
        return array_filter($payload, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    public static function timestamp(?float $microtime = null): string
    {
        $dt = $microtime === null
            ? new \DateTimeImmutable('now')
            : (\DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $microtime)) ?: new \DateTimeImmutable('now'));

        if ($microtime !== null) {
            $dt = $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        }

        return $dt->format('Y-m-d\TH:i:s.vP');
    }

    /**
     * Maps PSR-3 / Monolog level names to the wire levels.
     */
    public static function normalizeLevel(string $level): string
    {
        $level = strtolower($level);

        return match ($level) {
            'debug', 'info', 'notice', 'error', 'critical' => $level,
            'warning', 'warn' => 'warning',
            'alert', 'emergency', 'fatal' => 'critical',
            default => 'info',
        };
    }
}
