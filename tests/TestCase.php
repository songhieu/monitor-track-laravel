<?php

namespace MonitorTrack\Tests;

use MonitorTrack\Client;
use MonitorTrack\MonitorTrackServiceProvider;
use MonitorTrack\Transport\MemoryTransport;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MonitorTrackServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['MonitorTrack' => \MonitorTrack\Facades\MonitorTrack::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.name', 'billing-api');
        $app['config']->set('app.timezone', 'Asia/Ho_Chi_Minh');
        $app['config']->set('monitor-track.release', '2026.09.21-2');
    }

    protected function client(): Client
    {
        return $this->app->make(Client::class);
    }

    /**
     * Route the app's client into memory and return the captured lines.
     */
    protected function fake(): MemoryTransport
    {
        return $this->client()->fake();
    }
}
