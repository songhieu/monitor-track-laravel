<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule)
    {
        // The shapes dodgeprint uses: runInBackground(), mostly with
        // withoutOverlapping().
        $schedule->command('harness:exit 3')->everyMinute()->runInBackground();
        $schedule->command('harness:exit 0')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('harness:sleep 8')->everyMinute()->withoutOverlapping()->runInBackground();
        // In the schedule:run process itself.
        $schedule->command('harness:exit 4 --foreground')->everyMinute();
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
