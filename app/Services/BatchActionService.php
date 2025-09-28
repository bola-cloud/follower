<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class BatchActionService
{
    /**
     * High-performance batch insertion system for pending actions
     * Handles up to 5000+ actions per minute with chunking and connection optimization
     */
    public function batchInsertPendingAction(Order $order, array $userIds): array
    {
        if (empty($userIds)) {
            return ['inserted' => 0, 'skipped' => 0];
        }

    // Chunk size can be controlled via env for tuning. Default 1000 to push throughput if DB can handle it.
    // Lower values will reduce lock contention but increase round trips.
    $chunkSize = (int) env('BATCH_ACTION_CHUNK_SIZE', 100);
        $totalUsers = count($userIds);

        // Optional fast mode: if enabled and there are many users, allow larger chunks for throughput
        if ($totalUsers > 2000 && env('BATCH_ACTION_FAST_MODE', false)) {
            // allow very large chunks in fast mode but cap to a safe maximum configurable via env
            $fastMax = (int) env('BATCH_ACTION_FAST_MAX_CHUNK', 5000);
            $chunkSize = min($fastMax, max($chunkSize, intval($totalUsers / max(2, ceil($totalUsers / 1000)))));
        }
        $totalInserted = 0;
        $totalSkipped = 0;
        $now = now();

        // Process in chunks to prevent memory/connection issues
        $chunks = array_chunk($userIds, $chunkSize);

        // If configured, enqueue all chunks into Redis for asynchronous processing
        $enqueueAll = filter_var(env('BATCH_ACTION_ENQUEUE_ALL', false), FILTER_VALIDATE_BOOLEAN);
        $totalQueued = 0;

        if ($enqueueAll) {
            foreach ($chunks as $chunk) {
                try {
                    $queueKey = env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $order->id);
                    $chunkId = Str::random(8);
                    $payload = ['order_id' => $order->id, 'user_ids' => $chunk, 'chunk_id' => $chunkId, 'attempts' => 0, 'enqueued_at' => time()];
                    Redis::rpush($queueKey, json_encode($payload));
                    $nowMinute = gmdate('YmdHi');
                    $queuedKey = 'batch_action_queued:' . $nowMinute;
                    Redis::incr($queuedKey);
                    Redis::expire($queuedKey, 3600);
                    $totalQueued += count($chunk);
                } catch (\Throwable $e) {
                    Log::warning('[BatchActionService] Failed to enqueue chunk in enqueue-all mode', ['error' => $e->getMessage(), 'order_id' => $order->id]);
                }
            }

            Log::info('[BatchActionService] Enqueued all chunks for later processing', [
                'order_id' => $order->id,
                'total_users' => count($userIds),
                'total_queued' => $totalQueued
            ]);

            return ['inserted' => 0, 'skipped' => 0, 'queued' => $totalQueued];
        }

        // Apply lightweight session optimizations once for the whole batch to reduce round-trips.
        $sessionOptimized = false;
        try {
            DB::statement("SET SESSION sql_mode = ''");
            DB::statement("SET SESSION unique_checks = 0");
            DB::statement("SET SESSION foreign_key_checks = 0");
            $sessionOptimized = true;
        } catch (\Throwable $e) {
            // If we fail to set session options, proceed without session optimizations.
            Log::warning('[BatchActionService] Failed to apply session optimizations', ['error' => $e->getMessage(), 'order_id' => $order->id]);
        }

        try {
            foreach ($chunks as $chunkIndex => $chunk) {
                try {
                // Rate limiter: ensure we don't exceed configured inserts per minute
                $chunkCount = count($chunk);
                // Defensive DB health check: if the DB looks overloaded, enqueue this chunk instead of inserting
                try {
                    $batchDb = app(\App\Services\BatchDatabaseService::class);
                    if ($batchDb && method_exists($batchDb, 'getConnectionHealth')) {
                        $health = $batchDb->getConnectionHealth();
                        $threshold = (int) env('BATCH_ACTION_DB_USAGE_THRESHOLD', 80);
                        if (empty($health['healthy']) || ($health['usage_percent'] ?? 0) > $threshold) {
                            $queueKey = env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $order->id);
                            $chunkId = Str::random(8);
                            $payload = ['order_id' => $order->id, 'user_ids' => $chunk, 'chunk_id' => $chunkId, 'attempts' => 0, 'enqueued_at' => time(), 'reason' => 'db_overloaded'];
                            try { Redis::rpush($queueKey, json_encode($payload)); } catch (\Throwable $__e) {}
                            $nowMinute = gmdate('YmdHi');
                            $queuedKey = 'batch_action_queued:' . $nowMinute;
                            try { Redis::incr($queuedKey); Redis::expire($queuedKey, 3600); } catch (\Throwable $__e) {}
                            $totalSkipped += $chunkCount;
                            Log::warning('[BatchActionService] DB overloaded; enqueued chunk instead of immediate insert', ['order_id' => $order->id, 'chunk_id' => $chunkId, 'chunk_size' => $chunkCount, 'db_health' => $health]);
                            continue;
                        }
                    }
                } catch (\Throwable $e) {
                    // If health check fails, proceed normally to avoid blocking
                }
                // Allow raising the per-minute global target; default 15000 to allow 5000 inserts/min per worker
                $maxPerMinute = (int) env('BATCH_ACTION_MAX_PER_MIN', 15000);
                $nowMinute = gmdate('YmdHi');
                $globalKey = 'batch_action_rate_global:' . $nowMinute;

                try {
                    $current = Redis::incrby($globalKey, $chunkCount);
                    Redis::expire($globalKey, 70);
                } catch (\Throwable $e) {
                    Log::warning('[BatchActionService] Redis unavailable for rate limiting, proceeding', ['error' => $e->getMessage(), 'order_id' => $order->id]);
                    $current = $chunkCount;
                }

                if ($current > $maxPerMinute) {
                    // Exceeded per-minute limit: back off and attempt to retry a few times
                    Log::warning('[BatchActionService] Rate limit reached, delaying chunk', ['order_id' => $order->id, 'chunk_size' => $chunkCount, 'max_per_minute' => $maxPerMinute, 'current_minute_total' => $current]);
                    try { Redis::decrby($globalKey, $chunkCount); } catch (\Throwable $__e) {}

                    $backoffAttempts = 0;
                    $backoffMax = 6;
                    $backoffBaseMs = 50;
                    $delayed = false;
                    while ($backoffAttempts < $backoffMax) {
                        $backoffAttempts++;
                        usleep(($backoffBaseMs * (int) pow(2, $backoffAttempts - 1)) * 1000);
                        try {
                            $current = Redis::get($globalKey);
                            $current = $current ? intval($current) : 0;
                        } catch (\Throwable $e) {
                            $current = 0;
                        }
                        if ($current + $chunkCount <= $maxPerMinute) {
                            try { Redis::incrby($globalKey, $chunkCount); Redis::expire($globalKey, 70); } catch (\Throwable $e) {}
                            $delayed = true;
                            break;
                        }
                    }

                    if (!$delayed) {
                        Log::warning('[BatchActionService] Skipping chunk due to sustained rate limit; enqueuing for later', ['order_id' => $order->id, 'chunk_size' => $chunkCount]);
                        $totalSkipped += $chunkCount;

                        // Enqueue the chunk into Redis for later processing by a worker.
                        try {
                            $queueKey = env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $order->id);
                            $chunkId = Str::random(8);
                            $payload = ['order_id' => $order->id, 'user_ids' => $chunk, 'chunk_id' => $chunkId, 'attempts' => 0, 'enqueued_at' => time(), 'reason' => 'rate_limited'];
                            // Store the chunk as JSON payload with order_id and user_ids
                            Redis::rpush($queueKey, json_encode($payload));
                            // Increment a per-minute queued counter for observability
                            $queuedKey = 'batch_action_queued:' . $nowMinute;
                            Redis::incr($queuedKey);
                            Redis::expire($queuedKey, 3600);
                        } catch (\Throwable $__e) {
                            Log::warning('[BatchActionService] Failed to enqueue skipped chunk to Redis', ['error' => $__e->getMessage(), 'order_id' => $order->id]);
                        }

                        continue;
                    }
                }

                // Prepare batch data for this chunk
                $batchData = [];
                foreach ($chunk as $userId) {
                    $batchData[] = [
                        'order_id' => $order->id,
                        'user_id' => $userId,
                        'type' => $order->type,
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                // Per-chunk retry/backoff configuration (env overrides)
                $chunkMaxAttempts = (int) env('BATCH_INSERT_MAX_ATTEMPTS', 5);
                $chunkBaseBackoffMs = (int) env('BATCH_INSERT_BASE_BACKOFF_MS', 100);

                $attempt = 0;
                $inserted = 0;
                $chunkFailed = false;

                while ($attempt < $chunkMaxAttempts) {
                    $attempt++;
                    try {
                        // Process the logical batchData in smaller DB transaction chunks to limit lock scope
                    // Increase DB transaction chunk size to reduce transaction overhead. Default 1000.
                    $dbTxChunkSize = (int) env('BATCH_DB_TX_CHUNK_SIZE', 1000);
                        $txChunks = array_chunk($batchData, $dbTxChunkSize);
                        $inserted = 0;

                        foreach ($txChunks as $txIndex => $txChunk) {
                            // performBatchInsert already has its own deadlock retry; we call it per txChunk
                            $affected = $this->performBatchInsert($txChunk, $order->id, !$sessionOptimized);
                            $inserted += $affected;
                        }

                        // Successful insert of entire logical chunk, break retry loop
                        break;
                    } catch (\Throwable $e) {
                        // Detect lock-wait timeouts and deadlocks
                        $msg = $e->getMessage();
                        $sqlState = null;
                        if ($e instanceof \Illuminate\Database\QueryException && isset($e->errorInfo[0])) {
                            $sqlState = $e->errorInfo[0];
                        }

                        $isTransientLock = false;
                        if (strpos($msg, 'Lock wait timeout') !== false || strpos($msg, 'Lock wait') !== false) {
                            $isTransientLock = true;
                        }
                        if (in_array($sqlState, ['1205', '1213', '40001'], true)) {
                            $isTransientLock = true;
                        }
                        if (strpos($msg, 'Deadlock') !== false || strpos($msg, 'Deadlock found') !== false || strpos($msg, 'SQLSTATE[40001]') !== false) {
                            $isTransientLock = true;
                        }

                        Log::warning('[BatchActionService] performBatchInsert chunk attempt failed', [
                            'order_id' => $order->id,
                            'attempt' => $attempt,
                            'chunk_size' => count($batchData),
                            'error' => $e->getMessage(),
                            'sqlstate' => $sqlState
                        ]);

                        if ($isTransientLock && $attempt < $chunkMaxAttempts) {
                            // exponential backoff with small jitter
                            $sleepMs = (int) ($chunkBaseBackoffMs * pow(2, $attempt - 1) + rand(0, 50));
                            usleep($sleepMs * 1000);
                            continue; // retry
                        }

                        // Non-transient or max attempts exhausted
                        $chunkFailed = true;
                        Log::error('[BatchActionService] Chunk failed after retries', ['order_id' => $order->id, 'chunk_size' => count($batchData), 'attempts' => $attempt, 'error' => $e->getMessage()]);
                        break;
                    }
                }

                if ($chunkFailed) {
                    // Enqueue failed chunk for retry by a worker so we don't lose users
                    try {
                        $queueKey = env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $order->id);
                        $chunkId = Str::random(8);
                        $payload = ['order_id' => $order->id, 'user_ids' => $chunk, 'chunk_id' => $chunkId, 'attempts' => 0, 'enqueued_at' => time(), 'error' => 'chunk_failed_after_retries'];
                        Redis::rpush($queueKey, json_encode($payload));
                        $queuedKey = 'batch_action_queued:' . $nowMinute;
                        Redis::incr($queuedKey);
                        Redis::expire($queuedKey, 3600);
                    } catch (\Throwable $__e) {
                        Log::warning('[BatchActionService] Failed to enqueue failed chunk to Redis after retries', ['error' => $__e->getMessage(), 'order_id' => $order->id]);
                    }

                    $totalSkipped += count($chunk);
                } else {
                    $totalInserted += $inserted;
                    $totalSkipped += (count($chunk) - $inserted);
                }

                // Small configurable delay between chunks to prevent overwhelming the database. Default 1000 microseconds (1ms)
                if ($chunkIndex < count($chunks) - 1 && count($chunks) > 1) {
                    $interChunkUs = (int) env('BATCH_INTER_CHUNK_US', 1000);
                    if ($interChunkUs > 0) usleep($interChunkUs);
                }

                Log::info('[BatchActionService] Batch chunk inserted', [
                    'chunk' => $chunkIndex + 1,
                    'chunk_size' => count($chunk),
                    'inserted' => $inserted,
                    'total_inserted' => $totalInserted
                ]);

                } catch (\Throwable $e) {
                Log::error('[BatchActionService] Batch chunk failed', [
                    'chunk' => $chunkIndex + 1,
                    'error' => $e->getMessage(),
                    'order_id' => $order->id
                ]);

                // Continue with other chunks even if one fails
                    $totalSkipped += count($chunk);

                    // Enqueue failed chunk for retry by a worker so we don't lose users
                    try {
                        $queueKey = env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $order->id);
                        $chunkId = Str::random(8);
                        $payload = ['order_id' => $order->id, 'user_ids' => $chunk, 'chunk_id' => $chunkId, 'attempts' => 0, 'enqueued_at' => time(), 'error' => $e->getMessage()];
                        Redis::rpush($queueKey, json_encode($payload));
                        $queuedKey = 'batch_action_queued:' . $nowMinute;
                        Redis::incr($queuedKey);
                        Redis::expire($queuedKey, 3600);
                    } catch (\Throwable $__e) {
                        Log::warning('[BatchActionService] Failed to enqueue failed chunk to Redis', ['error' => $__e->getMessage(), 'order_id' => $order->id]);
                    }

                        continue;
            }
            }
        } finally {
            // Restore session settings if we applied them
            if (!empty($sessionOptimized)) {
                try {
                    DB::statement("SET SESSION unique_checks = 1");
                    DB::statement("SET SESSION foreign_key_checks = 1");
                    DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                } catch (\Throwable $e) {
                    Log::warning('[BatchActionService] Failed to restore session settings', ['error' => $e->getMessage(), 'order_id' => $order->id]);
                }
            }
        }

        Log::info('[BatchActionService] Batch insertion completed', [
            'order_id' => $order->id,
            'total_users' => count($userIds),
            'total_inserted' => $totalInserted,
            'total_skipped' => $totalSkipped
        ]);

        return [
            'inserted' => $totalInserted,
            'skipped' => $totalSkipped
        ];
    }

    /**
     * Perform the actual batch insert with optimized SQL
     */
    /**
     * Perform the actual batch insert with optimized SQL.
     * Adds retries on deadlock (SQLSTATE 40001) and optional per-order GET_LOCK serialization.
     *
     * @param array $batchData
     * @param int|null $orderId
     * @return int
     */
    /**
     * @param array $batchData
     * @param int|null $orderId
     * @param bool $applySession Whether to apply session-level optimizations inside this call
     * @return int
     */
    private function performBatchInsert(array $batchData, ?int $orderId = null, bool $applySession = true): int
    {
        if (empty($batchData)) {
            return 0;
        }

        // Optional fast-path using LOAD DATA LOCAL INFILE for very large batches.
        $useFastLoad = filter_var(env('BATCH_USE_FAST_LOAD', false), FILTER_VALIDATE_BOOLEAN);
        $fastLoadMin = (int) env('BATCH_FAST_LOAD_MIN_ROWS', 1000);
        if ($useFastLoad && count($batchData) >= $fastLoadMin) {
            try {
                $tmpDir = sys_get_temp_dir();
                $fileName = $tmpDir . DIRECTORY_SEPARATOR . 'actions_bulk_' . uniqid() . '.csv';
                $fp = fopen($fileName, 'w');
                if ($fp === false) {
                    throw new \RuntimeException('Failed to open temporary file for fast load');
                }

                foreach ($batchData as $row) {
                    // Use CSV with tab delimiter to be safe for URLs etc
                    fputcsv($fp, [$row['order_id'], $row['user_id'], $row['type'], $row['status'], $row['created_at'], $row['updated_at']], '\t');
                }
                fclose($fp);

                // Build LOAD DATA LOCAL INFILE SQL (expecting columns order_id,user_id,type,status,created_at,updated_at)
                $table = DB::getTablePrefix() . 'actions';
                $sql = "LOAD DATA LOCAL INFILE '" . addslashes($fileName) . "' INTO TABLE `actions` CHARACTER SET utf8mb4 FIELDS TERMINATED BY '\t' LINES TERMINATED BY '\n' (order_id, user_id, type, status, created_at, updated_at)";

                // Execute using PDO directly to allow LOCAL INFILE
                $pdo = DB::connection()->getPdo();
                // Enable local infile attribute if available
                try {
                    if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
                        $pdo->setAttribute(constant('PDO::MYSQL_ATTR_LOCAL_INFILE'), true);
                    }
                } catch (\Throwable $__e) {
                    // ignore if not allowed
                }

                $affected = $pdo->exec($sql);
                // cleanup
                @unlink($fileName);

                if ($affected === false) {
                    // fallback if exec failed
                    Log::warning('[BatchActionService] Fast LOAD DATA failed, falling back to standard insert', ['error' => json_encode($pdo->errorInfo())]);
                } else {
                    return intval($affected);
                }
            } catch (\Throwable $e) {
                Log::warning('[BatchActionService] Fast LOAD DATA path failed, falling back to standard path', ['error' => $e->getMessage()]);
                // attempt to unlink temp file if exists
                if (!empty($fileName) && file_exists($fileName)) {
                    @unlink($fileName);
                }
            }
        }

        try {
            // Use INSERT IGNORE to handle duplicates gracefully
            $placeholders = [];
            $values = [];

            foreach ($batchData as $row) {
                $placeholders[] = '(?, ?, ?, ?, ?, ?)';
                $values = array_merge($values, [
                    $row['order_id'],
                    $row['user_id'],
                    $row['type'],
                    $row['status'],
                    $row['created_at'],
                    $row['updated_at']
                ]);
            }

            $sql = "INSERT IGNORE INTO actions (order_id, user_id, type, status, created_at, updated_at) VALUES "
                 . implode(',', $placeholders);

            $maxAttempts = 4;
            $attempt = 0;

            while (true) {
                $attempt++;

                try {
                    return DB::connection()->transaction(function () use ($sql, $values, $orderId, $applySession) {
                        // Optional: obtain a lightweight advisory lock per order to serialize inserts for the same order.
                        $gotLock = false;
                        if ($orderId !== null) {
                            try {
                                $lockName = 'batch_action_order_' . intval($orderId);
                                // wait up to 100ms for lock (0.1s)
                                $res = DB::selectOne("SELECT GET_LOCK(?, 0.1) as got", [$lockName]);
                                $gotLock = ($res && isset($res->got) && intval($res->got) === 1);
                            } catch (\Throwable $le) {
                                // ignore lock acquisition errors and proceed without lock
                                $gotLock = false;
                            }
                        }

                        // Optionally apply session-level optimizations if requested for this call
                        if (!empty($applySession)) {
                            DB::statement("SET SESSION sql_mode = ''");
                            DB::statement("SET SESSION unique_checks = 0");
                            DB::statement("SET SESSION foreign_key_checks = 0");
                        }

                        $affected = DB::affectingStatement($sql, $values);

                        // Only restore if we applied session optimizations here
                        if (!empty($applySession)) {
                            DB::statement("SET SESSION unique_checks = 1");
                            DB::statement("SET SESSION foreign_key_checks = 1");
                            DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
                        }

                        // Release advisory lock if held
                        if (!empty($gotLock) && $orderId !== null) {
                            try {
                                DB::selectOne("SELECT RELEASE_LOCK(?)", ['batch_action_order_' . intval($orderId)]);
                            } catch (\Throwable $le) {
                                // ignore
                            }
                        }

                        return $affected;
                    });
                } catch (\Throwable $e) {
                    // If it's a deadlock/serialization error, retry with exponential backoff
                    $isDeadlock = false;
                    $msg = $e->getMessage();
                    if (strpos($msg, 'SQLSTATE[40001]') !== false || strpos($msg, 'Deadlock found') !== false) {
                        $isDeadlock = true;
                    }

                    Log::warning('[BatchActionService] performBatchInsert attempt failed', [
                        'attempt' => $attempt,
                        'batch_size' => count($batchData),
                        'error' => $e->getMessage()
                    ]);

                    if ($isDeadlock && $attempt < $maxAttempts) {
                        // exponential backoff plus small jitter
                        $sleepMs = (int) (pow(2, $attempt) * 50 + rand(0, 50));
                        usleep($sleepMs * 1000);
                        continue; // retry
                    }

                    // Not recoverable or max attempts reached - log and rethrow
                    Log::error('[BatchActionService] Raw batch insert failed', [
                        'error' => $e->getMessage(),
                        'batch_size' => count($batchData)
                    ]);
                    throw $e;
                }
            }

        } catch (\Throwable $e) {
            Log::error('[BatchActionService] Raw batch insert failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batchData)
            ]);
            throw $e;
        }
    }
}
