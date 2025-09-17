<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // Reduce chunk size to reduce lock contention; 200 is a safer default under high concurrency
        $chunkSize = 200;
        $totalInserted = 0;
        $totalSkipped = 0;
        $now = now();

        // Process in chunks to prevent memory/connection issues
        $chunks = array_chunk($userIds, $chunkSize);

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
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

                // Use raw SQL for maximum performance with proper escaping
                $inserted = $this->performBatchInsert($batchData, $order->id);
                $totalInserted += $inserted;
                $totalSkipped += (count($chunk) - $inserted);

                // Small delay between chunks to prevent overwhelming the database
                if ($chunkIndex < count($chunks) - 1 && count($chunks) > 1) {
                    usleep(10000); // 10ms pause between chunks
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
                continue;
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
    private function performBatchInsert(array $batchData, ?int $orderId = null): int
    {
        if (empty($batchData)) {
            return 0;
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
                    return DB::connection()->transaction(function () use ($sql, $values, $orderId) {
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

                        // Temporarily optimize connection for batch operations
                        DB::statement("SET SESSION sql_mode = ''");
                        DB::statement("SET SESSION unique_checks = 0");
                        DB::statement("SET SESSION foreign_key_checks = 0");

                        $affected = DB::affectingStatement($sql, $values);

                        // Restore normal settings (removed NO_AUTO_CREATE_USER for MySQL 8.0+ compatibility)
                        DB::statement("SET SESSION unique_checks = 1");
                        DB::statement("SET SESSION foreign_key_checks = 1");
                        DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

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
