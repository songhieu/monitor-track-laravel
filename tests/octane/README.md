# Octane, scheduler and Horizon harness

Runs the SDK inside a real application with the **http transport** and checks
what an ingest capture server received. Everything runs in one Docker image;
nothing is installed on the host. The image mirrors the dodgeprint runtime:
Alpine 3.19, PHP 8.2 with `php82-pecl-swoole` (Swoole 5.1), a fresh
Laravel 9.52 app, `laravel/octane` 1.5.6, `laravel/horizon` 5 and redis.
The SDK is installed from a path repository and the live source is mounted
at run time, so SDK changes need no rebuild.

```bash
tests/octane/run.sh              # all three scenarios
tests/octane/run.sh octane       # or schedule, horizon, daemon
REBUILD=1 tests/octane/run.sh    # rebuild the image (after changing app/ or the Dockerfile)
CLEAN=1 tests/octane/run.sh      # remove the image afterwards
SDK=/path/to/other/sdk tests/octane/run.sh octane   # e.g. an older release, for comparison
```

The first build takes about a minute and the image is about 130 MB. The
scenarios take about 25 s (octane), 20 s (schedule), 50 s (horizon) and
10 s (daemon).

## Scenarios

**octane** (`octane:start --server=swoole --workers=2`): 40 requests, two at a
time, from users 1 and 2 on `GET /api/orders` (a lazy-loading N+1 over 30
orders) and `GET /api/boom` (throws), each with its own `X-Request-Id`.
Authentication is a request guard that loads the user with a query, like
Passport's. The app's log stack also contains the `monitor-track` channel.
It then checks:

- every request has exactly one event, with its own `route`, `user_id` and
  `trace_id`: an N+1 event with `count` 30 (per request, not accumulated) or
  one exception (not duplicated by the log channel);
- the 40 requests leave in a few POSTs (sent when due, not per request);
- a lone request's events leave about 2 s later with no request after it
  (the Swoole timer);
- `octane:reload` runs `WorkerStopping` in both workers;
- `octane:stop` 3 s after the last request loses nothing. Right after a
  request it can lose that request's events: Octane stops Swoole with
  `SIGKILL` (reported as info);
- Octane's console output contains no SDK JSON.

**schedule**: `schedule:run` twice, 3 s apart, with `runInBackground()` tasks
that exit 3 and 0, a `withoutOverlapping()` task that sleeps 8 s, and a
foreground task that exits 4. It checks start → fail (exit 3) and start →
success from the `schedule:finish` processes, start → skip → success for the
overlapping task, fail with `duration_ms` for the foreground task, and that
each process (2 `schedule:run`, 5 `schedule:finish`) sent its buffer in one
POST when it ended.

**horizon**: `php artisan horizon` with 20 queued jobs (16 with an N+1 over
30 rows, 4 that fail), then `horizon:terminate`. It checks start → done /
failed per job, one N+1 event per job with `scope` `job`, one exception per
failing job, and heartbeats only from `horizon:work` processes.

**daemon**: `harness:daemon 7`, a loop like dodgeprint's
`distribution:supervisor` (an N+1 over 30 rows and a log line each second),
listed in `MT_LONG_RUNNING_COMMANDS` as `harness:dae*`. It checks that the
events leave while the command runs and that its 210 repeated queries are
not reported as N+1 when it exits.

## Files

- `Dockerfile`: the image (the `sdk` build context is prepared by `run.sh`).
- `app/`: files copied over the fresh Laravel app (routes, models, the
  migration, the console kernel with the schedule, commands, jobs, and an
  `AppServiceProvider` that configures the guard and the log stack).
- `harness/*.sh`: the scenarios, run inside the container from the mounted
  `tests/` directory; `harness/check.php` makes the assertions. The capture
  server is `tests/Fixtures/ingest-server.php`.
