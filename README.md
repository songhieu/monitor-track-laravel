# monitor-track for Laravel

[![tests](https://github.com/songhieu/monitor-track-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/songhieu/monitor-track-laravel/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/songhieu/monitor-track-laravel?label=packagist)](https://packagist.org/packages/songhieu/monitor-track-laravel)

`songhieu/monitor-track-laravel` sends structured events to monitor-track
App Logs. Plain log lines can't carry this data: exceptions with real stack
frames, exact queue job runs, scheduled task runs that register themselves,
queue worker heartbeats, and slow or N+1 database queries with the line of
your code that ran them. The wire format is described in
[`docs/sdk-spec.md`](docs/sdk-spec.md).

Requires PHP 8.0+ and Laravel 9, 10, 11, 12 or 13 (Monolog 2 or 3). Every
combination is tested in CI on PHP 8.0–8.5, including the lowest supported
versions. Laravel 9, 10 and 11 no longer receive security fixes; Composer
2.10+ refuses to install them while they have open advisories — upgrading is
the real fix.

## Install

```bash
composer require songhieu/monitor-track-laravel
php artisan mt:test          # sends a log, exception, job, cron run and heartbeat; shows where they went
```

On Kubernetes, run the test inside a pod with `--pod-log`:

```bash
kubectl -n billing exec deploy/billing-api -- php artisan mt:test --pod-log
```

`kubectl exec` gives the command its own stderr, which is not the container
log the collector reads; `--pod-log` writes to the container's stderr
(`/proc/1/fd/2`) instead. It must run as the same user as the container's
main process, which is what `kubectl exec` does by default.

Package discovery registers the service provider and the `MonitorTrack`
facade. The default transport writes one JSON line per event to stderr. The
monitor-track collector already reads pod logs, so on Kubernetes you usually
don't need to configure anything else — no key, and no network call from
the app.

Outside Kubernetes (a VM, shared hosting), create an **HTTP push** log
source in monitor-track to get a key (`mt_src_…`, shown once) and send the
events straight to the ingest service:

```dotenv
MT_TRANSPORT=http
MT_ENDPOINT=https://ingest.monitor.example.com
MT_TOKEN=mt_src_...
LOG_STACK=stderr,monitor-track
```

To publish the config file (optional):

```bash
php artisan vendor:publish --tag=monitor-track-config
```

## Configuration

These `MT_*` variables are the same in the Go and Python SDKs.

| env | default | meaning |
|---|---|---|
| `MT_ENABLED` | `true` | `false` makes every call a no-op and registers no listeners |
| `MT_TRANSPORT` | `stream` | `stream` \| `file` \| `http` |
| `MT_STREAM` | `stderr` | `stderr` \| `stdout` |
| `MT_FILE` | `storage/logs/monitor-track.jsonl` | file transport path |
| `MT_ENDPOINT` | — | http transport base URL, e.g. `https://monitor.example.com` |
| `MT_TOKEN` | — | source push token (http) |
| `MT_APP` | `config('app.name')` | `app` field |
| `MT_ENV` | `app()->environment()` | `env` field |
| `MT_RELEASE` | — | `release` field (e.g. image tag) |
| `MT_SAMPLE_RATE` | `1` | 0..1, applies to `type=log` only |
| `MT_QUEUE_SIZE` | `1000` | http transport: max events buffered per PHP process |
| `MT_HEARTBEAT_SECONDS` | `15` | queue worker heartbeat interval |
| `MT_LOG_LEVEL` | `debug` | minimum level of the `monitor-track` log channel |
| `MT_SLOW_QUERY_MS` | `500` | report queries at least this slow; `0` turns it off |
| `MT_N_PLUS_ONE` | `10` | report a statement run this many times in one request / job / command (minimum 2); `0` turns it off |
| `MT_QUERY_THROTTLE_SECONDS` | `60` | at most one query event per statement and call site per window per server; `0` sends every one |
| `MT_LONG_RUNNING_COMMANDS` | — | your own daemon commands, comma-separated names or patterns (`distribution:*`): not a query scope themselves, like `queue:work` |

With `MT_TRANSPORT=http` and no `MT_ENDPOINT`, the SDK falls back to `stream`.
`config/monitor-track.php` also has `capture.exceptions|queue|schedule|queries`
switches and the http timeouts.

## What is captured automatically

| source | events |
|---|---|
| `ExceptionHandler::reportable()` | `exception` with frames (innermost first, relative to `base_path()`, `in_app` = not `vendor/`), route `POST /api/v1/payments/{payment}`, user id (only when the guard already loaded the user, so no extra query), trace id from `traceparent` or `X-Request-Id`. Exceptions in `$dontReport` (404, validation, …) are skipped as usual. |
| Queue worker (`JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred`, `JobReleasedAfterException`, `JobTimedOut`) | `job` `start`, then exactly one of `done` / `failed` / `retry` per attempt, with queue, connection, class, uuid, attempts, `runtime_ms`, `timeout_ms` |
| Queue worker `Looping` | `heartbeat` every `MT_HEARTBEAT_SECONDS`: queues, busy/idle, current job, memory, processed count. Only queue workers (`queue:work`, `horizon:work`) send heartbeats; a `dispatch_sync()` job in a web request or an Octane worker is a job run, not a worker. |
| Scheduler (`ScheduledTaskStarting`, `Finished`, `Failed`, `Skipped`, `ScheduledBackgroundTaskFinished`) | `cron` `start` / `success` / `fail` / `skip` with name, expression, timezone, exit code, duration and the output tail on failure. The first event registers the task in monitor-track with its schedule. |
| Database (`QueryExecuted`) | `query` `n_plus_one` / `slow` per request, job, command or scheduled task, with the normalized SQL, count, total and max time, and the call site. See [Slow queries and N+1](#slow-queries-and-n1). |

The task name is the task's `description` if it has one. Otherwise it is the
artisan command without the PHP binary (for example `invoices:send-reminders`).
Closures with no `->name()` are named `closure:<file>:<line>`.

## Slow queries and N+1

The SDK listens to Laravel's `QueryExecuted` event and reports two problems
as `query` events:

- **N+1**: the same statement runs `MT_N_PLUS_ONE` (10) times or more in one
  request, queued job, artisan command or scheduled task. Usually this is a
  relation lazy-loaded in a loop. The event has the number of runs, their
  total and slowest time, and the stack of your code that ran the statement:
  `frames[0]` is your line, such as the controller loop or the `@foreach` in
  a compiled Blade view.
- **Slow query**: a query that takes `MT_SLOW_QUERY_MS` (500 ms) or longer.
  Slow runs of the same statement from the same line are added up per
  request or job.

```text
N+1 query: 37× select * from `users` where `users`.`id` = ? limit ?      GET /orders/{order}
  app/Http/Controllers/OrderController.php:42  App\Http\Controllers\OrderController->index
```

Statements are compared by shape: `in (?, ?, ?)` lists become `in (?)`, and
string and number literals become `?`. The SDK never reads the bindings, so
no values leave the app, and the SQL sent is at most 2000 bytes. A statement
repeated only inside framework or vendor code, with none of your code on the
stack (the migrator, the queue and cache drivers), is not reported as N+1.

Findings are sent when the unit of work ends: after the response for a
request, and when the job, command or scheduled task finishes. A queue
worker (`queue:work`, Horizon) and the other long-running commands
(`schedule:work`, `octane:*`, `reverb:start`, `pulse:*`) are not a unit
themselves. Each job they run is one, and the worker's own polling queries
are ignored. Add your own daemon commands to that list with
`MT_LONG_RUNNING_COMMANDS=distribution:*,reports:daemon` (names or `*`
patterns); otherwise a command that loops for hours is one unit, and a
statement it repeats over its lifetime is reported as N+1 when it exits. Counters start empty for every job and request, so nothing
builds up in a long-running worker or an Octane process.

**Events are samples.** The SDK sends at most one event per statement and
call site per `MT_QUERY_THROTTLE_SECONDS` (60) per server. An N+1 endpoint
serving 100 requests a second sends one event a minute, not 100 a second.
The count in an event is for that one request or job. The window is kept in
marker files in the system temp dir (`mt-q-*`), so it works across PHP-FPM
requests and between FPM and the queue workers. If the temp dir is not
writable, 1% of the events are sent instead.

To tune it or turn it off:

```dotenv
MT_SLOW_QUERY_MS=1000            # 0 turns slow-query events off
MT_N_PLUS_ONE=20                 # 0 turns N+1 events off
MT_QUERY_THROTTLE_SECONDS=300
```

With both set to `0`, or `'capture' => ['queries' => false]` in
`config/monitor-track.php`, the SDK registers no query listener at all.

**Overhead.** Each query costs about 0.5 µs in the SDK: one normalization,
remembered for repeated SQL, and one counter update. Laravel's own event
dispatch adds about 1 µs. The SDK only takes a backtrace (under 0.1 ms) when
a statement reaches the N+1 threshold, once per request or job, and for each
slow query. Memory per request or job is bounded to 1000 distinct statements
and 100 findings of each kind. Events are built and throttled when the
request or job ends, which is after the response under FPM.

## Logging channel

The provider adds a `monitor-track` channel. Its records become `log` events,
or `exception` events when the context has an `exception`:

```php
// config/logging.php (added for you if missing)
'monitor-track' => [
    'driver' => 'custom',
    'via' => MonitorTrack\Monolog\ChannelFactory::class,
    'level' => env('MT_LOG_LEVEL', 'debug'),
],
```

Pick the setup that matches your transport:

- **stream transport (default):** replace `stderr` with `monitor-track`, for
  example `LOG_STACK=monitor-track` (Laravel 11+) or
  `'channels' => ['monitor-track']` in the `stack` channel of
  `config/logging.php` (Laravel 9 and 10, which have no `LOG_STACK`). Both
  channels write to stderr, so `LOG_STACK=stderr,monitor-track` would print
  every line twice.
- **file / http transport:** `LOG_STACK=stderr,monitor-track` (Laravel 11+)
  or `['single', 'monitor-track']` (Laravel 9/10) is fine. The first channel
  stays human-readable, and the structured copy goes to the file or the
  ingest API. If the backend also reads this pod's stdout, you will see both
  copies.

Each exception is sent once, even when both the exception handler and a log
call see it.

You can also attach the handler yourself:
`new MonitorTrack\Monolog\Handler(app(MonitorTrack\Client::class), 'warning')`.

## Manual API

```php
use MonitorTrack\Facades\MonitorTrack;

MonitorTrack::log('warning', 'Payment retry scheduled', ['order_id' => 991]);
MonitorTrack::captureException($e, ['order_id' => 991]);

// Work outside Laravel's queue / scheduler:
MonitorTrack::trackJob('imports', ImportCsv::class, fn () => $importer->run());
MonitorTrack::trackCron('reports:nightly', '0 2 * * *', fn () => $reports->build());

MonitorTrack::stats();   // ['emitted' => …, 'dropped' => …, 'sampled' => …, 'errors' => …]
MonitorTrack::flush();   // http transport: send the buffer now (2 s budget)
MonitorTrack::flushIfDue(); // http transport: send if 2 s passed or 500 events wait (daemon loops)
```

`trackJob` and `trackCron` record the exception and rethrow it unchanged.

In your application's tests, `MonitorTrack::fake()` routes events to memory:

```php
$events = MonitorTrack::fake();
dispatch_sync(new SendInvoiceEmail($invoice));
$this->assertCount(1, $events->events('exception'));
```

## Transports

- **stream** (default): one `fwrite` of one line to `php://stderr` or
  `php://stdout`. There is no network and no buffering.
  **PHP-FPM:** FPM only forwards worker stderr when it is configured to. In
  the pool config set:

  ```ini
  catch_workers_output = yes
  decorate_workers_output = no   ; no "child 12 said into stderr:" prefix
  ```
  and in the global `php-fpm.conf`: `log_limit = 32768` (the default of 1024
  splits long lines).
- **file**: appends to `MT_FILE` with an exclusive `flock`, one write per
  line. The file is opened for each write, so logrotate works. Ship the file
  with Vector or Fluent Bit.
- **http**: events are buffered in memory (at most `MT_QUEUE_SIZE`, the newest
  are dropped when full). They are sent as gzip NDJSON to
  `{MT_ENDPOINT}/api/v1/logs/ingest` from `app()->terminating()`, which runs
  after the response is sent under FPM and when an artisan command ends. A
  console process also sends when due (2 s or 500 events) as it records
  events, so a daemon command delivers while it runs. Queue workers instead
  send every 2 s or 500 events from the `Looping` event, between jobs, and at
  `WorkerStopping`; Horizon's master and supervisor processes send from their
  once-a-second loop. Octane workers send when due, see
  [Laravel Octane](#laravel-octane). A shutdown function is the last resort.
  Each request has a 0.5 s connect timeout and a 1 s total timeout. A
  connection error gets one retry after 200 ms. Any non-2xx response drops
  the batch. When the ingest can't be reached or answers 429 / 502–504, a
  long-running process stops trying for 5 s, doubling up to 60 s: events keep
  being buffered (up to `MT_QUEUE_SIZE`) and go out when it is back.

## Laravel Octane

The SDK works under Octane (Swoole, RoadRunner) without configuration:

- **Per-request context.** Octane serves every request from a fresh clone of
  the booted application. The SDK reads the route, user and trace id from the
  current container at the time of each event, so every event carries its
  own request's `route`, `user_id` and `trace_id`, and exceptions are reported
  from every request, whichever copy of the exception handler it resolves.
- **Queries.** N+1 and slow-query counters start empty for every request
  (and every Octane task and tick), so nothing adds up across requests.
- **http transport.** An Octane worker serves many requests, so it does not
  POST after each one. After a request, the buffer is sent when it is due:
  2 s since the last send, or 500 events. On Swoole, a one-shot timer sends
  what is left about 2 s after the last request; it fires between requests,
  never during one. Task workers send after their tasks and ticks, and every
  worker sends everything at `WorkerStopping` (max requests reached,
  `octane:reload`). A worker is detected by the `LARAVEL_OCTANE` variable
  Octane sets for its server.

Octane (1.x and 2.x) stops Swoole with `SIGKILL` (`octane:stop`, or
`SIGTERM` to `octane:start`, which is what supervisord and Kubernetes send),
so no PHP code runs at that point: up to the last ~2 s of events of each
worker can be lost when the server is stopped. The same goes for a worker
killed for exceeding Octane's `max_execution_time`. With RoadRunner there is no timer: what a
request leaves in the buffer goes with a later request or when the worker
stops.

With the stream transport, worker output passes through `octane:start`,
which re-encodes JSON lines before printing them. Prefer the http or file
transport under Octane.

## Why this never slows your app

- **No network on the request or job path.** The default transport is a single
  local `fwrite`. The http transport only buffers during a request and sends
  after the response. Workers send between jobs.
- **Fail-open.** Every entry point, listener, transport and the service
  provider itself catches `\Throwable` and never rethrows. Failures are
  counted (`MonitorTrack::stats()['errors']`) and never raised. A broken
  config disables the SDK. It never breaks boot.
- **Bounded memory.** The http buffer holds at most `MT_QUEUE_SIZE` lines
  (1000), and the newest are dropped when it is full. Context is walked at
  most 5 levels deep and 100 items per level.
- **Bounded time.** Each http request has a 0.5 s connect and 1 s total
  timeout, with one quick retry and no loops. `flush()` stops at its budget.
  After a failed send, long-running processes (queue workers, Octane) back
  off for 5–60 s instead of waiting for the timeout at every flush.
- **Bounded size.** Lines stay under 16 KiB where possible and never exceed
  32 KiB. The message, output and context are truncated first, and the event
  is dropped only as a last resort. Output is always a single line.
- **No side effects.** The SDK reads the user id only if the guard already
  loaded the user. It never resolves services the app hasn't resolved. It
  never logs through your logger.
- **Sampling.** `MT_SAMPLE_RATE` thins `log` events. Exceptions, jobs, cron
  runs and heartbeats are always kept. `query` events are throttled to one
  per statement and call site per minute per server.
- **Cheap query hooks.** About 0.5 µs per query in the SDK. Stacks are only
  captured when a threshold is crossed, and findings are sent after the
  response.

## Known limits

- The PHP worker is single-threaded, so no heartbeat is sent while a job runs.
  A `busy` heartbeat is sent at job start when one is due, and heartbeats
  resume between jobs. Jobs that run longer than 3 × `MT_HEARTBEAT_SECONDS`
  go quiet until they finish.
- `runInBackground()` tasks report success or failure from the
  `schedule:finish` process. That process doesn't know the start time, so
  these events carry no `duration_ms`. Laravel sends the output of that
  process to `/dev/null` (and the task's own output to `/dev/null` or its
  `appendOutputTo()` file), so with the stream transport these events are
  lost: use the http or file transport for a scheduler with background tasks.
- A daemon command that records no events for a while holds what it buffered
  until its next event or its exit: the check runs when an event is recorded.
  Call `MonitorTrack::flushIfDue()` in its loop if that matters.
- N+1 counts runs of the same statement per request or job, wherever they
  come from. The call site reported is the one of the run that reached the
  threshold.
- An N+1 in a Blade template points at the compiled view
  (`storage/framework/views/….php`), not at the `.blade.php` file.
