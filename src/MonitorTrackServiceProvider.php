<?php

namespace MonitorTrack;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\ServiceProvider;
use MonitorTrack\Console\TestCommand;
use MonitorTrack\Listeners\QueryListener;
use MonitorTrack\Listeners\QueueListener;
use MonitorTrack\Listeners\ScheduleListener;
use MonitorTrack\Monolog\ChannelFactory;
use MonitorTrack\Support\OctaneFlusher;
use MonitorTrack\Support\RequestScope;

/**
 * Wires the SDK into Laravel. Every step is guarded: a broken configuration
 * disables the SDK, it never breaks the application.
 */
class MonitorTrackServiceProvider extends ServiceProvider
{
    /** @var \WeakMap<object, true>|null exception handlers that report to the SDK */
    private ?\WeakMap $reporting = null;

    public function register(): void
    {
        try {
            $this->mergeConfigFrom(__DIR__.'/../config/monitor-track.php', 'monitor-track');
        } catch (\Throwable) {
        }

        $this->app->singleton(Client::class, function ($app) {
            try {
                return $this->makeClient($app);
            } catch (\Throwable) {
                return Client::disabled();
            }
        });
        $this->app->alias(Client::class, 'monitor-track');

        try {
            $config = $this->app['config'];
            if (! $config->has('logging.channels.monitor-track')) {
                $config->set('logging.channels.monitor-track', [
                    'driver' => 'custom',
                    'via' => ChannelFactory::class,
                    'level' => $config->get('monitor-track.log_level', 'debug'),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    public function boot(): void
    {
        try {
            if ($this->app->runningInConsole()) {
                $this->publishes([
                    __DIR__.'/../config/monitor-track.php' => $this->app->configPath('monitor-track.php'),
                ], 'monitor-track-config');

                $this->commands([TestCommand::class]);
            }
        } catch (\Throwable) {
        }

        try {
            $client = $this->app->make(Client::class);
        } catch (\Throwable) {
            return;
        }

        if (! $client->isEnabled()) {
            return;
        }

        $capture = (array) $this->config('capture', []);

        try {
            // Reads the current container on each event: under Octane that is
            // the request's clone of the application, not $this->app.
            $client->setScopeResolver(new RequestScope);
        } catch (\Throwable) {
        }

        if ($capture['exceptions'] ?? true) {
            $this->registerExceptionReporter($client);
        }

        try {
            $events = $this->app['events'];

            if ($capture['queue'] ?? true) {
                (new QueueListener($client))->subscribe($events);
            }

            if ($capture['schedule'] ?? true) {
                $tz = $this->app['config']->get('app.timezone');
                (new ScheduleListener($client, is_string($tz) ? $tz : null))->subscribe($events);
            }

            if (($capture['queries'] ?? true) && ($client->slowQueryMs() > 0 || $client->nPlusOne() > 0)) {
                $queries = new QueryListener($client);
                $queries->subscribe($events);
                $this->app->instance(QueryListener::class, $queries);
                // Registered before the flush below, so a request's findings
                // leave in the same http batch.
                $this->app->terminating(static fn () => $queries->terminate());
            }
        } catch (\Throwable) {
        }

        $this->registerFlushes($client);
    }

    /**
     * http transport: when the buffer is sent. No-ops for stream and file.
     */
    private function registerFlushes(Client $client): void
    {
        try {
            $events = $this->app['events'];

            if (OctaneFlusher::running($this->app)) {
                // One process serves many requests: send when due (2 s /
                // 500 events), not once per request, and the rest when the
                // worker stops.
                $octane = OctaneFlusher::forWorker($client, $this->app);
                $this->app->terminating(static fn () => $octane->requestTerminated());
                $events->listen([
                    'Laravel\Octane\Events\TaskTerminated',
                    'Laravel\Octane\Events\TickTerminated',
                ], static fn () => $octane->operationTerminated());
                $events->listen('Laravel\Octane\Events\WorkerStopping', static fn () => $octane->workerStopping());
            } else {
                // After the response: FPM runs terminating callbacks after
                // fastcgi_finish_request; artisan commands when they end.
                $this->app->terminating(static fn () => $client->flush(2.0));
            }

            if ($this->app->runningInConsole() && ! OctaneFlusher::running($this->app)) {
                // A console process may run for hours without a worker loop
                // (a daemon command): send when due as events are recorded.
                // Queue workers send between jobs instead, so a job never
                // waits for the network.
                $client->setFlushOnRecord(true);
                $events->listen(Looping::class, static fn () => $client->setFlushOnRecord(false));
                $events->listen(JobProcessing::class, static function ($event) use ($client): void {
                    if (! $event->job instanceof SyncJob) {
                        $client->setFlushOnRecord(false);
                    }
                });
            }

            // Horizon's master and supervisor processes loop once a second
            // for as long as Horizon runs, without a queue Looping event.
            $events->listen([
                'Laravel\Horizon\Events\MasterSupervisorLooped',
                'Laravel\Horizon\Events\SupervisorLooped',
            ], static fn () => $client->flushIfDue());
        } catch (\Throwable) {
        }

        try {
            // Safety net for a process that ends without the above.
            register_shutdown_function(static fn () => $client->flush(1.0));
        } catch (\Throwable) {
        }
    }

    private function makeClient($app): Client
    {
        $cfg = (array) $app['config']->get('monitor-track', []);
        $http = (array) ($cfg['http'] ?? []);

        $options = $cfg;
        $options['file'] = ($cfg['file'] ?? null) ?: $app->storagePath('logs/monitor-track.jsonl');
        $options['app'] = ($cfg['app'] ?? null) ?: $app['config']->get('app.name');
        $options['env'] = ($cfg['env'] ?? null) ?: $app->environment();
        $options['base_path'] = $app->basePath();
        $options['http_connect_timeout_ms'] = $http['connect_timeout_ms'] ?? 500;
        $options['http_timeout_ms'] = $http['timeout_ms'] ?? 1000;

        return new Client($options);
    }

    private function registerExceptionReporter(Client $client): void
    {
        $this->reporting = new \WeakMap;

        $register = function ($handler) use ($client): void {
            if (! is_object($handler) || ! method_exists($handler, 'reportable') || isset($this->reporting[$handler])) {
                return;
            }
            $this->reporting[$handler] = true;

            // Returns nothing, so Laravel's own reporting (logging, other
            // reporters) continues. Exceptions in $dontReport never get here.
            $handler->reportable(static function (\Throwable $e) use ($client): void {
                $client->captureException($e);
            });
        };

        try {
            // Every handler instance: Octane resolves the handler again in
            // each request's clone of the application when the booted
            // application never resolved it.
            $this->app->afterResolving(ExceptionHandler::class, static function ($handler) use ($register): void {
                try {
                    $register($handler);
                } catch (\Throwable) {
                }
            });

            if ($this->app->resolved(ExceptionHandler::class)) {
                $register($this->app->make(ExceptionHandler::class));
            }
        } catch (\Throwable) {
        }
    }

    private function config(string $key, mixed $default = null): mixed
    {
        try {
            return $this->app['config']->get('monitor-track.'.$key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
