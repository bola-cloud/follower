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
use Illuminate\Support\Facades\Redis;

class ActionQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // Extended for processing up to 5000 actions
    public $tries = 1; // No retries for maximum speed - handle errors gracefully
    public $backoff = []; // No backoff delays
    public $maxExceptions = 3; // Allow some exceptions before failing

    public function __construct()
    {
        $this->onQueue('actions');
    }

    public function handle()
    {
        Log::info("🔄 ActionQueueJob started - processing batched actions");

        try {
            // Check database connectivity first
            if (!$this->checkDatabaseConnectivity()) {
                Log::error("❌ Database unavailable, will retry later");
                throw new \Exception("Database connection failed");
            }

            // Process actions in small batches to prevent overload
            $this->processBatchedActions();

        } catch (\Throwable $e) {
            Log::error("❌ ActionQueueJob failed: " . $e->getMessage());
            throw $e; // Re-throw to trigger retry
        }
    }

    /**
     * Process pending actions in optimized batches for maximum speed
     */
    private function processBatchedActions()
    {
        $batchSize = 100; // MAXIMUM batch size for ultra-fast processing
        $maxBatches = 50; // Process up to 5000 actions per job run
        $totalProcessed = 0;

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            // Get pending actions from cache
            $actions = $this->getPendingActions($batchSize);

            if (empty($actions)) {
                Log::info("✅ No more pending actions to process");
                break;
            }

            Log::info("📦 Processing batch {$batch}: " . count($actions) . " actions");

            $processed = $this->processBatch($actions);
            $totalProcessed += $processed;

            // NO DELAY - maximum speed processing
            // Continue immediately to next batch
        }

        Log::info("🎯 ActionQueueJob completed. Total processed: {$totalProcessed}");

        // If there are still pending actions, dispatch another job IMMEDIATELY
        if ($this->hasPendingActions()) {
            Log::info("🔄 More actions pending, dispatching next job");
            self::dispatch(); // Instant redispatch - no delay
        }
    }    /**
     * Get pending actions from cache storage
     */
    private function getPendingActions($limit)
    {
    $redisKey = 'mqtt_actions_queue';
        $batch = [];

        for ($i = 0; $i < $limit; $i++) {
            $item = Redis::lpop($redisKey);
            if ($item === null) break;
            $decoded = json_decode($item, true);
            if ($decoded !== null) $batch[] = $decoded;
        }

        return $batch;
    }

    /**
     * Check if there are more actions pending
     */
    private function hasPendingActions()
    {
    $redisKey = 'mqtt_actions_queue';
    return Redis::llen($redisKey) > 0;
    }

    /**
     * Process a batch of actions with intelligent MySQL load management
     */
    private function processBatch($actions)
    {
        $processed = 0;
        $dbLoadHigh = false;

        // Check MySQL load before processing
        try {
            $processlist = DB::select('SHOW PROCESSLIST');
            $activeConnections = count($processlist);
            $dbLoadHigh = $activeConnections > 50; // Adjust threshold as needed

            if ($dbLoadHigh) {
                Log::warning("⚠️ High MySQL load detected", ['active_connections' => $activeConnections]);
            }
        } catch (\Throwable $e) {
            // If can't check load, assume normal
            Log::debug("Could not check MySQL load: " . $e->getMessage());
        }

        // Process actions in smaller sub-batches if load is high
        $subBatchSize = $dbLoadHigh ? 5 : 15;
        $actionChunks = array_chunk($actions, $subBatchSize);

        foreach ($actionChunks as $chunkIndex => $chunk) {
            try {
                foreach ($chunk as $actionData) {
                    try {
                        $this->processAction($actionData);
                        $processed++;

                        // NO DELAYS - process actions at maximum speed
                        // Only add tiny delay if MySQL load is extremely high
                        if ($dbLoadHigh && $processed % 10 === 0) {
                            usleep(1000); // 1ms delay every 10 actions under high load
                        }

                    } catch (\Illuminate\Database\QueryException $e) {
                        if (strpos($e->getMessage(), 'Connection refused') !== false ||
                            strpos($e->getMessage(), 'MySQL server has gone away') !== false ||
                            strpos($e->getMessage(), 'Too many connections') !== false) {
                            Log::error("❌ Database overload during batch processing");
                            // Re-queue the remaining actions
                            $remaining = array_slice($actions, $processed);
                            $this->requeueActions($remaining);
                            throw $e;
                        }

                        Log::warning("⚠️ Database error processing action: " . $e->getMessage(), $actionData);
                        continue;

                    } catch (\Throwable $e) {
                        Log::warning("⚠️ Error processing action: " . $e->getMessage(), $actionData);
                        continue;
                    }
                }

                // Small pause between chunks only if load is very high
                if ($dbLoadHigh && $chunkIndex < count($actionChunks) - 1) {
                    usleep(2000); // 2ms between chunks under high load
                }

            } catch (\Throwable $e) {
                Log::error("❌ Batch chunk failed: " . $e->getMessage());
                continue;
            }
        }

        return $processed;
    }

    /**
     * Process a single action update - ONLY UPDATE EXISTING ACTIONS
     */
    private function processAction($actionData)
    {
        $orderId = $actionData['order_id'];
        $userId = $actionData['user_id'];
        $status = $actionData['status']; // Only 'done' or 'external'

        Log::debug("🔄 Processing action update", ['order_id' => $orderId, 'user_id' => $userId, 'status' => $status]);

        // NO TRANSACTION - for maximum speed, single atomic update
        try {
            // Only update existing actions - DO NOT CREATE NEW ONES
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                // Only update rows where the current status differs from the requested status
                ->where('status', '!=', $status)
                ->update([
                    'status' => $status,
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                // Action doesn't exist or status is already correct - this is normal
                Log::debug("⚠️ Action not updated (not found or status unchanged)", [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'requested_status' => $status
                ]);
                return; // Continue processing - not an error
            }

            Log::info("✅ Action updated successfully", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'rows_affected' => $updated
            ]);

            // Increment order done_count if action was successful and status is 'done'
            if ($status === 'done') {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->increment('done_count');

                // Check if order should be completed
                $this->checkOrderCompletion($orderId);
            }

        } catch (\Throwable $e) {
            Log::error("❌ Failed to update action", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Check if order should be marked as completed
     */
    private function checkOrderCompletion($orderId)
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->first(['done_count', 'total_count', 'status']);

        if ($order && $order->done_count >= $order->total_count && $order->status !== 'completed') {
            DB::table('orders')
                ->where('id', $orderId)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed']);
        }
    }

    /**
     * Re-queue actions that couldn't be processed
     */
    private function requeueActions($actions)
    {
        if (empty($actions)) return;

        $redisKey = 'mqtt_actions_queue';
        // Push back to the front of the list in the same order
        foreach (array_reverse($actions) as $act) {
            Redis::lpush($redisKey, json_encode($act));
        }
        Redis::expire($redisKey, 3600);

        Log::info("🔄 Re-queued " . count($actions) . " actions for later processing");
    }

    /**
     * Check database connectivity
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

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error("❌ ActionQueueJob failed permanently", [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
