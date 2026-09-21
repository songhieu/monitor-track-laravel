<?php

namespace MonitorTrack;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\ServiceProvider;
use MonitorTrack\Console\TestCommand;
use MonitorTrack\Listeners\QueryListener;
use MonitorTrack\Listeners\QueueListener;
use MonitorTrack\Listeners\ScheduleListener;
use MonitorTrack\Monolog\ChannelFactory;
use MonitorTrack\Support\RequestScope;

/**
 * Wires the SDK into Laravel. Every step is guarded: a broken configuration
 * disables the SDK, it never breaks the application.
 */
class MonitorTrackServiceProvider extends ServiceProvider
{
    private bool $reporterRegistered = false;

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
            $client->setScopeResolver(new RequestScope($this->app));
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
                $queries = new QueryListener($client, $this->app);
                $queries->subscribe($events);
                $this->app->instance(QueryListener::class, $queries);
                // Registered before the flush below, so a request's findings
                // leave in the same http batch.
                $this->app->terminating(static fn () => $queries->terminate());
            }
        } catch (\Throwable) {
        }

        // http transport: send the request's buffer after the response
        // (FPM runs terminating callbacks after fastcgi_finish_request), and
        // once more at shutdown as a safety net. No-ops for stream/file.
        try {
            $this->app->terminating(static fn () => $client->flush(2.0));
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
        $register = function ($handler) use ($client): void {
            if ($this->reporterRegistered || ! is_object($handler) || ! method_exists($handler, 'reportable')) {
                return;
            }
            $this->reporterRegistered = true;

            // Returns nothing, so Laravel's own reporting (logging, other
            // reporters) continues. Exceptions in $dontReport never get here.
            $handler->reportable(static function (\Throwable $e) use ($client): void {
                $client->captureException($e);
            });
        };

        try {
            if ($this->app->resolved(ExceptionHandler::class)) {
                $register($this->app->make(ExceptionHandler::class));
            } else {
                $this->app->afterResolving(ExceptionHandler::class, static function ($handler) use ($register): void {
                    try {
                        $register($handler);
                    } catch (\Throwable) {
                    }
                });
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
