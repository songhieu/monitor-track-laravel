# monitor-track SDK wire contract (v1)

The monitor-track SDKs (Laravel, Go, Python) add structured data to App Logs
that raw log lines cannot carry: exceptions with real stack frames, exact queue
job runs, scheduled-task (cron) runs that register themselves, and worker
heartbeats. This document is the contract between the SDKs and the backend.
Every SDK implements it identically.

- Go: `sdk/go` (`github.com/songhieu/monitor-track/sdk/go`, package `mt`)
- Python: `sdk/python` (package `monitortrack`)
- Laravel: `sdk/laravel` (`songhieu/monitor-track-laravel`)

## 1. Envelope

One event = one JSON object on **one line** (NDJSON). `"_mt":1` MUST be the
first key: the backend fast-paths on the byte prefix `{"_mt":`. Any line
without that prefix goes through the source's normal log parser.

```json
{"_mt":1,"type":"exception","ts":"2026-09-21T14:31:07.412+07:00","level":"error","message":"GuzzleHttp\\Exception\\ConnectException: cURL error 7: Failed to connect to psp.example.com","app":"billing-api","env":"production","release":"2026.09.21-2","host":"billing-api-7d9f8c6b5-x2kqp","pid":41,"trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","user_id":"1823","route":"POST /api/v1/payments","context":{"order_id":991,"authorization":"***"},"exception":{"class":"GuzzleHttp\\Exception\\ConnectException","message":"cURL error 7: Failed to connect to psp.example.com","frames":[{"file":"app/Services/PaymentGateway.php","line":142,"func":"App\\Services\\PaymentGateway->post","in_app":true},{"file":"vendor/guzzlehttp/guzzle/src/Client.php","line":189,"func":"GuzzleHttp\\Client->request","in_app":false}]}}
```

### Fields

Keys are emitted in this order. Empty/unknown fields are **omitted** (never
`null`, never `""`), except `_mt`, `type`, `ts`, `level`, `message`, `pid`.

| key | type | notes |
|---|---|---|
| `_mt` | int | always `1` (wire version), always first |
| `type` | string | `log` \| `exception` \| `job` \| `cron` \| `heartbeat` |
| `ts` | string | RFC 3339, millisecond precision, numeric offset or `Z`: `2026-09-21T14:31:07.412+07:00` |
| `level` | string | `debug` \| `info` \| `notice` \| `warning` \| `error` \| `critical` |
| `message` | string | human-readable; see per-type formats below |
| `app` | string | `MT_APP` → framework app name → process name |
| `env` | string | `MT_ENV` → framework environment |
| `release` | string | `MT_RELEASE` |
| `host` | string | hostname (in Kubernetes: the pod name) |
| `pid` | int | OS process id |
| `trace_id` | string | W3C `traceparent` trace-id, else `X-Request-Id` |
| `user_id` | string | authenticated user id, always a string |
| `route` | string | `METHOD /route/template` (template, not raw path: `GET /users/{id}`) |
| `context` | object | arbitrary JSON, **masked** (section 3) |
| `exception` | object | `type=exception` only |
| `job` | object | `type=job` only |
| `cron` | object | `type=cron` only |
| `heartbeat` | object | `type=heartbeat` only |

Only the object for the event's own type is present.

### Per-type payloads, levels and messages

**log** — an application log record. Level from the logger. Subject to sampling
(`MT_SAMPLE_RATE`). A log record that carries an exception is sent as
`type=exception` instead (never sampled).

**exception**
```json
"exception":{"class":"App\\Exceptions\\PaymentDeclined","message":"card declined","frames":[{"file":"app/Services/PaymentGateway.php","line":142,"func":"App\\Services\\PaymentGateway->post","in_app":true}]}
```
- Level `error` by default; `critical` for panics/fatal errors or when the
  logging call used critical.
- `message` = `"{class}: {message}"`.
- `frames` are **innermost first** (`frames[0]` = where it was thrown), at most
  50 (keep the first 50). `file` is relative to the app root (base path / cwd
  stripped; for installed packages the path after `site-packages/`).
  `in_app` = true for application code, false for `vendor/`, `site-packages`,
  `dist-packages`, `node_modules`, the language stdlib/`GOROOT` and the Go
  module cache (`/pkg/mod/`, `@vX.Y.Z`). The SDK's own frames are removed.
- `func` uses the language's native notation (`Class->method`,
  `Class::static`, `module.func`, `pkg.(*T).Method`).
- Chained exceptions (`previous`, `__cause__`, `%w`) are not expanded in v1;
  the outermost one is sent.

**job** — one queue job attempt.
```json
"job":{"queue":"emails","connection":"redis","class":"App\\Jobs\\SendInvoiceEmail","id":"9b1c6c0e-5a0b-4f7e-9d3e-3f1f0e6c2a11","status":"done","runtime_ms":1820,"attempts":1,"timeout_ms":10000}
```
| status | level | message | fields |
|---|---|---|---|
| `start` | info | `Job started: {class}` | no `runtime_ms` |
| `done` | info | `Job done: {class} ({runtime_ms}ms)` | `runtime_ms` |
| `failed` | error | `Job failed: {class} ({runtime_ms}ms)` | `runtime_ms` |
| `retry` | warning | `Job released for retry: {class} ({runtime_ms}ms)` | `runtime_ms` |

`id` is the queue's job id (Laravel `uuid()`, Celery `task_id`); SDKs that have
none generate a random 32-hex id per run. `attempts` starts at 1. `timeout_ms`
only when known. The job's exception, if any, is sent as a separate
`type=exception` event (Laravel already reports it through the exception
handler; Go/Python helpers send it themselves).

**cron** — one phase of one scheduled-task run.
```json
"cron":{"name":"invoices:send-reminders","expr":"0 9 * * 1-5","tz":"Asia/Ho_Chi_Minh","phase":"fail","exit_code":1,"duration_ms":14000,"output":"…last lines of output…"}
```
| phase | level | message | fields |
|---|---|---|---|
| `start` | info | `Scheduled task started: {name}` | — |
| `success` | info | `Scheduled task succeeded: {name} ({duration_ms}ms)` | `exit_code` 0, `duration_ms` |
| `fail` | error | `Scheduled task failed: {name} (exit {exit_code}, {duration_ms}ms)` | `exit_code` (default 1), `duration_ms`, optional `output` |
| `skip` | info | `Scheduled task skipped: {name}` | — |

SDKs send `expr` (5-field cron, or 6 with seconds) and `tz` (IANA) on every
event when they know them. `output` keeps the **tail** (last bytes) of the
output / error text.

**heartbeat** — worker liveness, every `MT_HEARTBEAT_SECONDS`.
```json
"heartbeat":{"queues":["emails","default"],"state":"busy","job":"App\\Jobs\\SendInvoiceEmail","job_started_at":"2026-09-21T14:31:05.120+07:00","memory_mb":96.5,"processed":540}
```
Level `debug`, message `Worker heartbeat: {state}`. `job`/`job_started_at`
only when `state=busy`. `processed` = jobs finished by this process since start.

## 2. Line encoding and size limits

- Exactly one line: no pretty printing, `\n`/`\r` inside strings are JSON
  escaped, the line ends with a single `\n`. Invalid UTF-8 is replaced
  (U+FFFD); no SDK ever fails because of encoding — unencodable values become
  strings.
- Target ≤ **16 KiB** per line (container runtimes split longer lines),
  hard limit **32 KiB** for every transport. Algorithm, applied in order:
  1. Always: `message` ≤ 8 KiB, `exception.message` ≤ 4 KiB, `cron.output`
     ≤ 8 KiB (tail kept), `frames` ≤ 50.
  2. If the encoded line > 16 KiB: `message` ≤ 2 KiB, `exception.message`
     ≤ 1 KiB, `cron.output` ≤ 2 KiB (tail), every string inside `context`
     ≤ 256 bytes.
  3. If still > 32 KiB: `context` becomes `{"_truncated":true}`, `frames` ≤ 20.
  4. If still > 32 KiB: drop the event and count it as dropped.
- Truncation cuts at a UTF-8 boundary and appends `…` (head kept) or prepends
  `…` (tail kept, `cron.output` only).

## 3. Context masking

`context` is arbitrary JSON supplied by the app (log context, exception
context, request info). The `context` object itself is depth 1; any object or
array nested deeper than depth 5 is replaced by the string `"[depth]"`
(scalars are kept). Before encoding, every key at every depth is lower-cased
with `-` turned into `_`; if it **contains** any of

```
password  passwd  secret  token  authorization  api_key  apikey  cookie  card  cvv
```

its value is replaced by `"***"`. Objects the language cannot encode become
strings (class name, `__toString()`, `str()`, `err.Error()`).

## 4. Transports

Chosen with `MT_TRANSPORT`. The SDK never does network I/O on the request /
job path.

### stream (default)
One `write` of one line to stderr (or stdout with `MT_STREAM=stdout`). The
collector reads pod logs through the Kubernetes API and fast-paths `{"_mt":`
lines. No network, no buffering, no background thread. Writes are serialized
with a mutex so lines never interleave.

PHP-FPM note: FPM only forwards worker stderr with `catch_workers_output = yes`;
also set `decorate_workers_output = no` (no `child N said into stderr` prefix)
and raise `log_limit` to `32768` (default 1024 splits lines).

### file
Append one line to `MT_FILE` (opened in append mode, one write per line,
`flock` in PHP) for VMs; ship it with Vector / Fluent Bit to the HTTP ingest
endpoint or let the collector tail it. Open/write errors are counted and the
event dropped.

### http (last resort)
```
POST {MT_ENDPOINT}/api/v1/logs/ingest
Authorization: Bearer {MT_TOKEN}
Content-Type: application/x-ndjson
Content-Encoding: gzip            (optional)
Idempotency-Key: 5f0c2a7e9b1d4c33 (optional, random per batch)

{"_mt":1,"type":"log",...}\n{"_mt":1,"type":"job",...}\n
```
| response | SDK action |
|---|---|
| 202 | accepted |
| 401 | bad token — drop batch |
| 413 | too large — drop batch |
| 429 | rate limited — drop batch |
| 503 | backend busy — drop batch |
| other / timeout | drop batch |
| connection refused/reset before a response | one quick retry (≈200 ms later, same `Idempotency-Key`), then drop |

Server limits: 5 MiB body (decompressed), 10 000 lines, 256 KiB per line.

SDK behaviour:
- Events go into a bounded in-memory queue (`MT_QUEUE_SIZE`, default 10 000;
  Laravel 1 000 per PHP process). When full the **newest** event is dropped
  and counted. Enqueue never blocks.
- A background worker (goroutine / daemon thread) flushes every **2 s** or when
  **500** events are queued; a batch is ≤ 500 lines and ≤ 4 MiB uncompressed.
- Timeout **2 s** per request (Laravel: 0.5 s connect, 1 s total). No retry
  loops, no backoff queues: a failed batch is dropped and counted.
- Laravel (no threads) buffers during the request and sends once from
  `app()->terminating()` (after the response under FPM); queue workers flush
  from the `Looping` event every 2 s / 500 events.
- `flush(timeout)` / `close(timeout)` send what is queued and return by the
  deadline even if the server hangs. Python registers an `atexit` flush.

## 5. Configuration

Same environment variables in every SDK (options passed in code win):

| env | default | meaning |
|---|---|---|
| `MT_ENABLED` | `true` | `false`/`0`/`off` turns every call into a no-op |
| `MT_TRANSPORT` | `stream` | `stream` \| `file` \| `http` |
| `MT_STREAM` | `stderr` | `stderr` \| `stdout` (stream transport) |
| `MT_FILE` | `monitor-track.jsonl` (Laravel: `storage/logs/monitor-track.jsonl`) | file transport path |
| `MT_ENDPOINT` | — | base URL for http, e.g. `https://monitor.example.com` |
| `MT_TOKEN` | — | source push token (http) |
| `MT_APP` | framework app name / process name | `app` field |
| `MT_ENV` | framework environment | `env` field |
| `MT_RELEASE` | — | `release` field |
| `MT_SAMPLE_RATE` | `1` | 0..1, applies to `type=log` only |
| `MT_QUEUE_SIZE` | `10000` (Laravel `1000`) | http queue bound |
| `MT_HEARTBEAT_SECONDS` | `15` | worker heartbeat interval |

`MT_TRANSPORT=http` without `MT_ENDPOINT` falls back to `stream`.

## 6. Safety contract ("never affect the host application")

1. No public entry point throws / raises / panics. Internal errors are
   swallowed and counted (`errors`).
2. No network I/O on the request or job path. Default transport is local.
3. Bounded memory: fixed-size queue, drop newest on overflow (`dropped`).
4. Sampling for `type=log` (`sampled`); exception, job, cron and heartbeat
   events are never sampled.
5. Shutdown `close`/`flush` always returns by its timeout.
6. The SDK observes, it does not change control flow: an app exception inside
   a job/cron helper is recorded and then propagates exactly as before (Go
   helpers re-panic a recovered panic). The Go HTTP middleware is the one
   documented exception: it turns a handler panic into a 500 when nothing was
   written yet (see `sdk/go/README.md`), as `net/http` would otherwise just
   drop the connection.
7. The SDK never logs through the host's logger (no feedback loops); log
   handlers ignore records from the SDK's own logger.

Every SDK exposes counters: `emitted` (written / accepted into the queue),
`dropped` (queue full, too large, failed HTTP batch), `sampled` (log events
skipped by sampling), `errors` (swallowed internal errors).

## 7. Scheduled tasks (cron) and auto-registration

- Task identity is `(app, name)`; the backend stores it as
  `sdk:{app}/{name}`.
- The first `cron` event with an `expr` for an unknown `(app, name)`
  **registers** the task with that schedule and `tz` (UTC if absent). Later
  events with a different `expr`/`tz` update it. Events without `expr`
  (e.g. Celery beat, where the schedule is not visible to the worker) still
  create the task, without missed-run detection until a schedule is set.
- Each run: `start`, then `success` or `fail` (or a lone `skip`). A run is
  keyed by **name + scheduled minute**: for `start`/`skip` the slot is
  `floor_minute(ts)`; for `success`/`fail` it is
  `floor_minute(ts − duration_ms)` — so finish events pair with their start
  without client state, even across pods.
- A finish without `duration_ms` (Laravel `runInBackground()` tasks report
  from a separate `schedule:finish` process) pairs with the most recent open
  `start` of the same task.
- A `start` with no finish is marked unfinished after the task's grace
  period; an expected slot with no `start` is a missed run.

```
{"_mt":1,"type":"cron","ts":"2026-09-21T09:00:00.031+07:00","level":"info","message":"Scheduled task started: invoices:send-reminders",...,"cron":{"name":"invoices:send-reminders","expr":"0 9 * * 1-5","tz":"Asia/Ho_Chi_Minh","phase":"start"}}
{"_mt":1,"type":"cron","ts":"2026-09-21T09:00:14.052+07:00","level":"info","message":"Scheduled task succeeded: invoices:send-reminders (14021ms)",...,"cron":{"name":"invoices:send-reminders","expr":"0 9 * * 1-5","tz":"Asia/Ho_Chi_Minh","phase":"success","exit_code":0,"duration_ms":14021}}
```

## 8. Queue jobs, in-flight work and workers

- A job run is identified by `job.id` + `attempts`. `start` opens it;
  `done`, `failed` or `retry` closes it. `retry` means released back to the
  queue — a new `start` with `attempts+1` follows.
- **In flight** = opened runs not yet closed. A worker is the `(app, host, pid)`
  that sent the `start`.
- Heartbeats describe the worker: queues, `busy`/`idle`, current job and when
  it started, memory, processed count. The worker status page shows one row per
  `(app, host, pid)` with its last heartbeat.
- A worker with no heartbeat for 3 × interval (45 s by default) is
  considered gone; its in-flight runs are marked **lost** (typically OOMKilled
  or evicted pods). Exception: single-threaded workers (PHP) cannot send
  heartbeats while a job runs, so a worker whose last heartbeat or `job`
  event says it is busy stays alive until `job_started_at` + `timeout_ms`
  (+ 3 × interval); without `timeout_ms`, the backend assumes 1 hour. A run past
  `timeout_ms` while its worker is alive is **stuck**.

```
{"_mt":1,"type":"job","ts":"2026-09-21T14:31:05.120+07:00","level":"info","message":"Job started: App\\Jobs\\SendInvoiceEmail","app":"billing-api","host":"billing-worker-5c7d9-abcde","pid":12,"job":{"queue":"emails","connection":"redis","class":"App\\Jobs\\SendInvoiceEmail","id":"9b1c6c0e-5a0b-4f7e-9d3e-3f1f0e6c2a11","status":"start","attempts":1,"timeout_ms":10000}}
{"_mt":1,"type":"job","ts":"2026-09-21T14:31:06.940+07:00","level":"info","message":"Job done: App\\Jobs\\SendInvoiceEmail (1820ms)","app":"billing-api","host":"billing-worker-5c7d9-abcde","pid":12,"job":{"queue":"emails","connection":"redis","class":"App\\Jobs\\SendInvoiceEmail","id":"9b1c6c0e-5a0b-4f7e-9d3e-3f1f0e6c2a11","status":"done","runtime_ms":1820,"attempts":1,"timeout_ms":10000}}
{"_mt":1,"type":"heartbeat","ts":"2026-09-21T14:31:20.000+07:00","level":"debug","message":"Worker heartbeat: idle","app":"billing-api","host":"billing-worker-5c7d9-abcde","pid":12,"heartbeat":{"queues":["emails"],"state":"idle","memory_mb":96.5,"processed":540}}
```

## 9. Log example

```
{"_mt":1,"type":"log","ts":"2026-09-21T14:31:07.412+07:00","level":"warning","message":"Payment retry scheduled","app":"billing-api","env":"production","host":"billing-api-7d9f8c6b5-x2kqp","pid":41,"trace_id":"4bf92f3577b34da6a3ce929d0e0e4736","route":"POST /api/v1/payments","context":{"order_id":991,"attempt":2}}
```
