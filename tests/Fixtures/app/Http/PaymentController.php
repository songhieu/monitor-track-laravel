<?php

namespace MonitorTrack\Tests\Fixtures\App\Http;

use MonitorTrack\Tests\Fixtures\App\Services\PaymentGateway;
use MonitorTrack\Tests\Fixtures\Vendor\HttpClient;

class PaymentController
{
    public function store(): void
    {
        $post = function () {
            (new PaymentGateway)->post();
        };
        // One-line call: PHP 8.1 reports a multi-line call at its last line.
        (new HttpClient)->send($post);
    }
}
