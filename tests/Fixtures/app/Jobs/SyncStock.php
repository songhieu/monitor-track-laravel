<?php

namespace MonitorTrack\Tests\Fixtures\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use MonitorTrack\Tests\Fixtures\App\Services\CustomerLookup;

class SyncStock implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * @param  list<int>  $ids
     */
    public function __construct(public array $ids = [])
    {
    }

    public function handle(CustomerLookup $lookup): void
    {
        $lookup->names($this->ids);
    }
}
