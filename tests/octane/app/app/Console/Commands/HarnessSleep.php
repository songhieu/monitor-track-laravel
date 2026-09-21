<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HarnessSleep extends Command
{
    protected $signature = 'harness:sleep {seconds}';

    protected $description = 'Sleep, then succeed';

    public function handle()
    {
        sleep((int) $this->argument('seconds'));

        return 0;
    }
}
