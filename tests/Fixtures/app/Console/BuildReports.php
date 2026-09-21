<?php

namespace MonitorTrack\Tests\Fixtures\App\Console;

use Illuminate\Console\Command;
use MonitorTrack\Tests\Fixtures\App\Services\CustomerLookup;

class BuildReports extends Command
{
    protected $signature = 'reports:build';

    protected $description = 'Fixture command with an N+1 loop';

    public function handle(CustomerLookup $lookup): int
    {
        $lookup->names(range(1, 12));

        return 0;
    }
}
