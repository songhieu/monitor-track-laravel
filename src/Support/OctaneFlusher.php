<?php

namespace MonitorTrack\Support;

use MonitorTrack\Client;

/**
 * http transport cadence in a Laravel Octane worker, which serves many
 * requests from one process: after a request the buffer is sent only when
 * due (2 s since the last send or 500 events), and what is left goes when
 * the worker stops (WorkerStopping). In a Swoole HTTP worker a one-shot timer
 * sends a buffer that no later request would: it fires between requests,
 * never during one. Octane is not a dependency: its classes are only named.
 *
 * @internal
 */
final class OctaneFlusher
{
    /** The flush interval (2 s) and a little slack. */
    private const TIMER_MS = 2050;

    /** @var (callable(int, callable): mixed)|null schedules a one-shot timer, returns its id */
    private $after;

    /** @var (callable(int): mixed)|null cancels a timer */
    private $clear;

    private ?int $timer = null;

    /**
     * @param  (callable(int, callable): mixed)|null  $after  Swoole\Timer::after; null = no timer
     * @param  (callable(int): mixed)|null  $clear  Swoole\Timer::clear
     */
    public function __construct(private Client $client, ?callable $after = null, ?callable $clear = null)
    {
        $this->after = $after;
        $this->clear = $clear;
    }

    /**
     * Whether this process is an Octane worker. Octane starts its server with
     * LARAVEL_OCTANE=1, its worker bootstrap sets APP_RUNNING_IN_CONSOLE=false,
     * and each worker's application has Octane's client bound. A `php artisan`
     * started from a request inherits the variable but runs in the console.
     *
     * @param  object  $app  the application
     */
    public static function running(object $app): bool
    {
        try {
            if (method_exists($app, 'runningInConsole') && $app->runningInConsole()) {
                return false;
            }

            $flag = $_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? getenv('LARAVEL_OCTANE');
            if (is_scalar($flag) && ! in_array(strtolower(trim((string) $flag)), ['', '0', 'false'], true)) {
                return true;
            }

            return method_exists($app, 'bound') && $app->bound('Laravel\Octane\Contracts\Client');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A flusher for this worker, with Swoole's timer when it is a Swoole HTTP
     * worker (not a task worker, whose ticks and tasks flush instead).
     *
     * @param  object  $app  the application
     */
    public static function forWorker(Client $client, object $app): self
    {
        try {
            $server = method_exists($app, 'bound') && $app->bound('Swoole\Http\Server') ? $app->make('Swoole\Http\Server') : null;

            if (is_object($server) && empty($server->taskworker) && class_exists('Swoole\Timer')
                && method_exists('Swoole\Timer', 'after') && method_exists('Swoole\Timer', 'clear')) {
                return new self($client, 'Swoole\Timer::after', 'Swoole\Timer::clear');
            }
        } catch (\Throwable) {
        }

        return new self($client);
    }

    /**
     * Terminating callback of every request.
     */
    public function requestTerminated(): void
    {
        $this->client->flushIfDue();
        $this->arm();
    }

    /**
     * An Octane task or tick finished (task workers run no terminating callbacks).
     */
    public function operationTerminated(): void
    {
        $this->client->flushIfDue();
    }

    /**
     * WorkerStopping: send everything. Octane stops Swoole with SIGKILL
     * (octane:stop, SIGTERM to octane:start), which no PHP code survives;
     * workers that stop gracefully (max requests, octane:reload) get here.
     */
    public function workerStopping(): void
    {
        if ($this->timer !== null && $this->clear !== null) {
            try {
                ($this->clear)($this->timer);
            } catch (\Throwable) {
            }
        }
        $this->timer = null;

        $this->client->flush(2.0);
    }

    public function timerPending(): bool
    {
        return $this->timer !== null;
    }

    private function arm(): void
    {
        if ($this->after === null || $this->timer !== null) {
            return;
        }

        try {
            if ($this->client->transport()->pending() === 0) {
                return;
            }

            $id = ($this->after)(self::TIMER_MS, function (): void {
                $this->timer = null;
                $this->client->flushIfDue();
                // Not due yet (a request flushed in between) or backing off.
                $this->arm();
            });
            $this->timer = is_int($id) && $id > 0 ? $id : null;
        } catch (\Throwable) {
            $this->timer = null;
        }
    }
}
