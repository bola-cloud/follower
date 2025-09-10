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
        $batchSize = 500; // ULTRA HIGH batch size for maximum MySQL throughput
        $maxBatches = 100; // Process up to 50,000 actions per job run
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
            $dbLoadHigh = $activeConnections > 100; // Higher threshold for maximum throughput

            if ($dbLoadHigh) {
                Log::warning("⚠️ High MySQL load detected", ['active_connections' => $activeConnections]);
            }
        } catch (\Throwable $e) {
            // If can't check load, assume normal
            Log::debug("Could not check MySQL load: " . $e->getMessage());
        }

        // Process actions in larger sub-batches for maximum speed
        $subBatchSize = $dbLoadHigh ? 25 : 50; // Much larger batches
        $actionChunks = array_chunk($actions, $subBatchSize);

        foreach ($actionChunks as $chunkIndex => $chunk) {
            try {
                foreach ($chunk as $actionData) {
                    try {
                        $this->processAction($actionData);
                        $processed++;

                        // ZERO DELAYS - absolute maximum speed processing
                        // Only minimal delay if MySQL is severely overloaded
                        if ($dbLoadHigh && $processed % 50 === 0) {
                            usleep(500); // 0.5ms delay every 50 actions under extreme load only
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

                // ZERO inter-chunk delays for maximum throughput
                // Only pause if MySQL is severely overloaded
                if ($dbLoadHigh && $chunkIndex < count($actionChunks) - 1) {
                    usleep(200); // 0.2ms between chunks under extreme load only
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
            // Only update actions that are currently PENDING - prevents duplicate MQTT updates
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where('status', 'pending') // ONLY update if status is pending
                ->update([
                    'status' => $status,
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated === 0) {
                // Action doesn't exist, not pending, or already processed - skip duplicate
                Log::debug("⚠️ Action not updated (not found, not pending, or already processed)", [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'requested_status' => $status,
                    'reason' => 'action_not_pending_or_duplicate_mqtt'
                ]);
                return; // Continue processing - not an error, likely duplicate MQTT
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
