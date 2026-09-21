<?php

namespace MonitorTrack\Tests\Fixtures\Vendor;

class HttpClient
{
    public function send(callable $fn): void
    {
        $fn();
    }
}
