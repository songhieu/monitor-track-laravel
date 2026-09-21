# monitor-track for Laravel

[![tests](https://github.com/songhieu/monitor-track-laravel/actions/workflows/tests.yml/badge.svg)](https://github.com/songhieu/monitor-track-laravel/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/songhieu/monitor-track-laravel?label=packagist)](https://packagist.org/packages/songhieu/monitor-track-laravel)

`songhieu/monitor-track-laravel` sends structured events to monitor-track
App Logs. Plain log lines can't carry this data: exceptions with real stack
frames, exact queue job runs, scheduled task runs that register themselves,
and queue worker heartbeats. The wire format is described in
[`docs/sdk-spec.md`](docs/sdk-spec.md).

Requires PHP 8.0+ and Laravel 9, 10, 11, 12 or 13 (Monolog 2 or 3). Every
combination is tested in CI on PHP 8.0–8.5, including the lowest supported
versions. Laravel 9, 10 and 11 no longer receive security fixes; Composer
2.10+ refuses to install them while they have open advisories — upgrading is
the real fix.

## Install

```bash
composer require songhieu/monitor-track-laravel
php artisan mt:test          # emits one event of each type and shows where they went
```

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

With `MT_TRANSPORT=http` and no `MT_ENDPOINT`, the SDK falls back to `stream`.
`config/monitor-track.php` also has `capture.exceptions|queue|schedule`
switches and the http timeouts.

## What is captured automatically

| source | events |
|---|---|
| `ExceptionHandler::reportable()` | `exception` with frames (innermost first, relative to `base_path()`, `in_app` = not `vendor/`), route `POST /api/v1/payments/{payment}`, user id (only when the guard already loaded the user, so no extra query), trace id from `traceparent` or `X-Request-Id`. Exceptions in `$dontReport` (404, validation, …) are skipped as usual. |
| Queue worker (`JobProcessing`, `JobProcessed`, `JobFailed`, `JobExceptionOccurred`, `JobReleasedAfterException`, `JobTimedOut`) | `job` `start`, then exactly one of `done` / `failed` / `retry` per attempt, with queue, connection, class, uuid, attempts, `runtime_ms`, `timeout_ms` |
| Queue worker `Looping` | `heartbeat` every `MT_HEARTBEAT_SECONDS`: queues, busy/idle, current job, memory, processed count |
| Scheduler (`ScheduledTaskStarting`, `Finished`, `Failed`, `Skipped`, `ScheduledBackgroundTaskFinished`) | `cron` `start` / `success` / `fail` / `skip` with name, expression, timezone, exit code, duration and the output tail on failure. The first event registers the task in monitor-track with its schedule. |

The task name is the task's `description` if it has one. Otherwise it is the
artisan command without the PHP binary (for example `invoices:send-reminders`).
Closures with no `->name()` are named `closure:<file>:<line>`.

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
  after the response is sent under FPM. Long-running queue workers send every
  2 s or 500 events from the `Looping` event, and at `WorkerStopping`. A
  shutdown function is the last resort. Each request has a 0.5 s connect
  timeout and a 1 s total timeout. A connection error gets one retry after
  200 ms. Any non-2xx response drops the batch.

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
- **Bounded size.** Lines stay under 16 KiB where possible and never exceed
  32 KiB. The message, output and context are truncated first, and the event
  is dropped only as a last resort. Output is always a single line.
- **No side effects.** The SDK reads the user id only if the guard already
  loaded the user. It never resolves services the app hasn't resolved. It
  never logs through your logger.
- **Sampling.** `MT_SAMPLE_RATE` thins `log` events. Exceptions, jobs, cron
  runs and heartbeats are always kept.

## Known limits

- The PHP worker is single-threaded, so no heartbeat is sent while a job runs.
  A `busy` heartbeat is sent at job start when one is due, and heartbeats
  resume between jobs. Jobs that run longer than 3 × `MT_HEARTBEAT_SECONDS`
  go quiet until they finish.
- `runInBackground()` tasks report success or failure from the
  `schedule:finish` process. That process doesn't know the start time, so
  these events carry no `duration_ms`.
