<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Always schedule the coordinator to run every minute. The system
        // scheduler (cron or Supervisor) should invoke `php artisan schedule:run`
        // every minute so Laravel can dispatch scheduled commands. Using
        // `withoutOverlapping()` prevents concurrent coordinator runs.
        // Capture the artisan command stdout/stderr into a dedicated log so we
        // can inspect the scheduler-run output even if the system cron redirects
        // schedule:run to /dev/null. This writes to storage/logs/orders-resume-active.log
        // and appends on each run.
        $schedule->command('orders:resume-active')
            ->everyMinute()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/orders-resume-active.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
