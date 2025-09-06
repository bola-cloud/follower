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

    public $timeout = 60; // 1 minute timeout
    public $tries = 3; // Allow retries
    public $backoff = [10, 30, 60]; // Progressive backoff

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
     * Process pending actions in small batches
     */
    private function processBatchedActions()
    {
        $batchSize = 5; // Very small batches
        $maxBatches = 10; // Maximum batches per job run
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

            // Small delay between batches
            usleep(500000); // 0.5 second delay
        }

        Log::info("🎯 ActionQueueJob completed. Total processed: {$totalProcessed}");

        // If there are still pending actions, dispatch another job
        if ($this->hasPendingActions()) {
            Log::info("🔄 More actions pending, dispatching next job");
            self::dispatch()->delay(now()->addSeconds(5));
        }
    }

    /**
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
     * Process a batch of actions with database safety
     */
    private function processBatch($actions)
    {
        $processed = 0;

        foreach ($actions as $actionData) {
            try {
                $this->processAction($actionData);
                $processed++;

                // Tiny delay between individual actions
                usleep(100000); // 0.1 second

            } catch (\Illuminate\Database\QueryException $e) {
                if (strpos($e->getMessage(), 'Connection refused') !== false) {
                    Log::error("❌ Database connection lost during batch processing");
                    // Re-queue the remaining actions
                    $this->requeueActions(array_slice($actions, $processed));
                    throw $e;
                }

                Log::warning("⚠️ Database error processing action: " . $e->getMessage(), $actionData);
                continue;

            } catch (\Throwable $e) {
                Log::warning("⚠️ Error processing action: " . $e->getMessage(), $actionData);
                continue;
            }
        }

        return $processed;
    }

    /**
     * Process a single action update
     */
    private function processAction($actionData)
    {
        $orderId = $actionData['order_id'];
        $userId = $actionData['user_id'];
        $status = $actionData['status'];

        Log::debug("🔄 Processing action", ['order_id' => $orderId, 'user_id' => $userId, 'status' => $status]);

        DB::beginTransaction();

        try {
            // Update action with race condition protection
            // Allow updating from 'pending' to any status, but prevent re-updating 'done' actions
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where(function($query) use ($status) {
                    // If incoming status is 'done', allow update from any non-done status
                    if ($status === 'done') {
                        $query->where('status', '!=', 'done');
                    } else {
                        // For other statuses (like 'external'), allow update from 'pending' or same status
                        $query->whereIn('status', ['pending', $status]);
                    }
                })
                ->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);

            // If no action was updated, check if we need to create one
            if ($updated === 0) {
                $existingAction = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->first();

                if (!$existingAction && env('MQTT_AUTO_CREATE_MISSING', false)) {
                    // Create missing action
                    Log::info("🔨 Creating missing action", ['order_id' => $orderId, 'user_id' => $userId, 'status' => $status]);
                    DB::table('actions')->insertOrIgnore([
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'type' => $actionData['type'] ?? 'follow',
                        'status' => $status,
                        'performed_at' => now(),
                    ]);
                    $updated = 1;
                } else {
                    // Log why the update was skipped
                    if ($existingAction) {
                        Log::warning("⚠️ Action update skipped", [
                            'order_id' => $orderId,
                            'user_id' => $userId,
                            'requested_status' => $status,
                            'current_status' => $existingAction->status,
                            'action_id' => $existingAction->id
                        ]);
                    } else {
                        Log::warning("⚠️ Action not found and auto-create disabled", [
                            'order_id' => $orderId,
                            'user_id' => $userId,
                            'status' => $status
                        ]);
                    }
                }
            } else {
                Log::info("✅ Action updated successfully", [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'status' => $status,
                    'rows_affected' => $updated
                ]);
            }

            // Increment order done_count if action was successful and status is 'done'
            if ($updated > 0 && $status === 'done') {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->increment('done_count');

                // Check if order should be completed
                $this->checkOrderCompletion($orderId);
            }

            DB::commit();
            Log::debug("✅ Action processed successfully", ['order_id' => $orderId, 'user_id' => $userId]);

        } catch (\Throwable $e) {
            DB::rollBack();
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
