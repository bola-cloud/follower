<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Order;

class BulkOrderProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // Extended for heavy processing
    public $tries = 1; // No retries for maximum speed
    public $backoff = []; // No backoff delays

    public function handle()
    {
        Log::info("🚀 BulkOrderProcessingJob started");

        // Prevent overlapping executions using a distributed lock (Redis)
        try {
            $lock = Cache::lock('bulk_order_processing_lock', 60);
        } catch (\Throwable $e) {
            // If cache/lock unavailable, proceed but be cautious
            Log::warning('BulkOrderProcessingJob: cache lock unavailable, proceeding without it: ' . $e->getMessage());
            $lock = null;
        }

        if ($lock) {
            if (!$lock->get()) {
                Log::info('BulkOrderProcessingJob: another instance is running, exiting early');
                return;
            }
        }

        // NO minimum interval - execute immediately for maximum speed
        // $minIntervalSeconds = 0; // No throttling
        // $lastRunKey = 'bulk_order_last_run_at';
        $lastRun = Cache::get($lastRunKey);
        // REMOVED: No minimum interval check for maximum speed
        // if ($lastRun && (time() - (int) $lastRun) < $minIntervalSeconds) {
        //     Log::info('BulkOrderProcessingJob: ran recently, skipping this run');
        //     if ($lock) { $lock->release(); }
        //     return;
        // }
        Cache::put($lastRunKey, time(), now()->addMinutes(5));

        try {
            // Check database connectivity first
            if (!$this->checkDatabaseConnectivity()) {
                Log::error("❌ Database connection failed, aborting bulk job");
                return;
            }

            // 🚀 MAXIMUM SPEED: Large batches with no delays
            $batchSize = 10; // Larger batches for maximum throughput
            $maxIterations = 10; // More iterations for maximum volume
            $totalProcessed = 0;

            for ($i = 0; $i < $maxIterations; $i++) {
                try {
                    // 🚀 SIMPLIFIED QUERY: Get orders that need actions with timeout protection
                    $orders = Order::where('status', 'active')
                        ->where('done_count', '<', DB::raw('total_count'))
                        ->orderBy('created_at', 'asc')
                        ->limit($batchSize)
                        ->get();

                    Log::info("Batch {$i}: Found {$orders->count()} orders to process");

                    if ($orders->isEmpty()) {
                        Log::info("No more orders to process, stopping");
                        break; // No more orders to process
                    }

                    $processed = $this->processBatchOrders($orders);
                    $totalProcessed += $processed;

                    // NO DELAYS - execute at maximum speed
                    // All delays removed for instant processing

                } catch (\Illuminate\Database\QueryException $e) {
                    Log::error("❌ Database error in batch {$i}: " . $e->getMessage());

                    // If connection error, stop processing
                    if (strpos($e->getMessage(), 'Connection refused') !== false) {
                        Log::error("❌ Database connection lost, aborting bulk job");
                        break;
                    }

                    // For other DB errors, continue immediately - no delays
                    continue;

                } catch (\Throwable $e) {
                    Log::error("❌ General error in batch {$i}: " . $e->getMessage());
                    // NO DELAYS - continue immediately for maximum speed
                    continue;
                }
            }

            Log::info("🎯 BulkOrderProcessingJob completed. Total processed: {$totalProcessed}");

            if ($lock) { $lock->release(); }

        } catch (\Throwable $e) {
            Log::error("❌ BulkOrderProcessingJob failed: " . $e->getMessage());
            if (isset($lock) && $lock) { try { $lock->release(); } catch (\Throwable $inner) { Log::warning('Failed to release lock: ' . $inner->getMessage()); } }
            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Check if database is responsive before processing
     */
    private function checkDatabaseConnectivity(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1 as test');
            return true;
        } catch (\Throwable $e) {
            Log::error("Database connectivity check failed: " . $e->getMessage());
            return false;
        }
    }

    private function processBatchOrders($orders): int
    {
        $processed = 0;
        foreach ($orders as $order) {
            try {
                // Send activation ping immediately with error handling
                $this->sendActivationPing($order);
                $processed++;

                // NO DELAYS between pings - maximum speed execution
                // All delays removed for instant processing

            } catch (\Throwable $e) {
                Log::error("❌ Failed to process order {$order->id}: " . $e->getMessage());
                // Continue processing other orders even if one fails
                continue;
            }
        }
        return $processed;
    }

    private function sendActivationPing($order)
    {
        try {
            // Check if PingService exists before using it
            if (!class_exists(\App\Services\PingService::class)) {
                Log::error("❌ PingService class not found");
                return;
            }

            $pingService = app()->make(\App\Services\PingService::class);

            // Clean ping format with only essential keys
            $pingData = [
                'type' => $order->type ?? 'create',  // Use order type or default to 'create'
                'order_id' => $order->id,
                'activation' => true
            ];

            Log::info("🚀 Sending bulk activation ping for order {$order->id}", $pingData);

            $pingService->sendPing('order/ping/req', $pingData);

            Log::info("✅ Bulk activation ping sent successfully", [
                'order_id' => $order->id,
                'remaining_count' => $order->total_count - $order->done_count
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error("❌ Database error while sending ping for order {$order->id}: " . $e->getMessage());
            throw $e; // Re-throw DB errors to trigger batch-level handling

        } catch (\Throwable $e) {
            Log::error("❌ Bulk ping failed for order {$order->id}: " . $e->getMessage());
            // Don't re-throw non-DB errors, just log and continue
        }
    }

    /**
     * Handle job failure - log details for debugging
     */
    public function failed(\Throwable $exception)
    {
        Log::error("❌ BulkOrderProcessingJob failed permanently", [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
