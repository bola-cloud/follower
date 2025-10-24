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
        // $schedule->command('inspire')->hourly();
        $hours = (int) env('COORDINATOR_INTERVAL_HOURS', 1);
        if ($hours <= 0) {
            return;
        }

        // If hours == 1 use the convenient hourly() helper. For >1 use a cron
        // expression so we can run every N hours (e.g. every 2 hours -> "0 */2 * * *").
        $cmd = $schedule->command('orders:resume-active')->withoutOverlapping();
        if ($hours === 1) {
            $cmd->hourly();
        } else {
            // Clamp to sensible range to avoid bad cron expressions
            $hours = max(1, min(23, $hours));
            $cron = sprintf('0 */%d * * *', $hours);
            $cmd->cron($cron);
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
