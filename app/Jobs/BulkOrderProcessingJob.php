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
        // Log::info("🚀 BulkOrderProcessingJob started");

        // 🚀 BULK PROCESSING: Process multiple orders in batches for high performance
        $batchSize = 20; // Smaller batches to reduce MQTT flood
        $maxIterations = 3; // Fewer iterations to reduce load
        $totalProcessed = 0;

        for ($i = 0; $i < $maxIterations; $i++) {
            // 🚀 SIMPLIFIED QUERY: Get orders that need actions
            $orders = Order::where('status', 'active')
                ->where('done_count', '<', DB::raw('total_count'))
                ->orderBy('created_at', 'asc')
                ->limit($batchSize)
                ->get();

            // Log::info("Batch {$i}: Found {$orders->count()} orders to process");

            if ($orders->isEmpty()) {
                Log::info("No more orders to process, stopping");
                break; // No more orders to process
            }

            $processed = $this->processBatchOrders($orders);
            $totalProcessed += $processed;

            // Small delay to let MQTT broker process messages
            usleep(500000); // 0.5 second delay between batches
        }

        // Log::info("🎯 BulkOrderProcessingJob completed. Total processed: {$totalProcessed}");
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

            // Clean ping format with only essential keys
            $pingData = [
                'type' => $order->type ?? 'create',  // Use order type or default to 'create'
                'order_id' => $order->id,
                'activation' => true
            ];

            // Log::info("🚀 Sending bulk activation ping for order {$order->id}", $pingData);

            $pingService->sendPing('order/ping/req', $pingData);

            // Log::info("✅ Bulk activation ping sent successfully", [
            //     'order_id' => $order->id,
            //     'remaining_count' => $order->total_count - $order->done_count
            // ]);
        } catch (\Throwable $e) {
            Log::error("❌ Bulk ping failed for order {$order->id}: " . $e->getMessage());
        }
    }
}
