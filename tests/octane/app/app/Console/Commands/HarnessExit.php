<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HarnessExit extends Command
{
    protected $signature = 'harness:exit {code} {--foreground}';

    protected $description = 'Exit with the given code';

    public function handle()
    {
        return (int) $this->argument('code');
    }
}
