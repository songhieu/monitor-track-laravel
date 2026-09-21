<?php

namespace MonitorTrack\Tests\Fixtures\App\Services;

class PaymentGateway
{
    public function post(): void
    {
        throw new \RuntimeException('card declined');
    }
}
