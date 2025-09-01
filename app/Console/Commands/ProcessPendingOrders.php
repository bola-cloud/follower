<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\BulkOrderProcessingJob;

class ProcessPendingOrders extends Command
{
    protected $signature = 'orders:process-pending {--continuous : Run continuously}';
    protected $description = 'Process pending orders in bulk for high performance';

    public function handle()
    {
        $continuous = $this->option('continuous');

        if ($continuous) {
            $this->info('🚀 Starting continuous order processing...');

            while (true) {
                $this->processBatch();
                sleep(5); // Process every 5 seconds
            }
        } else {
            $this->info('🚀 Processing single batch of orders...');
            $this->processBatch();
        }
    }

    private function processBatch()
    {
        // Dispatch bulk processing job
        BulkOrderProcessingJob::dispatch();

        $this->info('✅ Bulk processing job dispatched at ' . now()->format('H:i:s'));
    }
}
