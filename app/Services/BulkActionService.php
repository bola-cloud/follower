<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BulkActionService
{
    private $pendingActions = [];
    private $batchSize;
    private $flushInterval;

    public function __construct()
    {
        $this->batchSize = env('BULK_ACTION_BATCH_SIZE', 200);
        $this->flushInterval = env('BULK_ACTION_FLUSH_INTERVAL', 5); // seconds
    }

    /**
     * Add action to pending batch for bulk processing
     */
    public function addAction($orderId, $userId, $status, $type = 'follow')
    {
        $this->pendingActions[] = [
            'order_id' => $orderId,
            'user_id' => $userId,
            'status' => $status,
            'type' => $type,
            'timestamp' => now()->toDateTimeString()
        ];

        // Auto-flush if batch is full
        if (count($this->pendingActions) >= $this->batchSize) {
            $this->flushActions();
        }

        return true;
    }

    /**
     * Flush pending actions to database in bulk
     */
    public function flushActions()
    {
        if (empty($this->pendingActions)) {
            return 0;
        }

        $actions = $this->pendingActions;
        $this->pendingActions = [];

        return $this->processBulkActions($actions);
    }

    /**
     * Process actions in bulk using optimized SQL - compatible with your existing schema
     */
    private function processBulkActions($actions)
    {
        if (empty($actions)) return 0;

        $startTime = microtime(true);
        $processed = 0;

        DB::beginTransaction();
        try {
            // Group by order for efficient processing
            $orderCounts = [];
            $updateData = [];

            foreach ($actions as $action) {
                $updateData[] = [
                    'order_id' => $action['order_id'],
                    'user_id' => $action['user_id'],
                    'status' => $action['status'],
                    'performed_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString()
                ];

                if ($action['status'] === 'done') {
                    $orderCounts[$action['order_id']] = ($orderCounts[$action['order_id']] ?? 0) + 1;
                }
            }

            // Update actions individually for safety (compatible with your current logic)
            foreach ($updateData as $data) {
                $updated = DB::table('actions')
                    ->where('order_id', $data['order_id'])
                    ->where('user_id', $data['user_id'])
                    ->where('status', '!=', 'done') // Same logic as your ActionQueueJob
                    ->update([
                        'status' => $data['status'],
                        'performed_at' => $data['performed_at'],
                        'updated_at' => $data['updated_at'],
                    ]);

                if ($updated > 0) {
                    $processed++;
                }
            }

            // Bulk increment order counters (same as your existing system)
            foreach ($orderCounts as $orderId => $count) {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->increment('done_count', $count);
            }

            // Mark completed orders (same logic as existing)
            if (!empty($orderCounts)) {
                DB::table('orders')
                    ->whereIn('id', array_keys($orderCounts))
                    ->whereColumn('done_count', '>=', 'total_count')
                    ->where('status', '!=', 'completed')
                    ->update(['status' => 'completed']);
            }

            DB::commit();

            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::info("✅ Bulk action processing completed", [
                'actions_processed' => $processed,
                'orders_affected' => count($orderCounts),
                'duration_ms' => $duration,
                'actions_per_second' => round($processed / ($duration / 1000), 2)
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("❌ Bulk action processing failed: " . $e->getMessage());
            throw $e;
        }

        return $processed;
    }

    /**
     * Auto-flush based on time interval
     */
    public function autoFlush()
    {
        $lastFlush = Cache::get('bulk_action_last_flush', 0);
        $now = time();

        if (($now - $lastFlush) >= $this->flushInterval && !empty($this->pendingActions)) {
            Cache::put('bulk_action_last_flush', $now, 300);
            return $this->flushActions();
        }

        return 0;
    }

    /**
     * Get pending action count
     */
    public function getPendingCount()
    {
        return count($this->pendingActions);
    }
}
