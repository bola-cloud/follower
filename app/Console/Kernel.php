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
        // Schedule the coordinator. Behavior is driven by env variable
        // COORDINATOR_SCHEDULE_HOURS:
        // - If COORDINATOR_SCHEDULE_HOURS is unset or set to 0, use everyMinute()
        //   (useful for test mode).
        // - If set to a positive integer N, schedule the command to run every N
        //   hours at minute 0 using a cron expression (e.g. N=1 -> every hour on
        //   the hour; N=6 -> 00:00,06:00,12:00,18:00). Note: choose N that makes
        //   sense for your environment (divisors of 24 are typical).
        $hours = (int) env('COORDINATOR_SCHEDULE_HOURS', 0);

        $scheduled = $schedule->command('orders:resume-active')->withoutOverlapping()->appendOutputTo(storage_path('logs/orders-resume-active.log'));

        if ($hours <= 0) {
            // Test mode: every minute
            $scheduled->everyMinute();
        } else {
            // Production mode: every N hours at minute 0
            // Cron: minute 0, every N hours
            $scheduled->cron("0 */{$hours} * * *");
        }
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
