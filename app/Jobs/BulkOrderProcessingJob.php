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
        // 🚀 BULK PROCESSING: Process multiple orders in batches for high performance
        $batchSize = 100; // Process 100 orders at once
        $maxIterations = 10; // Maximum 10 batches per job (1000 orders)

        for ($i = 0; $i < $maxIterations; $i++) {
            // Get active orders that need processing
            $orders = Order::where('status', 'active')
                ->where('done_count', '<', DB::raw('total_count'))
                ->whereDoesntHave('actions', function($query) {
                    $query->where('created_at', '>', now()->subMinutes(5))
                          ->where('status', 'pending');
                })
                ->orderBy('created_at', 'asc')
                ->limit($batchSize)
                ->get();

            if ($orders->isEmpty()) {
                break; // No more orders to process
            }

            $this->processBatchOrders($orders);

            // Small delay to prevent overwhelming the system
            usleep(100000); // 0.1 second
        }
    }

    private function processBatchOrders($orders)
    {
        foreach ($orders as $order) {
            // Send activation ping immediately
            $this->sendActivationPing($order);
        }
    }

    private function sendActivationPing($order)
    {
        try {
            $pingService = app()->make(\App\Services\PingService::class);
            $pingService->sendPing('order/ping/req', [
                'type' => 'resume',
                'order_id' => $order->id,
                'activation' => true,
                'bulk_processing' => true
            ]);

            Log::info("Bulk activation ping sent", [
                'order_id' => $order->id,
                'remaining_count' => $order->total_count - $order->done_count
            ]);
        } catch (\Throwable $e) {
            Log::error("Bulk ping failed for order {$order->id}: " . $e->getMessage());
        }
    }
}
