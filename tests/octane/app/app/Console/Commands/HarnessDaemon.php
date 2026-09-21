<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use MonitorTrack\Facades\MonitorTrack;

/**
 * A daemon like distribution:supervisor: a loop that polls with repeated
 * queries and logs, with no queue worker events.
 */
class HarnessDaemon extends Command
{
    protected $signature = 'harness:daemon {seconds}';

    protected $description = 'Loop once a second for the given time';

    public function handle()
    {
        for ($i = 1; $i <= (int) $this->argument('seconds'); $i++) {
            foreach (Order::all() as $order) {
                $order->customer->name;
            }
            MonitorTrack::log('info', "daemon loop {$i}");
            sleep(1);
        }

        return 0;
    }
}
