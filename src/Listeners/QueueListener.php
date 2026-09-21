<?php

namespace MonitorTrack\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use MonitorTrack\Client;

/**
 * Queue worker events → job start/done/failed/retry, worker heartbeats and
 * the http flush cadence of long-running workers.
 *
 * Heartbeats come only from a process that runs a worker loop (Looping): a
 * sync job inside a web request or an Octane worker is a job run, but that
 * process is not a queue worker.
 *
 * Laravel's event order per attempt:
 *   success:     JobProcessing → JobProcessed
 *   retry:       JobProcessing → JobExceptionOccurred → JobReleasedAfterException
 *   final fail:  JobProcessing → JobFailed → JobExceptionOccurred
 *   manual fail: JobProcessing → JobFailed → JobProcessed
 * Only the first closing event of an attempt is sent.
 */
final class QueueListener
{
    /** @var array<string, array{start:int|float, job:array<string, mixed>}> */
    private array $running = [];

    /** @var array{class:string, started_at:string}|null */
    private ?array $current = null;

    /** @var list<string> */
    private array $queues = [];

    private int $processed = 0;

    private float $lastHeartbeat = 0.0;

    /** A queue worker loop (Looping) runs in this process. */
    private bool $worker = false;

    public function __construct(private Client $client)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(JobProcessing::class, [$this, 'processing']);
        $events->listen(JobProcessed::class, [$this, 'processed']);
        $events->listen(JobFailed::class, [$this, 'failed']);
        $events->listen(JobExceptionOccurred::class, [$this, 'exceptionOccurred']);
        $events->listen(Looping::class, [$this, 'looping']);
        $events->listen(WorkerStopping::class, [$this, 'stopping']);

        if (class_exists(JobReleasedAfterException::class)) {
            $events->listen(JobReleasedAfterException::class, [$this, 'released']);
        }
        if (class_exists(JobTimedOut::class)) {
            $events->listen(JobTimedOut::class, [$this, 'timedOut']);
        }
    }

    public function processing(JobProcessing $event): void
    {
        try {
            $job = $this->describe($event->job, $event->connectionName);
            if (count($this->running) >= 1000) {
                // Attempts whose closing event never came; don't grow forever.
                array_shift($this->running);
            }
            $this->running[$this->key($event->job)] = ['start' => hrtime(true), 'job' => $job];
            $this->current = ['class' => (string) $job['class'], 'started_at' => Client::timestamp()];
            $this->client->job('start', $job);
            $this->heartbeatIfDue();
        } catch (\Throwable) {
            // never affect the worker
        }
    }

    public function processed(JobProcessed $event): void
    {
        try {
            // A job that called $this->release() itself finishes "processed".
            $status = $event->job->isReleased() ? 'retry' : 'done';
        } catch (\Throwable) {
            $status = 'done';
        }

        $this->close($event->job, $status, $event->connectionName);
    }

    public function failed(JobFailed $event): void
    {
        $this->close($event->job, 'failed', $event->connectionName);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        // The attempt is closed by the JobFailed / JobReleasedAfterException
        // that accompanies this event. If neither follows (the job deleted
        // itself), close it as failed.
        try {
            $job = $event->job;
            if (! $job->hasFailed() && ! $job->isReleased() && $job->isDeleted()) {
                $this->close($job, 'failed', $event->connectionName);
            }
        } catch (\Throwable) {
        }
    }

    public function released(JobReleasedAfterException $event): void
    {
        $this->close($event->job, 'retry', $event->connectionName);
    }

    public function timedOut(JobTimedOut $event): void
    {
        try {
            // The worker kills itself right after this event. A job that was
            // marked failed already closed through JobFailed; otherwise it will
            // be picked up again after retry_after.
            $this->close($event->job, $event->job->hasFailed() ? 'failed' : 'retry', $event->connectionName);
        } catch (\Throwable) {
        }
        $this->client->flush(1.0);
    }

    public function looping(Looping $event): void
    {
        $this->worker = true;

        try {
            $queues = array_values(array_filter(array_map('trim', explode(',', (string) $event->queue))));
            if ($queues !== []) {
                $this->queues = $queues;
            }
            $this->current = null;
            $this->heartbeatIfDue();
        } catch (\Throwable) {
        }

        $this->client->flushIfDue();
    }

    public function stopping(WorkerStopping $event): void
    {
        $this->client->flush(1.0);
    }

    private function heartbeatIfDue(): void
    {
        if (! $this->worker) {
            return;
        }

        $now = microtime(true);
        if ($now - $this->lastHeartbeat < $this->client->heartbeatSeconds()) {
            return;
        }
        $this->lastHeartbeat = $now;

        $busy = $this->current !== null;
        $this->client->heartbeat([
            'queues' => $this->queues,
            'state' => $busy ? 'busy' : 'idle',
            'job' => $this->current['class'] ?? null,
            'job_started_at' => $this->current['started_at'] ?? null,
            'memory_mb' => round(memory_get_usage(true) / 1048576, 1),
            'processed' => $this->processed,
        ]);
    }

    private function close(Job $job, string $status, ?string $connection = null): void
    {
        try {
            $key = $this->key($job);
            $run = $this->running[$key] ?? null;
            if ($run === null) {
                // Closing event without a start in this process (e.g. a job
                // failed by another component): report it once, runtime 0.
                if ($status !== 'failed') {
                    return;
                }
                $run = ['start' => hrtime(true), 'job' => $this->describe($job, $connection)];
            }
            unset($this->running[$key]);

            $this->processed++;
            $this->current = null;
            $this->client->job($status, $run['job'] + ['runtime_ms' => Client::elapsedMs($run['start'])]);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{queue:string|null, connection:string|null, class:string, id:string, attempts:int, timeout_ms:int|null}
     */
    private function describe(Job $job, ?string $connection): array
    {
        $timeout = null;
        try {
            $t = method_exists($job, 'timeout') ? $job->timeout() : ($job->payload()['timeout'] ?? null);
            if (is_numeric($t) && $t > 0) {
                $timeout = (int) round($t * 1000);
            }
        } catch (\Throwable) {
        }

        return [
            'queue' => self::safe(fn () => $job->getQueue()),
            'connection' => $connection ?? self::safe(fn () => $job->getConnectionName()),
            'class' => (string) (self::safe(fn () => $job->resolveName()) ?? 'unknown'),
            'id' => (string) (self::safe(fn () => $job->uuid()) ?? self::safe(fn () => $job->getJobId()) ?? ''),
            'attempts' => (int) (self::safe(fn () => $job->attempts()) ?? 1),
            'timeout_ms' => $timeout,
        ];
    }

    private function key(Job $job): string
    {
        $id = self::safe(fn () => $job->uuid()) ?? self::safe(fn () => $job->getJobId()) ?? spl_object_id($job);

        return $id.'#'.(self::safe(fn () => $job->attempts()) ?? 0);
    }

    private static function safe(callable $fn): ?string
    {
        try {
            $v = $fn();

            return is_scalar($v) && (string) $v !== '' ? (string) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
