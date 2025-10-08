<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Services\ResumeOrderService;
use App\Services\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessPingResponseBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300; // 5 minutes
    public $tries = 3;

    private $orderId;
    private $type;
    private $userIds;
    private $batchId;

    public function __construct(int $orderId, string $type, array $userIds, string $batchId)
    {
        $this->orderId = $orderId;
        $this->type = $type;
        $this->userIds = $userIds;
        $this->batchId = $batchId;

        // Use high-priority queue for ping responses
        $this->onQueue('high');
    }

    public function handle()
    {
        $startTime = microtime(true);
        $totalUsers = count($this->userIds);

        Log::info('[ProcessPingResponseBatchJob] start', [
            'batch_id' => $this->batchId,
            'order_id' => $this->orderId,
            'type' => $this->type,
            'user_count' => $totalUsers
        ]);

        try {
            // Load order once
            $order = Order::select('id', 'total_count', 'done_count', 'status', 'type', 'target_url', 'user_id', 'mediaId', 'userPk')
                ->find($this->orderId);

            if (!$order) {
                Log::warning('[ProcessPingResponseBatchJob] Order not found', ['order_id' => $this->orderId]);
                return;
            }

            if ($order->status !== 'active') {
                Log::info('[ProcessPingResponseBatchJob] Order not active, skipping', ['order_id' => $this->orderId, 'status' => $order->status]);
                return;
            }

            // Quick capacity check
            $doneCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->whereIn('status', ['done', 'external'])
                ->count();

            $remaining = $order->total_count - $doneCount;

            if ($remaining <= 0) {
                Log::info('[ProcessPingResponseBatchJob] Order completed, skipping', ['order_id' => $this->orderId]);
                return;
            }

            // Limit users to remaining slots
            if ($totalUsers > $remaining) {
                $this->userIds = array_slice($this->userIds, 0, $remaining);
                $totalUsers = count($this->userIds);
            }

            // Batch eligibility check: Load all users at once
            $users = User::select('id', 'type', 'points')
                ->whereIn('id', $this->userIds)
                ->get()
                ->keyBy('id');

            // Precompute excluded users who already performed done/external on OTHER orders
            // Compute canonical identifier from order->target_url (last path segment) for LIKE fallback
            $targetUrl = $order->target_url ?? '';
            $targetIdentifier = null;
            try {
                $parts = parse_url($targetUrl);
                if (!empty($parts['path'])) {
                    $segments = array_values(array_filter(explode('/', $parts['path'])));
                    if (!empty($segments)) {
                        $targetIdentifier = end($segments);
                    }
                }
            } catch (\Throwable $_e) {
                $targetIdentifier = $targetUrl;
            }
            $targetHash = $order->target_url_hash ?? ($targetIdentifier ? sha1($targetIdentifier) : sha1($targetUrl));

            // Query excluded user IDs among this batch's userIds to avoid scanning entire action table
            $excludedUserIds = DB::table('actions')
                ->join('orders', 'actions.order_id', '=', 'orders.id')
                ->whereIn('actions.status', ['done', 'external'])
                ->where('orders.id', '!=', $order->id)
                ->where(function ($q) use ($targetHash, $targetIdentifier) {
                    $q->where('orders.target_url_hash', $targetHash);
                    if ($targetIdentifier) {
                        $q->orWhere('orders.target_url', 'like', '%' . $targetIdentifier . '%');
                    }
                })
                ->whereIn('actions.user_id', $this->userIds)
                ->distinct()
                ->pluck('actions.user_id')
                ->toArray();

            if (!empty($excludedUserIds)) {
                Log::info('[ProcessPingResponseBatchJob] excluded users from other orders with same target', ['order_id' => $order->id, 'excluded_count' => count($excludedUserIds)]);
            }

            // Filter eligible users (basic check + exclude previously-acting users)
            $eligibleUserIds = [];
            foreach ($this->userIds as $userId) {
                if (in_array($userId, $excludedUserIds, true)) {
                    // skip users who already acted on same target via other orders
                    continue;
                }
                $user = $users->get($userId);
                if ($user && $this->isUserEligible($user, $order)) {
                    $eligibleUserIds[] = $userId;
                }
            }

            $eligibleCount = count($eligibleUserIds);

            if ($eligibleCount === 0) {
                Log::info('[ProcessPingResponseBatchJob] No eligible users in batch', [
                    'batch_id' => $this->batchId,
                    'order_id' => $this->orderId,
                    'total_users' => $totalUsers
                ]);
                return;
            }

            // Limit to remaining slots (eligibleUserIds may still be > remaining after checks)
            if ($eligibleCount > $remaining) {
                $eligibleUserIds = array_slice($eligibleUserIds, 0, $remaining);
                $eligibleCount = count($eligibleUserIds);
            }

            Log::info('[ProcessPingResponseBatchJob] eligible users filtered', [
                'batch_id' => $this->batchId,
                'order_id' => $this->orderId,
                'total_users' => $totalUsers,
                'eligible_count' => $eligibleCount
            ]);

            // Process in chunks to control DB load and order publish rate
            $chunkSize = (int) env('PING_BATCH_PROCESS_CHUNK_SIZE', 80);
            $chunks = array_chunk($eligibleUserIds, $chunkSize);

            $totalProcessed = 0;
            $totalPublished = 0;

            foreach ($chunks as $chunkIndex => $chunkUserIds) {
                try {
                    // Insert pending actions for this chunk
                    $inserted = $this->insertPendingActionsChunk($order, $chunkUserIds);
                    $totalProcessed += $inserted;

                    // Publish order announcements for this chunk
                    $published = $this->publishOrderAnnouncementsChunk($order, $chunkUserIds);
                    $totalPublished += $published;

                    // Small delay between chunks to control publish rate
                    if ($chunkIndex < count($chunks) - 1) {
                        $delayMs = (int) env('PING_BATCH_CHUNK_DELAY_MS', 50);
                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        }
                    }

                } catch (\Throwable $e) {
                    Log::error('[ProcessPingResponseBatchJob] chunk processing failed', [
                        'batch_id' => $this->batchId,
                        'order_id' => $this->orderId,
                        'chunk_index' => $chunkIndex,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('[ProcessPingResponseBatchJob] completed', [
                'batch_id' => $this->batchId,
                'order_id' => $this->orderId,
                'total_users' => $totalUsers,
                'eligible_count' => $eligibleCount,
                'processed' => $totalProcessed,
                'published' => $totalPublished,
                'duration_ms' => $duration
            ]);

            // Record metrics in Redis
            $this->recordMetrics($totalUsers, $eligibleCount, $totalProcessed, $totalPublished, $duration);

        } catch (\Throwable $e) {
            Log::error('[ProcessPingResponseBatchJob] job failed', [
                'batch_id' => $this->batchId,
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function isUserEligible(User $user, Order $order): bool
    {
        // Basic eligibility checks
        // Check if user already has an action for this order
        $hasAction = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->exists();

        if ($hasAction) {
            return false;
        }

        // Don't allow order owner to participate
        if ($order->user_id === $user->id) {
            return false;
        }

        // // ✅ Check if user has done/external actions on OTHER orders with same target_url
        // $hasSameTargetAction = DB::table('actions')
        //     ->join('orders', 'actions.order_id', '=', 'orders.id')
        //     ->where('orders.target_url', $order->target_url)
        //     ->where('orders.id', '!=', $order->id) // Different order, same target URL
        //     ->where('actions.user_id', $user->id)
        //     ->whereIn('actions.status', ['done', 'external']) // Exclude done/external, allow pending
        //     ->exists();

        // if ($hasSameTargetAction) {
        //     return false;
        // }

        return true;
    }

    private function insertPendingActionsChunk(Order $order, array $userIds): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $batchData = [];
        $now = now();

        foreach ($userIds as $userId) {
            $batchData[] = [
                'order_id' => $order->id,
                'user_id' => $userId,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            // Use INSERT IGNORE to handle race conditions
            $placeholders = implode(',', array_fill(0, count($batchData), '(?, ?, ?, ?, ?, ?)'));
            $values = [];
            foreach ($batchData as $row) {
                $values[] = $row['order_id'];
                $values[] = $row['user_id'];
                $values[] = $row['type'];
                $values[] = $row['status'];
                $values[] = $row['created_at'];
                $values[] = $row['updated_at'];
            }

            $sql = "INSERT IGNORE INTO actions (order_id, user_id, type, status, created_at, updated_at) VALUES {$placeholders}";
            $affected = DB::affectingStatement($sql, $values);

            return $affected;
        } catch (\Throwable $e) {
            Log::error('[ProcessPingResponseBatchJob] insert failed', [
                'order_id' => $order->id,
                'user_count' => count($userIds),
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    private function publishOrderAnnouncementsChunk(Order $order, array $userIds): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $published = 0;
        $publisher = app(\App\Services\MqttPublisherRedis::class);

        $payload = [
            'url' => $order->target_url,
            'order_id' => $order->id,
            'type' => $order->type,
            'mediaId' => $order->mediaId ?? null,
            'userPk' => $order->userPk ?? null,
        ];

        // Batch publish via Redis pipeline for speed
        try {
            $redis = app('redis')->connection();
            $queueKey = env('MQTT_QUEUE_KEY', env('REDIS_QUEUE_KEY', 'mqtt:publish'));

            // Build all jobs
            $jobs = [];
            foreach ($userIds as $userId) {
                $topic = "orders/{$userId}";
                $jobs[] = json_encode([
                    'topic' => $topic,
                    'payload' => $payload,
                    'qos' => 0,
                    'retain' => false,
                    'meta' => ['enqueued_at' => time(), 'batch_id' => $this->batchId]
                ]);
            }

            // Use pipeline to RPUSH all at once
            $redis->pipeline(function ($pipe) use ($queueKey, $jobs) {
                foreach ($jobs as $job) {
                    $pipe->rpush($queueKey, $job);
                }
            });

            $published = count($jobs);

            Log::info('[ProcessPingResponseBatchJob] published chunk', [
                'order_id' => $order->id,
                'published' => $published
            ]);

        } catch (\Throwable $e) {
            Log::error('[ProcessPingResponseBatchJob] publish chunk failed', [
                'order_id' => $order->id,
                'user_count' => count($userIds),
                'error' => $e->getMessage()
            ]);
        }

        return $published;
    }

    private function recordMetrics($totalUsers, $eligibleCount, $processed, $published, $duration)
    {
        try {
            $minute = gmdate('YmdHi');
            Redis::hincrby("ping_batch_metrics:{$minute}", 'total_users', $totalUsers);
            Redis::hincrby("ping_batch_metrics:{$minute}", 'eligible_users', $eligibleCount);
            Redis::hincrby("ping_batch_metrics:{$minute}", 'processed', $processed);
            Redis::hincrby("ping_batch_metrics:{$minute}", 'published', $published);
            Redis::hincrby("ping_batch_metrics:{$minute}", 'batches', 1);
            Redis::hincrbyfloat("ping_batch_metrics:{$minute}", 'total_duration_ms', $duration);
            Redis::expire("ping_batch_metrics:{$minute}", 3600);
        } catch (\Throwable $e) {
            // Swallow metrics errors
        }
    }
}
