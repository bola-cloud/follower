<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class BulkOrderProcessingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // Reduced to 2 minutes
    public $tries = 2; // Allow 1 retry
    public $backoff = 30; // 30 second delay between retries

    public function handle()
    {
        Log::info("🚀 BulkOrderProcessingJob started");

        try {
            // Check database connectivity first
            if (!$this->checkDatabaseConnectivity()) {
                Log::error("❌ Database connection failed, aborting bulk job");
                return;
            }

            // 🚀 GENTLE PROCESSING: Much smaller batches with longer delays
            $batchSize = 3; // Very small batches to prevent overload
            $maxIterations = 2; // Fewer iterations
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

                    // Much longer delay to reduce system load
                    sleep(5); // 5 second delay between batches

                } catch (\Illuminate\Database\QueryException $e) {
                    Log::error("❌ Database error in batch {$i}: " . $e->getMessage());

                    // If connection error, stop processing
                    if (strpos($e->getMessage(), 'Connection refused') !== false) {
                        Log::error("❌ Database connection lost, aborting bulk job");
                        break;
                    }

                    // For other DB errors, wait longer and continue
                    sleep(10);
                    continue;

                } catch (\Throwable $e) {
                    Log::error("❌ General error in batch {$i}: " . $e->getMessage());
                    sleep(5);
                    continue;
                }
            }

            Log::info("🎯 BulkOrderProcessingJob completed. Total processed: {$totalProcessed}");

        } catch (\Throwable $e) {
            Log::error("❌ BulkOrderProcessingJob failed: " . $e->getMessage());
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

                // Small delay between individual pings
                usleep(200000); // 0.2 second delay

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
