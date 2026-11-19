<?php

namespace MonitorTrack\Tests\Feature;

use MonitorTrack\Tests\TestCase;
use MonitorTrack\Transport\HttpTransport;

class HttpTimeoutTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('monitor-track.transport', 'http');
        $app['config']->set('monitor-track.endpoint', 'http://127.0.0.1:9');
        $app['config']->set('monitor-track.token', 't');
        $app['config']->set('monitor-track.spool.enabled', false);
        // A config published before 0.2.3: no console_timeout_ms key.
        $app['config']->set('monitor-track.http', ['connect_timeout_ms' => 500, 'timeout_ms' => 1500]);
    }

    private function http(): HttpTransport
    {
        $transport = $this->client()->transport();
        $this->assertInstanceOf(HttpTransport::class, $transport);

        return $transport;
    }

    public function test_console_processes_get_the_longer_timeout_until_a_worker_loop_starts(): void
    {
        $this->assertSame(5000, $this->http()->timeoutMs(), 'artisan commands and the scheduler');

        event(new \Illuminate\Queue\Events\Looping('redis', 'default'));
        $this->assertSame(1500, $this->http()->timeoutMs(), 'queue workers send between jobs');
    }

    public function test_horizon_loops_use_the_short_timeout(): void
    {
        event('Laravel\Horizon\Events\SupervisorLooped');

        $this->assertSame(1500, $this->http()->timeoutMs());
    }

    public function test_the_spool_can_be_turned_off(): void
    {
        $this->assertNull($this->http()->spool());
    }
}
