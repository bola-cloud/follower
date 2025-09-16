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

        $chunkSize = 500; // Optimal chunk size for MySQL performance
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
                $inserted = $this->performBatchInsert($batchData);
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
    private function performBatchInsert(array $batchData): int
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

            // Execute with connection optimization
            return DB::connection()->transaction(function () use ($sql, $values) {
                // Temporarily optimize connection for batch operations
                DB::statement("SET SESSION sql_mode = ''");
                DB::statement("SET SESSION unique_checks = 0");
                DB::statement("SET SESSION foreign_key_checks = 0");

                $affected = DB::affectingStatement($sql, $values);

                // Restore normal settings (removed NO_AUTO_CREATE_USER for MySQL 8.0+ compatibility)
                DB::statement("SET SESSION unique_checks = 1");
                DB::statement("SET SESSION foreign_key_checks = 1");
                DB::statement("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

                return $affected;
            });

        } catch (\Throwable $e) {
            Log::error('[BatchActionService] Raw batch insert failed', [
                'error' => $e->getMessage(),
                'batch_size' => count($batchData)
            ]);
            throw $e;
        }
    }
}
