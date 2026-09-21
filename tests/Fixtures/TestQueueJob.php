<?php

namespace MonitorTrack\Tests\Fixtures;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * Minimal queue job for driving the worker events in tests.
 */
class TestQueueJob extends Job implements JobContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private array $payloadData, private int $attemptCount = 1, string $queue = 'emails', string $connection = 'redis')
    {
        $this->queue = $queue;
        $this->connectionName = $connection;
    }

    public static function make(string $uuid = '9b1c6c0e-5a0b-4f7e-9d3e-3f1f0e6c2a11', int $attempts = 1, ?int $timeout = 10): self
    {
        return new self([
            'uuid' => $uuid,
            'displayName' => 'App\\Jobs\\SendInvoiceEmail',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'timeout' => $timeout,
            'data' => ['commandName' => 'App\\Jobs\\SendInvoiceEmail'],
        ], $attempts);
    }

    public function getJobId()
    {
        return 'redis-id-'.$this->payloadData['uuid'];
    }

    public function getRawBody()
    {
        return json_encode($this->payloadData);
    }

    public function attempts()
    {
        return $this->attemptCount;
    }
}
