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
    /**
     * Increase attempts to tolerate transient DB/Redis blips under high load.
     * Using a backoff gives time for resources to recover between retries.
     */
    public $tries = 5;
    public $backoff = 60; // seconds to wait before retrying

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

        // Log::info('[ProcessPingResponseBatchJob] start', [
        //     'batch_id' => $this->batchId,
        //     'order_id' => $this->orderId,
        //     'type' => $this->type,
        //     'user_count' => $totalUsers,
        //     'attempt' => $this->attempts()
        // ]);

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

            // Quick capacity check - count only 'done' actions (external should not reduce capacity)
            $doneCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'done')
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

            // ✅ CRITICAL FIX: Use centralized ResumeOrderService::batchCheckEligibility
            // This ensures consistent eligibility logic across all jobs and commands
            $resumeService = app(\App\Services\ResumeOrderService::class);
            $eligibleUserIds = $resumeService->batchCheckEligibility($order, $this->userIds);
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

            // Log::info('[ProcessPingResponseBatchJob] eligible users filtered', [
            //     'batch_id' => $this->batchId,
            //     'order_id' => $this->orderId,
            //     'total_users' => $totalUsers,
            //     'eligible_count' => $eligibleCount
            // ]);

            // Compute capacity counts before publishing (no locks)
            $doneCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'done')
                ->count();

            $pendingCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subMinutes(15))
                ->count();

            $remaining = $order->total_count - $doneCount;
            $availableSlots = $order->total_count - $doneCount - $pendingCount;

            // Log::info('[ProcessPingResponseBatchJob] capacity computed', [
            //     'order_id' => $order->id,
            //     'done_count' => $doneCount,
            //     'pending_count' => $pendingCount,
            //     'remaining' => $remaining,
            //     'available_slots' => $availableSlots
            // ]);

            if ($remaining <= 0) {
                Log::info('[ProcessPingResponseBatchJob] Order already completed (after capacity check), skipping', ['order_id' => $order->id]);
                return;
            }

            if ($availableSlots <= 0) {
                Log::info('[ProcessPingResponseBatchJob] No available slots (pending fills capacity), skipping', ['order_id' => $order->id]);
                return;
            }

            // Process in chunks to control DB load and order publish rate
            $chunkSize = (int) env('PING_BATCH_PROCESS_CHUNK_SIZE', 80);
            $chunks = array_chunk($eligibleUserIds, $chunkSize);

            $totalProcessed = 0;
            $totalPublished = 0;

                foreach ($chunks as $chunkIndex => $chunkUserIds) {
                    try {
                        if ($availableSlots <= 0) {
                            // no slots left
                            break;
                        }

                        // Trim chunk to availableSlots as a first guard
                        $toAttempt = $chunkUserIds;
                        if (count($toAttempt) > $availableSlots) {
                            $toAttempt = array_slice($toAttempt, 0, $availableSlots);
                        }

                        // Insert pending actions (INSERT IGNORE). This is the authoritative step that creates/claims slots.
                        $inserted = $this->insertPendingActionsChunk($order, $toAttempt);
                        $totalProcessed += $inserted;

                        // Determine which user_ids actually have actions now (pending/done/external)
                        $existingUserIds = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->whereIn('user_id', $toAttempt)
                            ->whereIn('status', ['pending', 'done', 'external'])
                            ->pluck('user_id')
                            ->toArray();

                        if (empty($existingUserIds)) {
                            // nothing to publish for this chunk
                            continue;
                        }

                        // Publish only for user ids that now have actions
                        $published = $this->publishOrderAnnouncementsChunk($order, $existingUserIds);
                        $totalPublished += $published;

                        // Recompute capacity after inserting this chunk to stay accurate under concurrent load
                        $doneCount = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->where('status', 'done')
                            ->count();

                        $pendingCount = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->where('status', 'pending')
                            ->where('created_at', '>=', now()->subMinutes(15))
                            ->count();

                        $remaining = $order->total_count - $doneCount;
                        $availableSlots = max(0, $order->total_count - $doneCount - $pendingCount);

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

            // Log::info('[ProcessPingResponseBatchJob] completed', [
            //     'batch_id' => $this->batchId,
            //     'order_id' => $this->orderId,
            //     'total_users' => $totalUsers,
            //     'eligible_count' => $eligibleCount,
            //     'processed' => $totalProcessed,
            //     'published' => $totalPublished,
            //     'duration_ms' => $duration
            // ]);

            // Record metrics in Redis
            $this->recordMetrics($totalUsers, $eligibleCount, $totalProcessed, $totalPublished, $duration);

        } catch (\Throwable $e) {
            // Classify transient errors (DB/Redis/connection-related) vs permanent ones.
            $msg = $e->getMessage();

            $isTransient = false;
            // Common indicators of transient failures
            $transientIndicators = [
                'Lock wait timeout',
                'Deadlock',
                'SQLSTATE[40001]',
                'SQLSTATE[1205]',
                'SQLSTATE[HY000]',
                'Connection refused',
                'Connection timed out',
                'Could not connect',
                'Connection reset',
                'server has gone away',
                'read ECONNRESET',
            ];

            foreach ($transientIndicators as $needle) {
                if (stripos($msg, $needle) !== false) {
                    $isTransient = true;
                    break;
                }
            }

            // Also treat common DB/Redis exception classes as transient
            if ($e instanceof \Illuminate\Database\QueryException || $e instanceof \PDOException ||
                (class_exists('\Illuminate\Redis\Connections\Connection') && $e instanceof \Illuminate\Redis\Connections\Connection) ||
                (class_exists('\RedisException') && $e instanceof \RedisException)) {
                $isTransient = true;
            }

            $context = [
                'batch_id' => $this->batchId,
                'order_id' => $this->orderId,
                'attempts' => method_exists($this, 'attempts') ? $this->attempts() : null,
                'error' => $e->getMessage(),
            ];

            if ($isTransient) {
                Log::warning('[ProcessPingResponseBatchJob] transient error, releasing job for retry', $context + ['trace' => $e->getTraceAsString()]);

                // If we still have attempts left, release with backoff (respect job backoff where present)
                try {
                    $delay = property_exists($this, 'backoff') ? $this->backoff : 60;
                    // release the job back to the queue to be retried after delay
                    if (method_exists($this, 'release')) {
                        $this->release($delay);
                        return; // stop processing this attempt
                    }
                } catch (\Throwable $__e) {
                    // If release fails, fall through to logging and return to avoid throwing
                    Log::warning('[ProcessPingResponseBatchJob] failed to release job after transient error', ['error' => $__e->getMessage()]);
                }
            }

            // For non-transient or if release not available, log full error and do not rethrow to avoid rapid failures.
            Log::error('[ProcessPingResponseBatchJob] job failed (permanent or unrecoverable)', $context + ['trace' => $e->getTraceAsString()]);

            // IMPORTANT: Rethrow the exception so Laravel can track it properly and increment attempts
            // Without rethrowing, the job silently "succeeds" and never actually processes
            throw $e;
        }
    }

    // REMOVED: isUserEligible() - now using ResumeOrderService::batchCheckEligibility() for all eligibility checks

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

            // Log::info('[ProcessPingResponseBatchJob] published chunk', [
            //     'order_id' => $order->id,
            //     'published' => $published
            // ]);

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
