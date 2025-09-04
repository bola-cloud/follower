<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\BulkOrderProcessingJob;
use Illuminate\Support\Facades\Cache;

class ProcessPendingOrders extends Command
{
    protected $signature = 'orders:process-pending {--continuous : Run continuously} {--interval=8 : Minimum seconds between runs when running continuously}';
    protected $description = 'Process pending orders in bulk for high performance';

    public function handle()
    {
        $continuous = $this->option('continuous');

        $interval = (int) $this->option('interval');

        if ($continuous) {
            $this->info('🚀 Starting continuous order processing...');

            while (true) {
                $this->processBatch($interval);
                sleep(max(1, $interval));
            }
        } else {
            $this->info('🚀 Processing single batch of orders...');
            $this->processBatch($interval);
        }
    }

    private function processBatch(int $minIntervalSeconds = 8)
    {
        $lockKey = 'bulk_order_processing_lock';
        $lastRunKey = 'bulk_order_last_run_at';

        // If a job is already running, skip dispatch
        if (Cache::has($lockKey)) {
            $this->info('⏭️ Bulk processing already running, skipping dispatch');
            return;
        }

        // Respect minimum interval between runs to avoid rapid re-dispatch
        $lastRun = Cache::get($lastRunKey);
        if ($lastRun && (time() - (int) $lastRun) < $minIntervalSeconds) {
            $this->info('⏭️ Bulk processing ran recently, skipping dispatch');
            return;
        }

        // Set a short-lived last-run marker to reduce immediate re-dispatch attempts
        Cache::put($lastRunKey, time(), now()->addMinutes(5));

        // Dispatch bulk processing job
        BulkOrderProcessingJob::dispatch();

        $this->info('✅ Bulk processing job dispatched at ' . now()->format('H:i:s'));
    }
}
