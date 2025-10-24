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
        // Prefer a minute-based test interval if provided. This allows you to
        // run `php artisan schedule:run` every minute from Supervisor/cron and
        // have the Kernel decide when to run the coordinator by minutes.
        $minutes = env('COORDINATOR_INTERVAL_MINUTES');
        if (!is_null($minutes)) {
            $minutes = (int) $minutes;
            if ($minutes <= 0) {
                return;
            }

            // For 1 <= minutes <= 59, use minute cron. For >=60 fall back to hour logic.
            $cmd = $schedule->command('orders:resume-active')->withoutOverlapping();
            if ($minutes === 1) {
                $cmd->everyMinute();
            } elseif ($minutes > 1 && $minutes < 60) {
                // cron '*/N * * * *' runs every N minutes
                $cron = sprintf('*/%d * * * *', max(1, $minutes));
                $cmd->cron($cron);
            } else {
                // minutes >= 60: convert to hours and reuse hours logic below
                $hours = max(1, min(23, (int) floor($minutes / 60)));
                if ($hours === 1) {
                    $cmd->hourly();
                } else {
                    $cron = sprintf('0 */%d * * *', $hours);
                    $cmd->cron($cron);
                }
            }

            return;
        }

        // Fallback to hours-based interval (legacy). Use COORDINATOR_INTERVAL_HOURS.
        $hours = (int) env('COORDINATOR_INTERVAL_HOURS', 1);
        if ($hours <= 0) {
            return;
        }

        $cmd = $schedule->command('orders:resume-active')->withoutOverlapping();
        if ($hours === 1) {
            $cmd->hourly();
        } else {
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
