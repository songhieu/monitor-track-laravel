<?php

namespace MonitorTrack\Tests\Fixtures\App\Console;

use Illuminate\Console\Command;
use MonitorTrack\Facades\MonitorTrack;
use MonitorTrack\Tests\Fixtures\App\Jobs\SyncStock;
use MonitorTrack\Tests\Fixtures\App\Services\CustomerLookup;

/**
 * Fixture daemon (one pass of its loop): polls with repeated queries, logs,
 * and runs a job.
 */
class DistributionSupervisor extends Command
{
    protected $signature = 'distribution:supervisor';

    protected $description = 'Fixture long-running command';

    /** @var (\Closure(): void)|null called between the two loop iterations (the test lets 2 s pass) */
    public static ?\Closure $between = null;

    public function handle(CustomerLookup $lookup): int
    {
        $lookup->names(range(1, 12));
        MonitorTrack::log('info', 'supervisor loop 1');

        if (self::$between !== null) {
            (self::$between)();
        }

        $lookup->names(range(1, 12));
        MonitorTrack::log('info', 'supervisor loop 2');

        dispatch(new SyncStock(range(1, 12)));

        return 0;
    }
}
