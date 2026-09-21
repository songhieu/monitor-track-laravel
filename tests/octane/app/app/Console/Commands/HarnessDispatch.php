<?php

namespace App\Console\Commands;

use App\Jobs\FailingJob;
use App\Jobs\LoadCustomers;
use Illuminate\Console\Command;

class HarnessDispatch extends Command
{
    protected $signature = 'harness:dispatch {count=10}';

    protected $description = 'Queue N+1 jobs and failing jobs';

    public function handle()
    {
        foreach (range(1, (int) $this->argument('count')) as $i) {
            dispatch($i % 5 === 0 ? new FailingJob($i) : new LoadCustomers($i));
        }

        return 0;
    }
}
