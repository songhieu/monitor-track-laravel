<?php

namespace MonitorTrack\Listeners;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Events\Dispatcher;
use MonitorTrack\Client;
use MonitorTrack\Support\EnvelopeEncoder;

/**
 * Laravel scheduler events → cron start/success/fail/skip. Every event
 * carries the task's expression and timezone, so the backend registers the
 * task on first sight (docs/sdk-spec.md §7).
 *
 * Event order in `schedule:run`:
 *   ok:           Starting → Finished (exitCode 0 / null for callbacks)
 *   exit code ≠0: Starting → Finished (exitCode ≠ 0)
 *   exception:    Starting → Failed
 *   background:   Starting → Finished (no exit code yet), later in the
 *                 `schedule:finish` process: ScheduledBackgroundTaskFinished
 */
final class ScheduleListener
{
    /** @var array<int, int|float> spl_object_id(task) => hrtime start */
    private array $started = [];

    private string $defaultTimezone;

    public function __construct(private readonly Client $client, ?string $defaultTimezone = null)
    {
        $this->defaultTimezone = $defaultTimezone ?: date_default_timezone_get();
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, [$this, 'starting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'finished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'failed']);
        $events->listen(ScheduledTaskSkipped::class, [$this, 'skipped']);

        if (class_exists(ScheduledBackgroundTaskFinished::class)) {
            $events->listen(ScheduledBackgroundTaskFinished::class, [$this, 'backgroundFinished']);
        }
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        try {
            $this->started[spl_object_id($event->task)] = hrtime(true);
            $this->client->cron('start', $this->describe($event->task));
        } catch (\Throwable) {
        }
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        try {
            $task = $event->task;

            if ($task->runInBackground) {
                // Only spawned; the result arrives via schedule:finish.
                unset($this->started[spl_object_id($task)]);

                return;
            }

            $duration = $this->duration($task, $event->runtime);
            if ($duration === null) {
                return; // already closed
            }

            $exit = $task->exitCode === null ? 0 : (int) $task->exitCode;

            if ($exit === 0) {
                $this->client->cron('success', $this->describe($task) + ['exit_code' => 0, 'duration_ms' => $duration]);
            } else {
                $this->client->cron('fail', $this->describe($task) + [
                    'exit_code' => $exit,
                    'duration_ms' => $duration,
                    'output' => $this->outputTail($task),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        try {
            $task = $event->task;
            $duration = $this->duration($task, null);
            if ($duration === null) {
                return;
            }

            $e = $event->exception;
            $exit = is_numeric($task->exitCode) && (int) $task->exitCode !== 0 ? (int) $task->exitCode : 1;

            $this->client->cron('fail', $this->describe($task) + [
                'exit_code' => $exit,
                'duration_ms' => $duration,
                'output' => get_class($e).': '.$e->getMessage(),
            ]);
        } catch (\Throwable) {
        }
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        try {
            $this->client->cron('skip', $this->describe($event->task));
        } catch (\Throwable) {
        }
    }

    public function backgroundFinished(ScheduledBackgroundTaskFinished $event): void
    {
        try {
            // Runs in the `schedule:finish` process: the start time is not
            // known here, so duration_ms is omitted.
            $task = $event->task;
            $exit = $task->exitCode === null ? 0 : (int) $task->exitCode;

            if ($exit === 0) {
                $this->client->cron('success', $this->describe($task) + ['exit_code' => 0]);
            } else {
                $this->client->cron('fail', $this->describe($task) + [
                    'exit_code' => $exit,
                    'output' => $this->outputTail($task),
                ]);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Milliseconds since the matching Starting event, or $runtime (seconds)
     * when the start was not seen. Returns null when the run was already
     * closed, so each run gets exactly one finish event.
     */
    private function duration(Event $task, int|float|null $runtime): ?int
    {
        $id = spl_object_id($task);

        if (isset($this->started[$id])) {
            $ms = Client::elapsedMs($this->started[$id]);
            unset($this->started[$id]);

            return $ms;
        }

        if ($runtime !== null) {
            return (int) round($runtime * 1000);
        }

        return null;
    }

    /**
     * @return array{name:string, expr:string, tz:string}
     */
    public function describe(Event $task): array
    {
        return [
            'name' => self::name($task),
            'expr' => (string) $task->expression,
            'tz' => $this->timezone($task),
        ];
    }

    public static function name(Event $task): string
    {
        $description = trim((string) $task->description);
        if ($description !== '') {
            return EnvelopeEncoder::head($description, 200);
        }

        $command = trim((string) $task->command);
        if ($command !== '') {
            // "'/usr/bin/php8.3' 'artisan' invoices:send-reminders" → "invoices:send-reminders"
            if (preg_match('/(?:^|\s)[\'"]?artisan[\'"]?\s+(.+)$/s', $command, $m)) {
                $command = trim($m[1]);
            }

            return EnvelopeEncoder::head($command, 200);
        }

        if ($task instanceof CallbackEvent) {
            try {
                $callback = (new \ReflectionProperty(CallbackEvent::class, 'callback'))->getValue($task);
                if (is_string($callback)) {
                    return EnvelopeEncoder::head($callback, 200);
                }
                if (is_object($callback) && ! $callback instanceof \Closure) {
                    return get_class($callback);
                }
                if ($callback instanceof \Closure) {
                    $fn = new \ReflectionFunction($callback);

                    return 'closure:'.basename((string) $fn->getFileName()).':'.$fn->getStartLine();
                }
            } catch (\Throwable) {
            }
        }

        return 'closure';
    }

    private function timezone(Event $task): string
    {
        $tz = $task->timezone;

        if ($tz instanceof \DateTimeZone) {
            return $tz->getName();
        }

        if (is_string($tz) && $tz !== '') {
            return $tz;
        }

        return $this->defaultTimezone;
    }

    private function outputTail(Event $task): ?string
    {
        try {
            $path = (string) $task->output;
            if ($path === '' || $path === '/dev/null' || strtoupper($path) === 'NUL' || ! is_file($path) || ! is_readable($path)) {
                return null;
            }

            $size = filesize($path);
            if (! $size) {
                return null;
            }

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }

            try {
                fseek($handle, max(0, $size - 8192));
                $tail = stream_get_contents($handle);
            } finally {
                fclose($handle);
            }

            return is_string($tail) && $tail !== '' ? $tail : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
