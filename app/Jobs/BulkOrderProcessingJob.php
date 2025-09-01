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

    public $timeout = 300; // 5 minutes
    public $tries = 1; // No retries for bulk jobs

    public function handle()
    {
        Log::info("🚀 BulkOrderProcessingJob started");

        // 🚀 BULK PROCESSING: Process multiple orders in batches for high performance
        $batchSize = 50; // Reduced batch size for testing
        $maxIterations = 5; // Reduced iterations for testing
        $totalProcessed = 0;

        for ($i = 0; $i < $maxIterations; $i++) {
            // 🚀 SIMPLIFIED QUERY: Get orders that need actions
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

            // Small delay to prevent overwhelming the system
            usleep(200000); // 0.2 second
        }

        Log::info("🎯 BulkOrderProcessingJob completed. Total processed: {$totalProcessed}");
    }    private function processBatchOrders($orders): int
    {
        $processed = 0;
        foreach ($orders as $order) {
            // Send activation ping immediately
            $this->sendActivationPing($order);
            $processed++;
        }
        return $processed;
    }

    private function sendActivationPing($order)
    {
        try {
            $pingService = app()->make(\App\Services\PingService::class);

            // 🚀 CRITICAL FIX: Use 'create' type since 'resume' isn't accepted by MQTT handler
            $pingData = [
                'type' => 'create',  // Changed from 'resume' to 'create'
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'activation' => true,
                'bulk_processing' => true,
                'timestamp' => now()->toISOString()
            ];

            Log::info("🚀 Sending bulk activation ping for order {$order->id}", $pingData);

            $pingService->sendPing('order/ping/req', $pingData);

            Log::info("✅ Bulk activation ping sent successfully", [
                'order_id' => $order->id,
                'remaining_count' => $order->total_count - $order->done_count
            ]);
        } catch (\Throwable $e) {
            Log::error("❌ Bulk ping failed for order {$order->id}: " . $e->getMessage());
        }
    }
}
