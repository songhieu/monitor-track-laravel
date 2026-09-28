<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use MonitorTrack\Listeners\ScheduleListener;
use MonitorTrack\Stats;
use MonitorTrack\Tests\TestCase;
use MonitorTrack\Transport\HttpTransport;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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

    public function test_background_task_finishes_from_schedule_finish_with_its_exit_code(): void
    {
        $memory = $this->fake();

        foreach ([3, 0] as $exit) {
            // schedule:run starts the task in the background and moves on.
            $task = $this->task()->runInBackground()->withoutOverlapping();
            event(new ScheduledTaskStarting($task));
            event(new ScheduledTaskFinished($task, 0.02));

            // The `schedule:finish {mutex} {exit}` process: the same task
            // definition, a new object; Event::finish() set the exit code.
            $finished = $this->task()->runInBackground()->withoutOverlapping();
            $finished->exitCode = $exit;
            event(new ScheduledBackgroundTaskFinished($finished));
        }

        $events = $memory->events('cron');
        $this->assertSame(['start', 'fail', 'start', 'success'], array_column(array_column($events, 'cron'), 'phase'));
        $this->assertSame(3, $events[1]['cron']['exit_code']);
        $this->assertSame('Scheduled task failed: invoices:send-reminders (exit 3)', $events[1]['message']);
        $this->assertArrayNotHasKey('duration_ms', $events[1]['cron'], 'the start time is in another process');
        $this->assertSame(0, $events[3]['cron']['exit_code']);
        foreach ($events as $event) {
            $this->assertSame(['invoices:send-reminders', '0 9 * * 1-5', 'Asia/Ho_Chi_Minh'],
                [$event['cron']['name'], $event['cron']['expr'], $event['cron']['tz']]);
        }
    }

    public function test_http_buffer_leaves_when_the_artisan_process_terminates(): void
    {
        // schedule:run and schedule:finish are artisan commands: artisan
        // calls the kernel's terminate(), which runs the terminating callbacks.
        $posts = [];
        $this->client()->setTransport(new HttpTransport('http://ingest.test', 'tok', 1000, new Stats, 500, 1000,
            function (string $url, array $headers, string $body) use (&$posts) {
                $posts[] = array_column(array_map(fn ($l) => json_decode($l, true)['cron'], explode("\n", rtrim((string) gzdecode($body)))), 'phase');

                return ['status' => 202, 'retryable' => false];
            }));

        $task = $this->task();
        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskSkipped($this->task('php artisan reports:build')));
        $this->assertSame([], $posts);

        $this->app->terminate();

        $this->assertSame([['start', 'skip']], $posts);
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

    private function scheduleRunStarts(): array
    {
        $before = intdiv(time(), 60) * 60;
        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput()));

        return [$before, intdiv(time(), 60) * 60];
    }

    public function test_start_and_skip_carry_the_minute_schedule_run_started(): void
    {
        $memory = $this->fake();
        $minutes = $this->scheduleRunStarts();
        $task = $this->task();

        event(new ScheduledTaskStarting($task));
        $task->exitCode = 0;
        event(new ScheduledTaskFinished($task, 0.01));
        event(new ScheduledTaskSkipped($this->task('php artisan other:thing')));

        [$start, $success, $skip] = array_column($memory->events('cron'), 'cron');
        $this->assertContains($start['scheduled'], $minutes);
        $this->assertSame(0, $start['scheduled'] % 60);
        $this->assertSame($start['scheduled'], $skip['scheduled']);
        $this->assertArrayNotHasKey('scheduled', $success, 'finish events pair by their start');
    }

    public function test_other_commands_and_sub_minute_tasks_carry_no_slot(): void
    {
        $memory = $this->fake();
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput()));
        event(new ScheduledTaskStarting($this->task()));
        $this->assertArrayNotHasKey('scheduled', $memory->events('cron')[0]['cron']);

        $this->scheduleRunStarts();
        $fast = $this->task('php artisan ticks:poll', '* * * * *');
        if (! method_exists($fast, 'everyThirtySeconds')) {
            $this->markTestSkipped('no sub-minute tasks before Laravel 10.x');
        }
        $fast->everyThirtySeconds();
        event(new ScheduledTaskStarting($fast));
        $this->assertArrayNotHasKey('scheduled', $memory->events('cron')[1]['cron']);
    }

    public function test_schedule_is_listed_for_the_current_environment(): void
    {
        $memory = $this->fake();
        $schedule = new Schedule('UTC');
        $schedule->command('invoices:send-reminders')->weekdays()->at('09:00');
        $schedule->command('reports:rebuild --fast')->everyFiveMinutes()->timezone('Asia/Ho_Chi_Minh');
        $schedule->command('staging:only')->daily()->environments('staging');
        $schedule->call(fn () => null)->name('prune-sessions')->hourly();

        (new ScheduleListener($this->client()))->listSchedule($schedule, 'production');

        $events = $memory->events('cron');
        $this->assertCount(1, $events);
        $this->assertSame('list', $events[0]['cron']['phase']);
        $this->assertSame('debug', $events[0]['level']);
        $this->assertSame('Schedule listed: 3 tasks', $events[0]['message']);
        $this->assertSame([
            ['name' => 'invoices:send-reminders', 'expr' => '0 9 * * 1-5', 'tz' => 'UTC'],
            ['name' => 'reports:rebuild --fast', 'expr' => '*/5 * * * *', 'tz' => 'Asia/Ho_Chi_Minh'],
            ['name' => 'prune-sessions', 'expr' => '0 * * * *', 'tz' => 'UTC'],
        ], $events[0]['cron']['tasks']);
    }

    public function test_long_schedule_is_listed_in_parts_under_the_line_target(): void
    {
        $memory = $this->fake();
        $schedule = new Schedule('UTC');
        for ($i = 0; $i < 300; $i++) {
            $schedule->command("sync:marketplace-orders --platform={$i} --chunk=500 --with-refunds --since=2days")->everyMinute();
        }

        (new ScheduleListener($this->client()))->listSchedule($schedule);

        $events = $memory->events('cron');
        $this->assertGreaterThan(1, count($events));
        $names = [];
        foreach ($events as $i => $event) {
            $this->assertSame('list', $event['cron']['phase']);
            $this->assertStringEndsWith('('.($i + 1).'/'.count($events).')', $event['message']);
            $this->assertLessThan(16384, strlen(json_encode($event)));
            $names = array_merge($names, array_column($event['cron']['tasks'], 'name'));
        }
        $this->assertCount(300, $names);
        $this->assertSame('sync:marketplace-orders --platform=299 --chunk=500 --with-refunds --since=2days', end($names));
    }

    public function test_schedule_run_lists_the_schedule_every_five_minutes(): void
    {
        $memory = $this->fake();
        $this->app->make(Schedule::class)->command('invoices:send-reminders')->daily();

        $minute = $this->scheduleRunStarts();
        $lists = array_filter($memory->events('cron'), fn ($e) => $e['cron']['phase'] === 'list');
        if (intdiv($minute[0], 60) % 5 === 0 && $minute[0] === $minute[1]) {
            $this->assertCount(1, $lists);
            $this->assertContains('invoices:send-reminders', array_column(reset($lists)['cron']['tasks'], 'name'));
        } elseif (intdiv($minute[1], 60) % 5 !== 0 && $minute[0] === $minute[1]) {
            $this->assertCount(0, $lists);
        } else {
            $this->addToAssertionCount(1); // crossed a minute: either is right
        }
    }
}
