<?php

namespace MonitorTrack\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use MonitorTrack\Listeners\QueryListener;
use MonitorTrack\Tests\Fixtures\TestQueueJob;
use MonitorTrack\Tests\TestCase;
use MonitorTrack\Transport\NullTransport;

class DisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('monitor-track.enabled', 'false');
    }

    public function test_disabled_sdk_is_a_no_op(): void
    {
        $client = $this->client();
        $this->assertFalse($client->isEnabled());
        $this->assertInstanceOf(NullTransport::class, $client->transport());

        Log::channel('monitor-track')->error('ignored', ['exception' => new \RuntimeException('x')]);
        event(new JobProcessing('redis', TestQueueJob::make()));
        report(new \RuntimeException('ignored'));

        $this->assertSame(['emitted' => 0, 'dropped' => 0, 'sampled' => 0, 'errors' => 0], $client->stats());
        $this->assertFalse($this->app['events']->hasListeners(QueryExecuted::class));
        $this->assertFalse($this->app->bound(QueryListener::class));
        // Artisan::output() rather than expectsOutputToContain(), which Laravel 9.0 lacks.
        $this->withoutMockingConsoleOutput();
        $this->assertSame(0, Artisan::call('mt:test'));
        $this->assertStringContainsString('disabled', Artisan::output());
    }
}
