<?php

namespace MonitorTrack\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Jobs\SyncJob;
use MonitorTrack\Client;
use MonitorTrack\Support\QueryScope;
use MonitorTrack\Support\QueryThrottle;
use MonitorTrack\Support\RequestScope;
use MonitorTrack\Support\SqlNormalizer;
use MonitorTrack\Support\StackTrace;

/**
 * Database queries → slow query and N+1 events (type=query, docs/sdk-spec.md
 * §10).
 *
 * Each query costs one (memoized) normalization and one counter update. A
 * backtrace is only taken when a statement reaches the N+1 threshold or runs
 * longer than the slow threshold. Findings are sent when their scope ends:
 *   request:   the app's terminating callbacks (after the response on FPM)
 *   job:       JobProcessing → JobProcessed / JobFailed / JobExceptionOccurred
 *              / JobReleasedAfterException / JobTimedOut
 *   command:   CommandStarting → CommandFinished; long-running commands
 *              (queue:work, horizon, octane:*, …) are not scopes, their jobs are
 *   schedule:  ScheduledTaskStarting → ScheduledTaskFinished / ScheduledTaskFailed
 * Queries outside these belong to the process and are evaluated on
 * terminating as "request" or "command". Scopes nest (a sync job inside a
 * request); a query counts in the innermost open one.
 */
final class QueryListener
{
    /** Queries outside their jobs / requests (queue polling) are not counted. */
    private const LONG_RUNNING = [
        'queue:work', 'queue:listen', 'horizon', 'horizon:work', 'horizon:supervisor',
        'schedule:work', 'reverb:start', 'pulse:check', 'pulse:work',
    ];

    /** Distinct statements counted per scope. */
    private const MAX_STATEMENTS = 1000;

    /** N+1 findings and slow findings kept per scope, each. */
    private const MAX_FINDINGS = 100;

    /** Open scopes; deeper means closing events were lost. */
    private const MAX_DEPTH = 16;

    private const MAX_FRAMES = 20;

    private const BACKTRACE_LIMIT = 200;

    /** Normalized statements remembered, by raw SQL up to 4 KiB. */
    private const MEMO_SIZE = 500;

    private float $slowMs;

    /** @var int|float MT_SLOW_QUERY_MS as sent in `threshold` */
    private int|float $slowThreshold;

    private int $repeatAt;

    private QueryThrottle $throttle;

    /** Queries outside any request / job / command / task of this process. */
    private QueryScope $root;

    /** @var list<QueryScope> */
    private array $stack = [];

    /** Where the next query is counted; null = nowhere. */
    private ?QueryScope $current;

    private int $longRunning = 0;

    /** A queue worker loop (Looping) runs in this process. */
    private bool $worker = false;

    /** @var array<string, string> "{connection}\0{raw sql}" => normalized */
    private array $normalized = [];

    /** @var array<string, bool> connection => "…" is a string literal */
    private array $doubleQuotedStrings = [];

    public function __construct(private Client $client, private Application $app, ?QueryThrottle $throttle = null)
    {
        $this->slowMs = $client->slowQueryMs();
        $this->slowThreshold = fmod($this->slowMs, 1.0) === 0.0 ? (int) $this->slowMs : $this->slowMs;
        $this->repeatAt = $client->nPlusOne();
        $this->throttle = $throttle ?? new QueryThrottle($client->queryThrottleSeconds());
        $this->root = new QueryScope;
        $this->current = $this->root;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, [$this, 'queryExecuted']);

        $events->listen(JobProcessing::class, [$this, 'jobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'jobFinished']);
        $events->listen(JobFailed::class, [$this, 'jobFinished']);
        $events->listen(JobExceptionOccurred::class, [$this, 'jobFinished']);
        if (class_exists(JobReleasedAfterException::class)) {
            $events->listen(JobReleasedAfterException::class, [$this, 'jobFinished']);
        }
        if (class_exists(JobTimedOut::class)) {
            $events->listen(JobTimedOut::class, [$this, 'jobFinished']);
        }
        $events->listen(Looping::class, [$this, 'looping']);

        $events->listen(CommandStarting::class, [$this, 'commandStarting']);
        $events->listen(CommandFinished::class, [$this, 'commandFinished']);

        $events->listen(ScheduledTaskStarting::class, [$this, 'taskStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'taskFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'taskFinished']);

        // Octane serves many requests from one process.
        $events->listen('Laravel\Octane\Events\RequestReceived', [$this, 'requestReceived']);
    }

    public function setThrottle(QueryThrottle $throttle): void
    {
        $this->throttle = $throttle;
    }

    public function queryExecuted(QueryExecuted $event): void
    {
        $scope = $this->current;
        if ($scope === null) {
            return;
        }

        try {
            $time = (float) $event->time;
            $slow = $this->slowMs > 0 && $time >= $this->slowMs;
            if (! $slow && $this->repeatAt === 0) {
                return;
            }

            $connection = (string) $event->connectionName;
            $sql = $this->normalize($connection, (string) $event->sql, $event);
            $key = $connection."\0".$sql;

            if ($this->repeatAt > 0) {
                $this->count($scope, $key, $sql, $connection, $time);
            }

            if ($slow) {
                $this->slow($scope, $key, $sql, $connection, $time);
            }
        } catch (\Throwable) {
            // never affect the query
        }
    }

    public function jobProcessing(JobProcessing $event): void
    {
        try {
            $job = $event->job;

            if (! $job instanceof SyncJob) {
                // A worker runs one job at a time: an open job scope here is
                // one whose closing event never came.
                while ($this->stack !== [] && $this->stack[count($this->stack) - 1]->kind === 'job') {
                    $this->end(array_pop($this->stack));
                }
            }

            try {
                $name = (string) $job->resolveName();
            } catch (\Throwable) {
                $name = '';
            }

            $this->push(new QueryScope('job', $name !== '' ? $name : 'unknown', 'job#'.spl_object_id($job)));
        } catch (\Throwable) {
        }
    }

    /**
     * JobProcessed, JobFailed, JobExceptionOccurred, JobReleasedAfterException
     * or JobTimedOut: the first one closes the attempt's scope.
     */
    public function jobFinished(object $event): void
    {
        try {
            $this->close('job#'.spl_object_id($event->job));
        } catch (\Throwable) {
        }
    }

    public function looping(Looping $event): void
    {
        if (! $this->worker) {
            // From now on only jobs are scopes: the queries a worker makes
            // between jobs (polling the database queue) are nobody's.
            $this->worker = true;
            $this->root = new QueryScope;
            $this->refresh();
        }
    }

    public function commandStarting(CommandStarting $event): void
    {
        try {
            $name = (string) $event->command;

            if (self::isLongRunning($name)) {
                $this->longRunning++;
                $this->root = new QueryScope;
                $this->refresh();

                return;
            }

            $this->push(new QueryScope('command', trim('artisan '.$name), 'command#'.$name));
        } catch (\Throwable) {
        }
    }

    public function commandFinished(CommandFinished $event): void
    {
        try {
            $name = (string) $event->command;

            if (self::isLongRunning($name)) {
                $this->longRunning = max(0, $this->longRunning - 1);
                $this->refresh();

                return;
            }

            $this->close('command#'.$name);
        } catch (\Throwable) {
        }
    }

    public function taskStarting(ScheduledTaskStarting $event): void
    {
        try {
            $task = $event->task;
            $this->push(new QueryScope('schedule', ScheduleListener::name($task), 'task#'.spl_object_id($task)));
        } catch (\Throwable) {
        }
    }

    /**
     * ScheduledTaskFinished or ScheduledTaskFailed.
     */
    public function taskFinished(object $event): void
    {
        try {
            $this->close('task#'.spl_object_id($event->task));
        } catch (\Throwable) {
        }
    }

    /**
     * Octane: a new request starts from nothing, whatever the previous one
     * left behind.
     */
    public function requestReceived(): void
    {
        $this->stack = [];
        $this->root = new QueryScope;
        $this->refresh();
    }

    /**
     * Terminating callback: sends what the open scopes found, then starts
     * over (Octane serves the next request from the same process).
     */
    public function terminate(): void
    {
        try {
            while ($this->stack !== []) {
                $this->end(array_pop($this->stack));
            }

            if (! $this->ignoresRoot()) {
                $this->end($this->root);
            }
        } catch (\Throwable) {
        }

        $this->stack = [];
        $this->root = new QueryScope;
        $this->refresh();
    }

    private function count(QueryScope $scope, string $key, string $sql, string $connection, float $time): void
    {
        if (isset($scope->counts[$key])) {
            $c = &$scope->counts[$key];
            $c[0]++;
            $c[1] += $time;
            if ($time > $c[2]) {
                $c[2] = $time;
            }
            $n = $c[0];
            unset($c);
        } elseif (count($scope->counts) < self::MAX_STATEMENTS) {
            $scope->counts[$key] = [1, $time, $time];
            $n = 1;
        } else {
            return;
        }

        // One backtrace per statement, by the execution that reaches the
        // threshold: its call site is the one reported.
        if ($n !== $this->repeatAt || count($scope->repeated) >= self::MAX_FINDINGS) {
            return;
        }

        $frames = $this->callSite();

        // Repetition with no application code on the stack (the migrator, a
        // queue or cache driver) is nothing the app can change.
        if ($frames !== [] && $frames[0]['in_app']) {
            $scope->repeated[$key] = ['sql' => $sql, 'connection' => $connection, 'site' => self::site($frames), 'frames' => $frames];
        }
    }

    private function slow(QueryScope $scope, string $key, string $sql, string $connection, float $time): void
    {
        $frames = $this->callSite();
        $site = self::site($frames);
        $slowKey = $key."\0".$site;

        if (isset($scope->slow[$slowKey])) {
            $s = &$scope->slow[$slowKey];
            $s['count']++;
            $s['total'] += $time;
            if ($time > $s['max']) {
                $s['max'] = $time;
            }
            unset($s);
        } elseif (count($scope->slow) < self::MAX_FINDINGS) {
            $scope->slow[$slowKey] = [
                'sql' => $sql, 'connection' => $connection, 'site' => $site, 'frames' => $frames,
                'count' => 1, 'total' => $time, 'max' => $time,
            ];
        }
    }

    /**
     * @return list<array{file:string, line:int, func:string, in_app:bool}>
     */
    private function callSite(): array
    {
        return StackTrace::fromBacktrace(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::BACKTRACE_LIMIT),
            $this->client->basePath(),
            self::MAX_FRAMES,
            'Illuminate\Database\Connection->logQuery',
        );
    }

    /**
     * @param  list<array{file:string, line:int, func:string, in_app:bool}>  $frames
     */
    private static function site(array $frames): string
    {
        return isset($frames[0]) ? $frames[0]['file'].':'.$frames[0]['line'] : '';
    }

    private function normalize(string $connection, string $raw, QueryExecuted $event): string
    {
        $memo = $connection."\0".$raw;
        if (isset($this->normalized[$memo])) {
            return $this->normalized[$memo];
        }

        $sql = SqlNormalizer::normalize($raw, str_contains($raw, '"') && $this->doubleQuotedStrings($connection, $event));

        if (strlen($raw) <= 4096) {
            if (count($this->normalized) >= self::MEMO_SIZE) {
                $this->normalized = [];
            }
            $this->normalized[$memo] = $sql;
        }

        return $sql;
    }

    private function doubleQuotedStrings(string $connection, QueryExecuted $event): bool
    {
        if (! isset($this->doubleQuotedStrings[$connection])) {
            try {
                $driver = (string) $event->connection->getDriverName();
            } catch (\Throwable) {
                $driver = '';
            }
            $this->doubleQuotedStrings[$connection] = in_array($driver, ['mysql', 'mariadb'], true);
        }

        return $this->doubleQuotedStrings[$connection];
    }

    private function push(QueryScope $scope): void
    {
        if (count($this->stack) >= self::MAX_DEPTH) {
            array_shift($this->stack);
        }

        $this->stack[] = $scope;
        $this->current = $scope;
    }

    /**
     * Ends the scope with this id and any scope opened inside it that never
     * closed. An id that is not open (a second closing event) is ignored.
     */
    private function close(string $id): void
    {
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            if ($this->stack[$i]->id === $id) {
                while (count($this->stack) > $i) {
                    $this->end(array_pop($this->stack));
                }
                break;
            }
        }

        $this->refresh();
    }

    private function refresh(): void
    {
        $this->current = $this->stack !== []
            ? $this->stack[count($this->stack) - 1]
            : ($this->ignoresRoot() ? null : $this->root);
    }

    private function ignoresRoot(): bool
    {
        return $this->worker || $this->longRunning > 0;
    }

    private static function isLongRunning(string $command): bool
    {
        return in_array($command, self::LONG_RUNNING, true) || str_starts_with($command, 'octane:');
    }

    private function end(QueryScope $scope): void
    {
        if ($scope->repeated === [] && $scope->slow === []) {
            return;
        }

        try {
            if ($scope->kind === '') {
                [$scope->kind, $scope->name] = $this->describeProcess();
            }

            foreach ($scope->repeated as $key => $finding) {
                [$count, $total, $max] = $scope->counts[$key];
                $this->emit('n_plus_one', $scope, $finding, $count, $total, $max, $this->repeatAt);
            }

            foreach ($scope->slow as $finding) {
                $this->emit('slow', $scope, $finding, $finding['count'], $finding['total'], $finding['max'], $this->slowThreshold);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array{sql:string, connection:string, site:string, frames:list<array{file:string, line:int, func:string, in_app:bool}>}  $finding
     */
    private function emit(string $kind, QueryScope $scope, array $finding, int $count, float $total, float $max, int|float $threshold): void
    {
        $key = implode("\0", [$kind, $finding['connection'], $finding['sql'], $finding['site'], $this->client->basePath()]);
        if (! $this->throttle->allow($key)) {
            return;
        }

        $this->client->query($kind, [
            'sql' => $finding['sql'],
            'connection' => $finding['connection'],
            'count' => $count,
            'total_ms' => $total,
            'max_ms' => $max,
            'threshold' => $threshold,
            'scope' => $scope->kind,
            'scope_name' => $scope->name,
            'frames' => $finding['frames'],
        ]);
    }

    /**
     * Kind and name of the process scope: the matched route of a request,
     * else the artisan command (or script) of a console process.
     *
     * @return array{0:string, 1:string}
     */
    private function describeProcess(): array
    {
        $route = (new RequestScope($this->app))()['route'] ?? null;
        if ($route !== null) {
            return ['request', $route];
        }

        if (! $this->app->runningInConsole()) {
            $method = 'GET';
            try {
                if ($this->app->resolved('request')) {
                    $method = strtoupper((string) $this->app->make('request')->getMethod());
                }
            } catch (\Throwable) {
            }

            return ['request', $method.' (no route)'];
        }

        $argv = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
        $script = basename((string) ($argv[0] ?? 'php'));
        if ($script === 'artisan') {
            foreach (array_slice($argv, 1) as $arg) {
                if (is_string($arg) && $arg !== '' && $arg[0] !== '-') {
                    return ['command', 'artisan '.$arg];
                }
            }
        }

        return ['command', $script];
    }
}
