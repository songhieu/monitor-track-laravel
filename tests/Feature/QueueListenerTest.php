<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\Looping;
use MonitorTrack\Tests\Fixtures\TestQueueJob;
use MonitorTrack\Tests\TestCase;

class QueueListenerTest extends TestCase
{
    public function test_successful_job_emits_start_then_done_with_runtime(): void
    {
        $memory = $this->fake();
        $job = TestQueueJob::make();

        event(new JobProcessing('redis', $job));
        usleep(20_000);
        event(new JobProcessed('redis', $job));

        $events = $memory->events('job');
        $this->assertCount(2, $events);

        $this->assertSame([
            'queue' => 'emails',
            'connection' => 'redis',
            'class' => 'App\\Jobs\\SendInvoiceEmail',
            'id' => '9b1c6c0e-5a0b-4f7e-9d3e-3f1f0e6c2a11',
            'status' => 'start',
            'attempts' => 1,
            'timeout_ms' => 10000,
        ], $events[0]['job']);
        $this->assertSame('Job started: App\\Jobs\\SendInvoiceEmail', $events[0]['message']);

        $this->assertSame('done', $events[1]['job']['status']);
        $this->assertGreaterThanOrEqual(20, $events[1]['job']['runtime_ms']);
        $this->assertLessThan(1000, $events[1]['job']['runtime_ms']);
        $this->assertSame('info', $events[1]['level']);
    }

    public function test_exception_then_release_emits_exactly_one_retry(): void
    {
        if (! class_exists(JobReleasedAfterException::class)) {
            $this->markTestSkipped('JobReleasedAfterException arrived in Laravel 9.x');
        }
        $memory = $this->fake();
        $job = TestQueueJob::make('retry-uuid', 1);

        event(new JobProcessing('redis', $job));
        event(new JobExceptionOccurred('redis', $job, new \RuntimeException('smtp timeout')));
        $job->release(10);
        event(new JobReleasedAfterException('redis', $job));

        $statuses = array_map(fn ($e) => $e['job']['status'], $memory->events('job'));
        $this->assertSame(['start', 'retry'], $statuses);
        $this->assertSame('warning', $memory->events('job')[1]['level']);

        // The next attempt is a new run with attempts + 1.
        $next = TestQueueJob::make('retry-uuid', 2);
        event(new JobProcessing('redis', $next));
        event(new JobProcessed('redis', $next));

        $jobs = $memory->events('job');
        $this->assertSame(['start', 'retry', 'start', 'done'], array_map(fn ($e) => $e['job']['status'], $jobs));
        $this->assertSame(2, $jobs[3]['job']['attempts']);
    }

    public function test_final_failure_emits_exactly_one_failed(): void
    {
        $memory = $this->fake();
        $job = TestQueueJob::make('fail-uuid', 3);
        $e = new \RuntimeException('smtp down');

        event(new JobProcessing('redis', $job));
        $job->markAsFailed();
        event(new JobFailed('redis', $job, $e));
        event(new JobExceptionOccurred('redis', $job, $e));

        $jobs = $memory->events('job');
        $this->assertSame(['start', 'failed'], array_map(fn ($e) => $e['job']['status'], $jobs));
        $this->assertSame('error', $jobs[1]['level']);
        $this->assertSame(3, $jobs[1]['job']['attempts']);
        $this->assertArrayHasKey('runtime_ms', $jobs[1]['job']);
    }

    public function test_manual_fail_inside_handle_is_not_double_closed(): void
    {
        $memory = $this->fake();
        $job = TestQueueJob::make('manual-uuid');

        event(new JobProcessing('redis', $job));
        event(new JobFailed('redis', $job, new \RuntimeException('manual')));
        event(new JobProcessed('redis', $job));

        $this->assertSame(['start', 'failed'], array_map(fn ($e) => $e['job']['status'], $memory->events('job')));
    }

    public function test_job_without_timeout_omits_timeout_ms(): void
    {
        $memory = $this->fake();
        $job = TestQueueJob::make('no-timeout', 1, null);

        event(new JobProcessing('redis', $job));

        $this->assertArrayNotHasKey('timeout_ms', $memory->events('job')[0]['job']);
    }

    public function test_looping_sends_throttled_heartbeats(): void
    {
        config(['monitor-track.heartbeat_seconds' => 15]);
        $memory = $this->fake();

        event(new Looping('redis', 'emails,default'));
        event(new Looping('redis', 'emails,default'));

        $beats = $memory->events('heartbeat');
        $this->assertCount(1, $beats, 'second Looping within the interval is throttled');
        $this->assertSame(['emails', 'default'], $beats[0]['heartbeat']['queues']);
        $this->assertSame('idle', $beats[0]['heartbeat']['state']);
        $this->assertSame(0, $beats[0]['heartbeat']['processed']);
        $this->assertIsFloat($beats[0]['heartbeat']['memory_mb']);
        $this->assertSame('Worker heartbeat: idle', $beats[0]['message']);
    }

    public function test_a_sync_job_outside_a_worker_sends_no_heartbeat(): void
    {
        $memory = $this->fake();
        $job = TestQueueJob::make();

        // dispatch_sync() in a web request or an Octane worker.
        event(new JobProcessing('sync', $job));
        event(new JobProcessed('sync', $job));

        $this->assertCount(2, $memory->events('job'));
        $this->assertSame([], $memory->events('heartbeat'), 'that process is not a queue worker');

        event(new Looping('redis', 'default'));
        $this->assertCount(1, $memory->events('heartbeat'));
    }

    public function test_listener_errors_never_reach_the_worker(): void
    {
        $memory = $this->fake();
        $job = new class(['uuid' => 'broken', 'displayName' => 'App\\Jobs\\Broken', 'job' => 'x'], 1) extends TestQueueJob
        {
            public function timeout()
            {
                throw new \RuntimeException('corrupt payload');
            }

            public function getQueue()
            {
                throw new \RuntimeException('no queue');
            }
        };

        event(new JobProcessing('redis', $job));
        event(new JobProcessed('redis', $job));

        $this->assertSame(['start', 'done'], array_map(fn ($e) => $e['job']['status'], $memory->events('job')));
    }
}
