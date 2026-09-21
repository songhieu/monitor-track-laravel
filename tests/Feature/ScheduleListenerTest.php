<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use MonitorTrack\Listeners\ScheduleListener;
use MonitorTrack\Tests\TestCase;

class ScheduleListenerTest extends TestCase
{
    private function task(string $command = "'/usr/bin/php8.3' 'artisan' invoices:send-reminders", string $expr = '0 9 * * 1-5', $tz = 'Asia/Ho_Chi_Minh'): Event
    {
        $task = new Event($this->createStub(EventMutex::class), $command, $tz);
        $task->expression = $expr;

        return $task;
    }

    public function test_successful_run_emits_start_and_success(): void
    {
        $memory = $this->fake();
        $task = $this->task();

        event(new ScheduledTaskStarting($task));
        usleep(10_000);
        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, 0.01));

        $events = $memory->events('cron');
        $this->assertCount(2, $events);
        $this->assertSame([
            'name' => 'invoices:send-reminders',
            'expr' => '0 9 * * 1-5',
            'tz' => 'Asia/Ho_Chi_Minh',
            'phase' => 'start',
        ], $events[0]['cron']);
        $this->assertSame('Scheduled task started: invoices:send-reminders', $events[0]['message']);

        $this->assertSame('success', $events[1]['cron']['phase']);
        $this->assertSame(0, $events[1]['cron']['exit_code']);
        $this->assertGreaterThanOrEqual(10, $events[1]['cron']['duration_ms']);
        $this->assertMatchesRegularExpression('/^Scheduled task succeeded: invoices:send-reminders \(\d+ms\)$/', $events[1]['message']);
    }

    public function test_non_zero_exit_code_is_one_fail_even_if_failed_follows(): void
    {
        $memory = $this->fake();
        $task = $this->task();

        event(new ScheduledTaskStarting($task));
        $task->exitCode = 2;
        event(new ScheduledTaskFinished($task, 0.5));
        // Laravel 10 throws after a non-zero exit, which fires Failed too.
        event(new ScheduledTaskFailed($task, new \Exception('exit code [2]')));

        $events = $memory->events('cron');
        $this->assertSame(['start', 'fail'], array_column(array_column($events, 'cron'), 'phase'));
        $this->assertSame(2, $events[1]['cron']['exit_code']);
        $this->assertSame('error', $events[1]['level']);
    }

    public function test_exception_in_task_emits_fail_with_output(): void
    {
        $memory = $this->fake();
        $task = new CallbackEvent($this->createStub(EventMutex::class), fn () => null, [], 'UTC');
        $task->expression = '*/5 * * * *';
        $task->description = 'reports:rebuild';

        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFailed($task, new \RuntimeException('disk full')));

        $fail = $memory->events('cron')[1];
        $this->assertSame('reports:rebuild', $fail['cron']['name']);
        $this->assertSame('UTC', $fail['cron']['tz']);
        $this->assertSame('fail', $fail['cron']['phase']);
        $this->assertSame(1, $fail['cron']['exit_code']);
        $this->assertSame('RuntimeException: disk full', $fail['cron']['output']);
        $this->assertArrayHasKey('duration_ms', $fail['cron']);
    }

    public function test_skipped_task(): void
    {
        $memory = $this->fake();

        event(new ScheduledTaskSkipped($this->task()));

        $event = $memory->events('cron')[0];
        $this->assertSame('skip', $event['cron']['phase']);
        $this->assertSame('Scheduled task skipped: invoices:send-reminders', $event['message']);
        $this->assertArrayNotHasKey('exit_code', $event['cron']);
    }

    public function test_default_timezone_and_names(): void
    {
        $memory = $this->fake();
        event(new ScheduledTaskStarting($this->task('php artisan backup:run --only-db', '0 2 * * *', null)));

        $cron = $memory->events('cron')[0]['cron'];
        $this->assertSame('backup:run --only-db', $cron['name']);
        $this->assertSame('Asia/Ho_Chi_Minh', $cron['tz'], 'falls back to app.timezone');

        $this->assertSame('node /srv/sync.js', ScheduleListener::name($this->task('node /srv/sync.js')));
        $closure = new CallbackEvent($this->createStub(EventMutex::class), fn () => null);
        $this->assertMatchesRegularExpression('/^closure:ScheduleListenerTest\.php:\d+$/', ScheduleListener::name($closure));
        $timezone = $this->task('php artisan x', '* * * * *', new \DateTimeZone('Europe/Berlin'));
        $this->assertSame('Europe/Berlin', (new ScheduleListener($this->client()))->describe($timezone)['tz']);
    }

    public function test_output_tail_is_attached_to_failures(): void
    {
        $memory = $this->fake();
        $task = $this->task();
        $task->output = tempnam(sys_get_temp_dir(), 'mt');
        file_put_contents($task->output, str_repeat("noise\n", 5000)."Error: SMTP 421\n");

        event(new ScheduledTaskStarting($task));
        $task->exitCode = 1;
        event(new ScheduledTaskFinished($task, 1.0));
        @unlink($task->output);

        $output = $memory->events('cron')[1]['cron']['output'];
        $this->assertStringEndsWith("Error: SMTP 421\n", $output);
        $this->assertLessThanOrEqual(8192, strlen($output));
    }
}
