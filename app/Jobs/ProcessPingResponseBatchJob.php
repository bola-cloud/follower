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
                    if ($remaining <= 0) break;

                    // Reserve slots atomically in Redis for this chunk (pre-publish reservation)
                    $reservedUserIds = $this->reserveUsersRedis($order, $chunkUserIds, $remaining);

                    if (empty($reservedUserIds)) {
                        Log::info('[ProcessPingResponseBatchJob] no reservations possible for chunk', [
                            'order_id' => $order->id,
                            'chunk_index' => $chunkIndex,
                            'requested' => count($chunkUserIds)
                        ]);
                        continue;
                    }

                    // Insert pending actions only for reserved users
                    $inserted = $this->insertPendingActionsChunk($order, $reservedUserIds);
                    $totalProcessed += $inserted;

                    // Reduce remaining capacity by number of reserved users (reserve = pending)
                    $reservedCount = count($reservedUserIds);
                    $remaining = max(0, $remaining - $reservedCount);

                    // Publish order announcements for reserved users only
                    $published = $this->publishOrderAnnouncementsChunk($order, $reservedUserIds);
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

        /**
         * Reserve a subset of requested userIds in Redis atomically to prevent oversubscription.
         * Returns the array of userIds that were successfully reserved.
         */
        private function reserveUsersRedis(Order $order, array $userIds, int $currentRemaining): array
        {
                if (empty($userIds) || $currentRemaining <= 0) return [];

                try {
                        $redis = app('redis')->connection();
                        $reservationsKey = "order:{$order->id}:reservations";
                        $ttl = (int) env('PING_RESERVATION_TTL_SEC', 900); // 15 minutes

                        // Lua script: try to SADD all requested ids, ensure set size <= currentRemaining, pop extras if needed, set TTL, return list of added & still-present ids
                        $lua = <<<'LUA'
local reservations = KEYS[1]
local capacity = tonumber(ARGV[1])
local ttl = tonumber(ARGV[2])
local requested = {}
for i=3,#ARGV do
    requested[#requested+1] = ARGV[i]
end

local added = {}
for i,uid in ipairs(requested) do
    local ok = redis.call('SADD', reservations, uid)
    if ok == 1 then
        table.insert(added, uid)
    end
end

local total = redis.call('SCARD', reservations)
if total > capacity then
    local toRemove = total - capacity
    for i=1,toRemove do
        redis.call('SPOP', reservations)
    end
end

redis.call('EXPIRE', reservations, ttl)

local result = {}
for i,uid in ipairs(added) do
    if redis.call('SISMEMBER', reservations, uid) == 1 then
        table.insert(result, uid)
    end
end
return result
LUA;

                        $args = array_merge([$reservationsKey, $currentRemaining, $ttl], array_map('strval', $userIds));
                        $reserved = $redis->eval($lua, array_values($args), 1);

                        $reservedIds = [];
                        if (is_array($reserved)) {
                                foreach ($reserved as $r) {
                                        $reservedIds[] = (int) $r;
                                }
                        }

                        if (!empty($reservedIds)) {
                                Log::info('[ProcessPingResponseBatchJob] reserved users in redis', ['order_id' => $order->id, 'reserved' => count($reservedIds)]);
                        }

                        return $reservedIds;
                } catch (\Throwable $e) {
                        Log::error('[ProcessPingResponseBatchJob] redis reservation failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        // Fallback: return empty to avoid risking oversubscription when Redis is down
                        return [];
                }
        }
}
