<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class OptimizedQueueWorker extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'queue:work-optimized {--sleep=1 : Seconds to sleep when no job is available}';

    /**
     * The console command description.
     */
    protected $description = 'Run optimized queue worker with dynamic performance adjustments';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sleep = $this->option('sleep');

        // Get system load
        $systemLoad = sys_getloadavg()[0] ?? 1.0;

        // Dynamic worker configuration based on system load
        if ($systemLoad < 5) {
            // Low load: aggressive processing
            $timeout = 120;
            $memory = 512;
            $sleep = 0; // No sleep for maximum speed
        } elseif ($systemLoad < 15) {
            // Medium load: balanced processing
            $timeout = 90;
            $memory = 256;
            $sleep = 1;
        } else {
            // High load: conservative processing
            $timeout = 60;
            $memory = 128;
            $sleep = 3;
        }

        $this->info("🚀 Starting optimized queue worker");
        $this->info("📊 System load: {$systemLoad}");
        $this->info("⚙️  Timeout: {$timeout}s, Memory: {$memory}MB, Sleep: {$sleep}s");

        // Run the optimized queue worker
        Artisan::call('queue:work', [
            '--timeout' => $timeout,
            '--memory' => $memory,
            '--sleep' => $sleep,
            '--tries' => 3,
            '--backoff' => '3,6,12', // Fast backoff progression
            '--max-time' => 3600, // 1 hour max runtime
        ]);

        return 0;
    }
}
