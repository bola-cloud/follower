<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HighPerformanceActionProcessor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes for bulk processing
    public $tries = 2;
    public $maxExceptions = 3;

    public function handle()
    {
        $startTime = microtime(true);
        Log::info("🚀 HighPerformanceActionProcessor started");

        $batchSize = env('MQTT_BATCH_SIZE', 100);
        $maxBatches = 50; // Process up to 5000 actions per job
        $totalProcessed = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $actions = $this->getBulkActions($batchSize);

            if (empty($actions)) {
                break;
            }

            $processed = $this->processBulkActions($actions);
            $totalProcessed += $processed;

            // Quick yield for other processes
            usleep(10000); // 0.01 second
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info("✅ HighPerformanceActionProcessor completed", [
            'total_processed' => $totalProcessed,
            'duration_ms' => $duration,
            'actions_per_second' => round($totalProcessed / ($duration / 1000), 2)
        ]);

        // Schedule next job if more actions pending
        if ($this->hasMoreActions()) {
            self::dispatch()->delay(now()->addSeconds(1));
        }
    }

    private function getBulkActions($limit)
    {
        // Use same Redis key as your existing system for compatibility
        $redisKey = 'mqtt_actions_queue';
        $actions = [];

        // Use Redis pipeline for faster bulk operations
        $pipe = Redis::pipeline();
        for ($i = 0; $i < $limit; $i++) {
            $pipe->lpop($redisKey);
        }
        $results = $pipe->execute();

        foreach ($results as $item) {
            if ($item) {
                $decoded = json_decode($item, true);
                if ($decoded) {
                    $actions[] = $decoded;
                }
            }
        }

        return $actions;
    }

    private function processBulkActions($actions)
    {
        if (empty($actions)) return 0;

        // Group actions by order_id for efficient processing
        $actionsByOrder = [];
        $updateData = [];
        $incrementOrders = [];

        foreach ($actions as $action) {
            $key = $action['order_id'] . '_' . $action['user_id'];
            $actionsByOrder[$key] = $action;

            // Prepare bulk update data
            $updateData[] = [
                'order_id' => $action['order_id'],
                'user_id' => $action['user_id'],
                'status' => $action['status'],
                'performed_at' => now(),
                'updated_at' => now()
            ];

            // Track which orders need done_count increment
            if ($action['status'] === 'done') {
                $incrementOrders[$action['order_id']] = ($incrementOrders[$action['order_id']] ?? 0) + 1;
            }
        }

        DB::beginTransaction();
        try {
            // Bulk update actions using raw SQL for maximum performance
            $this->bulkUpdateActions($updateData);

            // Bulk increment order counters
            $this->bulkIncrementOrders($incrementOrders);

            DB::commit();

            Log::info("✅ Bulk processed {$count} actions", [
                'orders_affected' => count($incrementOrders),
                'actions_updated' => count($updateData)
            ]);

            return count($updateData);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("❌ Bulk processing failed: " . $e->getMessage());

            // Re-queue failed actions
            $this->requeueActions($actions);
            throw $e;
        }
    }

    private function bulkUpdateActions($updateData)
    {
        if (empty($updateData)) return;

        // Use MySQL's ON DUPLICATE KEY UPDATE for high performance
        $values = [];
        $bindings = [];

        foreach ($updateData as $data) {
            $values[] = "(?, ?, ?, ?, ?)";
            $bindings = array_merge($bindings, [
                $data['order_id'],
                $data['user_id'],
                $data['status'],
                $data['performed_at'],
                $data['updated_at']
            ]);
        }

        $sql = "
            UPDATE actions
            SET status = CASE
                WHEN CONCAT(order_id, '_', user_id) = ? THEN ?
                " . str_repeat("WHEN CONCAT(order_id, '_', user_id) = ? THEN ? ", count($updateData) - 1) . "
                ELSE status
            END,
            performed_at = CASE
                WHEN CONCAT(order_id, '_', user_id) = ? THEN ?
                " . str_repeat("WHEN CONCAT(order_id, '_', user_id) = ? THEN ? ", count($updateData) - 1) . "
                ELSE performed_at
            END,
            updated_at = NOW()
            WHERE CONCAT(order_id, '_', user_id) IN (" . str_repeat('?,', count($updateData) - 1) . "?)
            AND status != 'done'
        ";

        $caseBindings = [];
        $whereBindings = [];

        foreach ($updateData as $data) {
            $key = $data['order_id'] . '_' . $data['user_id'];
            $caseBindings[] = $key;
            $caseBindings[] = $data['status'];
            $whereBindings[] = $key;
        }

        // Duplicate for performed_at
        foreach ($updateData as $data) {
            $key = $data['order_id'] . '_' . $data['user_id'];
            $caseBindings[] = $key;
            $caseBindings[] = $data['performed_at'];
        }

        $finalBindings = array_merge($caseBindings, $whereBindings);

        DB::update($sql, $finalBindings);
    }

    private function bulkIncrementOrders($incrementOrders)
    {
        if (empty($incrementOrders)) return;

        foreach ($incrementOrders as $orderId => $count) {
            DB::table('orders')
                ->where('id', $orderId)
                ->increment('done_count', $count);
        }

        // Check for completion in bulk
        $orderIds = array_keys($incrementOrders);
        $completedOrders = DB::table('orders')
            ->whereIn('id', $orderIds)
            ->whereColumn('done_count', '>=', 'total_count')
            ->where('status', '!=', 'completed')
            ->pluck('id');

        if ($completedOrders->isNotEmpty()) {
            DB::table('orders')
                ->whereIn('id', $completedOrders)
                ->update(['status' => 'completed']);
        }
    }

    private function hasMoreActions()
    {
        return Redis::llen('mqtt_actions_queue') > 0;
    }

    private function requeueActions($actions)
    {
        $redisKey = 'mqtt_actions_queue';
        foreach (array_reverse($actions) as $action) {
            Redis::lpush($redisKey, json_encode($action));
        }
        Redis::expire($redisKey, 3600);
    }
}
