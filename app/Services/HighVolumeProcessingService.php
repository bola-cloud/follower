<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;


/**
 * Service to handle high-volume MQTT processing (500-1000 concurrent users per order)
 * Implements circuit breaker patterns, DB connection pooling, and graceful degradation
 */
class HighVolumeProcessingService
{
    private const CIRCUIT_BREAKER_THRESHOLD = 5; // failures before circuit opens
    private const CIRCUIT_BREAKER_TIMEOUT = 60; // seconds
    private const MAX_DB_CONNECTIONS = 80; // % of max_connections to use
    private const BATCH_SIZE_HIGH_LOAD = 100;
    private const BATCH_SIZE_NORMAL = 500;

    public function __construct(
        private BatchDatabaseService $batchService,
        private DatabaseConnectionManager $connectionManager
    ) {}

    /**
     * Process MQTT action with circuit breaker and load management
     */
    public function processAction(int $orderId, int $userId, string $status): array
    {
        // Defensive: if device reports busy or user_id is missing, ignore to avoid DB/queue side-effects
        if ($status === 'busy' || empty($userId)) {
            Log::info('[HighVolumeProcessingService] Ignoring busy status or empty user', ['order_id' => $orderId, 'user_id' => $userId, 'status' => $status]);
            return ['success' => true, 'skipped' => true, 'message' => 'Device busy or invalid user - ignored'];
        }
        // If configured, enqueue all incoming high-volume actions to Redis
        // This offloads DB work to background processors and protects MySQL
        $enqueueAll = filter_var(env('HIGH_VOLUME_ENQUEUE_ALL', false), FILTER_VALIDATE_BOOLEAN);
        if ($enqueueAll) {
            try {
                $actionData = ['order_id' => $orderId, 'user_id' => $userId, 'status' => $status, 'ts' => now()->timestamp];
                $queueKey = env('HIGH_VOLUME_QUEUE_KEY', 'high_volume_actions_queue');
                Redis::rpush($queueKey, json_encode($actionData));
                Redis::expire($queueKey, 3600);
                $queueSize = Redis::llen($queueKey);
                Log::info('[HighVolumeProcessingService] Enqueued action (enqueue-all enabled)', ['order_id' => $orderId, 'user_id' => $userId, 'queue_size' => $queueSize]);
                return ['success' => true, 'queued' => true, 'queue_size' => $queueSize];
            } catch (\Throwable $e) {
                Log::warning('[HighVolumeProcessingService] Failed to enqueue action in enqueue-all mode', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
                // Fallthrough to regular processing if Redis is unavailable
            }
        }
        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            return $this->queueForLaterProcessing($orderId, $userId, $status);
        }

        // Check system load and adapt processing strategy
        $systemLoad = $this->getSystemLoad();

        if ($systemLoad['db_overloaded']) {
            Log::warning('Database overloaded, switching to queue-only mode');
            return $this->queueForLaterProcessing($orderId, $userId, $status);
        }

        try {
            // Attempt immediate processing with connection pooling
            $result = $this->processActionImmediate($orderId, $userId, $status, $systemLoad);
            $this->recordSuccess();
            return $result;

        } catch (\Exception $e) {
            $this->recordFailure($e);

            // Fallback to queuing if immediate processing fails
            Log::warning('Immediate processing failed, falling back to queue', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
                'user_id' => $userId
            ]);

            return $this->queueForLaterProcessing($orderId, $userId, $status);
        }
    }

    /**
     * Process action immediately with optimized connection handling
     */
    private function processActionImmediate(int $orderId, int $userId, string $status, array $systemLoad): array
    {
        return DatabaseConnectionManager::executeBatch(function($connection) use ($orderId, $userId, $status, $systemLoad) {

            // Use optimized single-query update
            $sql = "UPDATE actions SET
                        status = ?,
                        performed_at = NOW(),
                        updated_at = NOW()
                    WHERE order_id = ?
                    AND user_id = ?
                    AND status = 'pending'";

            $updated = $connection->affectingStatement($sql, [$status, $orderId, $userId]);

            if ($updated === 0) {
                // Action not found, not pending, or already processed
                // Check if action exists and try to create it if missing
                $existingAction = $connection->table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->first(['status']);

                    if (!$existingAction) {
                    // Action doesn't exist - this is unusual and suggests a timing issue
                    // Log the issue but DON'T create actions on-the-fly during MQTT processing unless safe
                    Log::warning('Action not found during MQTT update - this suggests a race condition or missing action creation', [
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'status' => $status,
                        'note' => 'Actions should be created BEFORE devices can respond to pings'
                    ]);

                    // Only create action if this is a "pending" status (initial response to ping)
                    // Do NOT create actions for completion statuses like "done", "external", etc.
                    if ($status === 'pending') {
                        // Rate limiter: reserve a slot in the per-minute global counter to avoid DB overload
                        $maxPerMinute = (int) env('BATCH_ACTION_MAX_PER_MIN', 5000);
                        $nowMinute = gmdate('YmdHi');
                        $globalKey = 'batch_action_rate_global:' . $nowMinute;
                        $reserved = false;
                        $reserveAttempts = 0;
                        $reserveMax = 6;

                        while ($reserveAttempts < $reserveMax) {
                            $reserveAttempts++;
                            try {
                                $current = Redis::get($globalKey);
                                $current = $current ? intval($current) : 0;
                                if ($current + 1 <= $maxPerMinute) {
                                    Redis::incrby($globalKey, 1);
                                    Redis::expire($globalKey, 70);
                                    $reserved = true;
                                    break;
                                }
                            } catch (\Throwable $e) {
                                // Redis unavailable: log and allow creation (to avoid blocking single-action flows)
                                Log::warning('[HighVolumeProcessingService] Redis unavailable for rate limiting, proceeding', ['error' => $e->getMessage(), 'order_id' => $orderId]);
                                $reserved = true;
                                break;
                            }

                            // backoff
                            usleep((int) (50 * pow(2, $reserveAttempts - 1)) * 1000);
                        }

                        if (!$reserved) {
                            // Could not reserve a slot; avoid creating now to protect DB and queue instead
                            Log::warning('[HighVolumeProcessingService] Rate limit exceeded for creating missing action; queuing instead', ['order_id' => $orderId, 'user_id' => $userId, 'max_per_minute' => $maxPerMinute]);
                            return $this->queueForLaterProcessing($orderId, $userId, $status);
                        }
                        // Get order type for action creation
                        $orderType = $connection->table('orders')
                            ->where('id', $orderId)
                            ->value('type') ?? 'create';

                        // Retry logic with advisory lock per-order to reduce deadlocks under high concurrency
                        $maxRetries = 4;
                        $created = false;
                        $lockName = 'hv_action_order_' . intval($orderId);

                        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                            $gotLock = false;
                            try {
                                // attempt to acquire lightweight advisory lock for this order (0.1s)
                                $res = $connection->selectOne('SELECT GET_LOCK(?, 0.1) as got', [$lockName]);
                                $gotLock = ($res && isset($res->got) && intval($res->got) === 1);
                            } catch (\Throwable $le) {
                                // ignore lock acquisition errors and proceed without lock
                                $gotLock = false;
                            }

                            try {
                                $created = $connection->table('actions')->insertOrIgnore([
                                    'order_id' => $orderId,
                                    'user_id' => $userId,
                                    'type' => $orderType,
                                    'status' => $status,
                                    'performed_at' => now(),
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                // release advisory lock if acquired
                                if ($gotLock) {
                                    try { $connection->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable $__) {}
                                }

                                break; // success

                            } catch (\Illuminate\Database\QueryException $e) {
                                $msg = $e->getMessage();
                            $isDeadlock = (strpos($msg, 'SQLSTATE[40001]') !== false || strpos($msg, 'Deadlock found') !== false);
                            $isLockTimeout = (strpos($msg, '1205') !== false || strpos($msg, 'Lock wait timeout') !== false);

                            if (($isDeadlock || $isLockTimeout) && $attempt < $maxRetries) {
                                $delay = (int) (pow(2, $attempt - 1) * 50000); // 50ms,100ms,200ms...
                                Log::warning('[HighVolumeProcessingService] Lock/deadlock during action creation, retrying', [
                                    'attempt' => $attempt,
                                    'delay_ms' => $delay / 1000,
                                    'order_id' => $orderId,
                                    'user_id' => $userId,
                                    'error' => $e->getMessage()
                                ]);

                                // release lock if held
                                if (!empty($gotLock)) { try { $connection->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable $__) {} }

                                usleep($delay);
                                continue; // retry
                            }

                            // otherwise rethrow after ensuring lock released
                            if (!empty($gotLock)) { try { $connection->selectOne('SELECT RELEASE_LOCK(?)', [$lockName]); } catch (\Throwable $__) {} }
                            // If we reserved a rate slot, release it because creation failed permanently
                            try { Redis::decrby($globalKey, 1); } catch (\Throwable $__e) {}
                            throw $e;
                        }
                    }

                    if ($created) {
                        Log::info('Action created during MQTT update', [
                            'order_id' => $orderId,
                            'user_id' => $userId,
                            'status' => $status
                        ]);

                        // Update order done_count if status is done
                        if ($status === 'done') {
                            $connection->statement("
                                UPDATE orders
                                SET done_count = LEAST(done_count + 1, total_count),
                                    updated_at = NOW()
                                WHERE id = ? AND done_count < total_count
                            ", [$orderId]);

                            $this->checkOrderCompletion($orderId, $connection);
                        }

                        return [
                            'success' => true,
                            'message' => 'Action created and processed immediately',
                            'immediate' => true,
                            'created' => true
                        ];
                    } else {
                        Log::warning('Failed to create missing action during MQTT update', [
                            'order_id' => $orderId,
                            'user_id' => $userId,
                            'status' => $status
                        ]);
                    }
                } else {
                    // For completion statuses (done, external, etc.) when action doesn't exist,
                    // just log and return - don't try to create actions retroactively
                    Log::warning('Action not found for completion status - likely timing issue', [
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'status' => $status,
                        'note' => 'Device completed task but action was never created or was deleted'
                    ]);

                    return [
                        'success' => false,
                        'message' => 'Action not found for completion status',
                        'immediate' => true,
                        'missing_action' => true
                    ];
                }
                } else {
                    // Action exists but wasn't updated (likely already processed)
                    Log::info('Action exists but not updated during MQTT response', [
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'existing_status' => $existingAction->status,
                        'requested_status' => $status
                    ]);
                }

                return [
                    'success' => true,
                    'message' => 'Action already processed or not updatable',
                    'immediate' => true,
                    'existing_status' => $existingAction->status ?? null
                ];
            }

            // Update order done_count if status is done (safely prevent exceeding total_count)
            if ($status === 'done') {
                // Use safe increment that doesn't exceed total_count
                $connection->statement("
                    UPDATE orders
                    SET done_count = LEAST(done_count + 1, total_count),
                        updated_at = NOW()
                    WHERE id = ? AND done_count < total_count
                ", [$orderId]);

                // Check for order completion (lightweight check)
                $this->checkOrderCompletion($orderId, $connection);
            }

            return [
                'success' => true,
                'message' => 'Action processed immediately',
                'immediate' => true,
                'rows_affected' => $updated
            ];
        });
    }

    /**
     * Queue action for batch processing
     */
    private function queueForLaterProcessing(int $orderId, int $userId, string $status): array
    {
        $actionData = [
            'order_id' => $orderId,
            'user_id' => $userId,
            'status' => $status,
            'queued_at' => now()->timestamp
        ];

        try {
            // Use Redis for high-performance queuing
            $queueKey = 'high_volume_actions_queue';
            Redis::rpush($queueKey, json_encode($actionData));
            Redis::expire($queueKey, 3600); // 1 hour expiry

            $queueSize = Redis::llen($queueKey);

            // Note: Batch job processing removed as per user requirements
            // Queue will be processed by scheduled jobs or manual cleanup

            return [
                'success' => true,
                'message' => 'Action queued for batch processing',
                'queued' => true,
                'queue_size' => $queueSize
            ];

        } catch (\Exception $e) {
            Log::error('Failed to queue action for batch processing', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
                'user_id' => $userId
            ]);

            throw $e;
        }
    }

    /**
     * Get current system load metrics
     */
    private function getSystemLoad(): array
    {
        try {
            // Check database connections
            $dbHealth = $this->batchService->getConnectionHealth();
            $dbOverloaded = !$dbHealth['healthy'] || $dbHealth['usage_percent'] > self::MAX_DB_CONNECTIONS;

            // Check queue lengths
            $queueSize = Redis::llen('high_volume_actions_queue');
            $queueBacklog = $queueSize > 1000;

            // Check Redis memory usage
            $redisInfo = Redis::info('memory');
            $redisMemoryMB = isset($redisInfo['used_memory']) ? round($redisInfo['used_memory'] / 1024 / 1024, 2) : 0;

            return [
                'db_overloaded' => $dbOverloaded,
                'db_connections' => $dbHealth['connections'] ?? 0,
                'db_usage_percent' => $dbHealth['usage_percent'] ?? 0,
                'queue_backlog' => $queueBacklog,
                'queue_size' => $queueSize,
                'redis_memory_mb' => $redisMemoryMB,
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            Log::warning('Failed to get system load metrics', ['error' => $e->getMessage()]);

            return [
                'db_overloaded' => false,
                'queue_backlog' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Circuit breaker pattern implementation
     */
    private function isCircuitOpen(): bool
    {
        $failures = Cache::get('circuit_breaker_failures', 0);
        $lastFailure = Cache::get('circuit_breaker_last_failure');

        if ($failures >= self::CIRCUIT_BREAKER_THRESHOLD) {
            if ($lastFailure && now()->diffInSeconds($lastFailure) < self::CIRCUIT_BREAKER_TIMEOUT) {
                return true;
            } else {
                // Reset circuit breaker after timeout
                $this->resetCircuitBreaker();
                return false;
            }
        }

        return false;
    }

    private function recordSuccess(): void
    {
        // Reset circuit breaker on success
        $this->resetCircuitBreaker();
    }

    private function recordFailure(\Exception $e): void
    {
        $failures = Cache::get('circuit_breaker_failures', 0) + 1;
        Cache::put('circuit_breaker_failures', $failures, 300); // 5 minutes
        Cache::put('circuit_breaker_last_failure', now(), 300);

        Log::warning('Circuit breaker recorded failure', [
            'failures' => $failures,
            'error' => $e->getMessage()
        ]);
    }

    private function resetCircuitBreaker(): void
    {
        Cache::forget('circuit_breaker_failures');
        Cache::forget('circuit_breaker_last_failure');
    }

    /**
     * Determine optimal batch size based on system load
     */
    private function getBatchSize(): int
    {
        $systemLoad = $this->getSystemLoad();

        if ($systemLoad['db_overloaded'] || $systemLoad['queue_backlog']) {
            return self::BATCH_SIZE_HIGH_LOAD;
        }

        return self::BATCH_SIZE_NORMAL;
    }

    /**
     * Check if we should dispatch a batch job
     */
    private function shouldDispatchBatchJob(): bool
    {
        $lastDispatch = Cache::get('last_batch_dispatch');

        // Don't dispatch more than once every 30 seconds
        return !$lastDispatch || now()->diffInSeconds($lastDispatch) > 30;
    }

    /**
     * Check if order should be completed
     */
    private function checkOrderCompletion(int $orderId, $connection): void
    {
        $order = $connection->table('orders')
            ->where('id', $orderId)
            ->first(['done_count', 'total_count', 'status']);

        if ($order &&
            $order->done_count >= $order->total_count &&
            $order->status !== 'completed') {

            $connection->table('orders')
                ->where('id', $orderId)
                ->where('status', '!=', 'completed')
                ->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);

            Log::info('Order auto-completed', [
                'order_id' => $orderId,
                'done_count' => $order->done_count,
                'total_count' => $order->total_count
            ]);
        }
    }

    /**
     * Get processing statistics for monitoring
     */
    public function getStats(): array
    {
        $systemLoad = $this->getSystemLoad();
        $circuitOpen = $this->isCircuitOpen();

        return [
            'circuit_breaker_open' => $circuitOpen,
            'system_load' => $systemLoad,
            'batch_size' => $this->getBatchSize(),
            'connection_pool_stats' => DatabaseConnectionManager::getPoolStats()
        ];
    }

    /**
     * Bulk process queued actions (called by batch job)
     * NOTE: This method is currently unused as batch job processing has been disabled per requirements
     */
    public function processBatch(int $maxActions = 1000): array
    {
        $queueKey = 'high_volume_actions_queue';
        $processed = 0;
        $errors = 0;
        $startTime = microtime(true);

        try {
            $actions = [];

            // Get actions from queue
            for ($i = 0; $i < $maxActions; $i++) {
                $item = Redis::lpop($queueKey);
                if (!$item) break;

                $action = json_decode($item, true);
                if ($action) {
                    $actions[] = $action;
                }
            }

            if (empty($actions)) {
                return ['processed' => 0, 'errors' => 0, 'duration' => 0];
            }

            // Process in optimized batches
            $processed = $this->batchService->batchUpdateActionStatus($actions);

            $duration = microtime(true) - $startTime;

            Log::info('High-volume batch processing completed', [
                'processed' => $processed,
                'errors' => $errors,
                'duration' => round($duration, 2),
                'rate_per_second' => round($processed / max($duration, 0.1), 2)
            ]);

            return [
                'processed' => $processed,
                'errors' => $errors,
                'duration' => $duration
            ];

        } catch (\Exception $e) {
            Log::error('Batch processing failed', [
                'error' => $e->getMessage(),
                'processed' => $processed
            ]);

            // Re-queue remaining actions
            if (!empty($actions)) {
                foreach (array_slice($actions, $processed) as $action) {
                    Redis::rpush($queueKey, json_encode($action));
                }
            }

            throw $e;
        }
    }
}
