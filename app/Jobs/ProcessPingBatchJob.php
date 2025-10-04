<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use App\Services\BatchActionService;
use App\Services\MqttPublisherRedis;

class ProcessPingBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 2;
    public $maxExceptions = 3;

    protected $pingResponses;
    protected $batchId;

    /**
     * Create a new job instance.
     *
     * @param array $pingResponses Array of [{order_id, user_id, type, message_id}, ...]
     * @param string $batchId Unique batch identifier for tracking
     */
    public function __construct(array $pingResponses, string $batchId)
    {
        $this->pingResponses = $pingResponses;
        $this->batchId = $batchId;
        $this->onQueue('bulk'); // Use dedicated bulk queue with 6 workers
    }

    /**
     * Execute the job - process batch of ping responses
     */
    public function handle()
    {
        $startTime = microtime(true);
        $totalResponses = count($this->pingResponses);

        Log::info('[ProcessPingBatchJob] Starting batch processing', [
            'batch_id' => $this->batchId,
            'total_responses' => $totalResponses
        ]);

        // Group by order_id and type for efficient batch processing
        $groupedByOrder = [];
        foreach ($this->pingResponses as $response) {
            $orderId = $response['order_id'] ?? null;
            $type = $response['type'] ?? 'create';
            
            if (!$orderId) continue;
            
            $key = "{$orderId}_{$type}";
            if (!isset($groupedByOrder[$key])) {
                $groupedByOrder[$key] = [
                    'order_id' => $orderId,
                    'type' => $type,
                    'user_ids' => []
                ];
            }
            $groupedByOrder[$key]['user_ids'][] = $response['user_id'];
        }

        $processed = 0;
        $failed = 0;

        foreach ($groupedByOrder as $group) {
            try {
                $result = $this->processSingleOrderBatch(
                    $group['order_id'],
                    $group['user_ids'],
                    $group['type']
                );
                $processed += $result['processed'];
                $failed += $result['failed'];
            } catch (\Throwable $e) {
                Log::error('[ProcessPingBatchJob] Failed to process order group', [
                    'batch_id' => $this->batchId,
                    'order_id' => $group['order_id'],
                    'type' => $group['type'],
                    'user_count' => count($group['user_ids']),
                    'error' => $e->getMessage()
                ]);
                $failed += count($group['user_ids']);
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('[ProcessPingBatchJob] Batch processing completed', [
            'batch_id' => $this->batchId,
            'total_responses' => $totalResponses,
            'processed' => $processed,
            'failed' => $failed,
            'duration_ms' => $duration,
            'throughput_per_sec' => $duration > 0 ? round(($processed / $duration) * 1000, 2) : 0
        ]);

        // Update metrics
        try {
            $metricsKey = 'ping_batch_metrics:' . gmdate('YmdH');
            Redis::hincrby($metricsKey, 'batches_processed', 1);
            Redis::hincrby($metricsKey, 'responses_processed', $processed);
            Redis::hincrby($metricsKey, 'responses_failed', $failed);
            Redis::expire($metricsKey, 7200); // 2 hours
        } catch (\Throwable $e) {
            // Ignore metrics errors
        }
    }

    /**
     * Process all ping responses for a single order
     */
    protected function processSingleOrderBatch(int $orderId, array $userIds, string $type): array
    {
        $order = Order::select('id', 'total_count', 'done_count', 'status', 'type', 'target_url')
            ->find($orderId);

        if (!$order) {
            Log::warning('[ProcessPingBatchJob] Order not found', [
                'batch_id' => $this->batchId,
                'order_id' => $orderId
            ]);
            return ['processed' => 0, 'failed' => count($userIds)];
        }

        if ($order->status !== 'active') {
            Log::warning('[ProcessPingBatchJob] Order not active', [
                'batch_id' => $this->batchId,
                'order_id' => $orderId,
                'status' => $order->status
            ]);
            return ['processed' => 0, 'failed' => count($userIds)];
        }

        // Get users in batch
        $users = User::select('id', 'type', 'profile_link')
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        if ($users->isEmpty()) {
            return ['processed' => 0, 'failed' => count($userIds)];
        }

        // Calculate remaining capacity
        $actualDoneCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'done')
            ->count();

        $recentPendingCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;

        if ($remaining <= 0) {
            Log::warning('[ProcessPingBatchJob] No remaining capacity', [
                'batch_id' => $this->batchId,
                'order_id' => $orderId,
                'total_count' => $order->total_count,
                'done_count' => $actualDoneCount,
                'pending_count' => $recentPendingCount,
                'remaining' => $remaining
            ]);
            return ['processed' => 0, 'failed' => count($userIds)];
        }

        // Get eligible users based on order type
        if ($type === 'resume') {
            $eligibleUserIds = $this->getResumeEligibleUsers($order, $users);
        } else {
            $eligibleUserIds = $this->getCreateEligibleUsers($order, $users);
        }

        if (empty($eligibleUserIds)) {
            Log::warning('[ProcessPingBatchJob] No eligible users found', [
                'batch_id' => $this->batchId,
                'order_id' => $orderId,
                'type' => $type,
                'total_user_ids' => count($userIds),
                'users_found' => $users->count(),
                'remaining_capacity' => $remaining
            ]);
            return ['processed' => 0, 'failed' => count($userIds)];
        }

        // Limit to remaining capacity
        $eligibleUserIds = array_slice($eligibleUserIds, 0, $remaining);

        // Batch insert pending actions using centralized service
        $batchService = app(BatchActionService::class);
        $result = $batchService->batchInsertPendingAction($order, $eligibleUserIds);

        // Enqueue MQTT publishes in chunks (80-200 per chunk)
        if ($result['inserted'] > 0) {
            $this->enqueueChunkedMqttPublishes($order, $eligibleUserIds);
        }

        return [
            'processed' => $result['inserted'],
            'failed' => count($userIds) - $result['inserted']
        ];
    }

    /**
     * Get eligible users for order creation
     */
    protected function getCreateEligibleUsers(Order $order, $users): array
    {
        // Filter out users who already have actions for this target
        $existingActions = DB::table('actions')
            ->whereIn('user_id', $users->pluck('id'))
            ->where('order_id', $order->id)
            ->whereIn('status', ['done', 'external', 'pending'])
            ->pluck('user_id')
            ->toArray();

        // Filter out users with reciprocal actions
        $reciprocalUsers = DB::table('actions as a1')
            ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
            ->whereIn('a1.user_id', $users->pluck('id'))
            ->whereIn('a1.status', ['done', 'external'])
            ->where('o1.user_id', $order->user_id)
            ->whereIn('o1.target_url', $users->pluck('profile_link'))
            ->pluck('a1.user_id')
            ->toArray();

        $excludedIds = array_unique(array_merge($existingActions, $reciprocalUsers));

        return array_values(array_diff($users->pluck('id')->toArray(), $excludedIds));
    }

    /**
     * Get eligible users for order resume
     */
    protected function getResumeEligibleUsers(Order $order, $users): array
    {
        // For resume, include pending users and check eligibility similarly
        return $this->getCreateEligibleUsers($order, $users);
    }

    /**
     * Enqueue MQTT publish jobs in chunks to avoid overwhelming the queue
     */
    protected function enqueueChunkedMqttPublishes(Order $order, array $userIds): void
    {
        $chunkSize = (int) env('MQTT_PUBLISH_CHUNK_SIZE', 100); // 80-200 recommended
        $chunks = array_chunk($userIds, $chunkSize);

        Log::info('[ProcessPingBatchJob] Enqueuing chunked MQTT publishes', [
            'order_id' => $order->id,
            'total_users' => count($userIds),
            'chunk_size' => $chunkSize,
            'total_chunks' => count($chunks)
        ]);

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                // Dispatch batch publish job to 'high' queue (16 workers)
                // This is MUCH more efficient than enqueuing 100 individual jobs
                PublishOrderAnnouncementBatchJob::dispatch(
                    $order->id,
                    $order->type,
                    $order->target_url,
                    $chunk,
                    "{$this->batchId}_publish_chunk_{$chunkIndex}"
                )->onQueue('high');

                // Small delay between chunk dispatches to prevent Redis overload
                if (($chunkIndex + 1) % 10 === 0) {
                    usleep(5000); // 5ms pause every 10 chunks
                }

            } catch (\Throwable $e) {
                Log::error('[ProcessPingBatchJob] Failed to dispatch publish chunk', [
                    'batch_id' => $this->batchId,
                    'order_id' => $order->id,
                    'chunk_index' => $chunkIndex,
                    'chunk_size' => count($chunk),
                    'error' => $e->getMessage()
                ]);

                // Continue with remaining chunks even if one fails
            }
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception)
    {
        Log::error('[ProcessPingBatchJob] Job failed', [
            'batch_id' => $this->batchId,
            'total_responses' => count($this->pingResponses),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
