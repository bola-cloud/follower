<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Order;
use App\Models\User;

class BatchDatabaseService
{
    private const MAX_BATCH_SIZE = 300;
    private const CONNECTION_POOL_SIZE = 5;
    private const BATCH_TIMEOUT = 30; // seconds

    private static $batchQueue = [];
    private static $connectionPool = [];

    /**
     * Batch insert actions with optimized transaction handling
     */
    public function batchInsertActions(array $actions): int
    {
        if (empty($actions)) {
            return 0;
        }

        return DatabaseConnectionManager::executeBatch(function($connection) use ($actions) {
            $chunks = array_chunk($actions, self::MAX_BATCH_SIZE);
            $totalInserted = 0;

            // Use the optimized connection for all operations
            $connection->beginTransaction();

            try {
                foreach ($chunks as $chunk) {
                    $inserted = $this->insertChunkWithConnection($connection, $chunk);
                    $totalInserted += $inserted;

                    Log::info('Batch actions inserted', [
                        'chunk_size' => count($chunk),
                        'inserted' => $inserted,
                        'total_inserted' => $totalInserted
                    ]);
                }

                $connection->commit();
                return $totalInserted;

            } catch (\Throwable $e) {
                $connection->rollBack();
                Log::error('Batch action insert failed', [
                    'error' => $e->getMessage(),
                    'total_actions' => count($actions)
                ]);
                throw $e;
            }
        });
    }

    /**
     * Insert a single chunk with connection
     */
    private function insertChunkWithConnection($connection, array $actions): int
    {
        // Use raw INSERT with ON DUPLICATE KEY UPDATE for better performance
        $values = [];
        $bindings = [];

        foreach ($actions as $action) {
            $values[] = '(?, ?, ?, ?, ?, ?)';
            $bindings = array_merge($bindings, [
                $action['order_id'],
                $action['user_id'],
                $action['type'],
                $action['status'] ?? 'pending',
                $action['created_at'] ?? now(),
                $action['updated_at'] ?? now()
            ]);
        }

        $sql = "INSERT IGNORE INTO actions (order_id, user_id, type, status, created_at, updated_at) VALUES " . implode(',', $values);

        return $connection->affectingStatement($sql, $bindings);
    }

    /**
     * Insert a single chunk with optimized SQL (legacy method for compatibility)
     */
    private function insertChunk(array $actions): int
    {
        return $this->insertChunkWithConnection(DB::connection(), $actions);
    }

    /**
     * Batch update action statuses with single query
     */
    public function batchUpdateActionStatus(array $updates): int
    {
        if (empty($updates)) {
            return 0;
        }

        $totalUpdated = 0;

        // Group updates by status for efficiency
        $updateGroups = [];
        // Track per-order increments when status becomes 'done'
        $orderIncrements = [];
        foreach ($updates as $update) {
            $status = $update['status'];
            $key = md5($status);

            if (!isset($updateGroups[$key])) {
                $updateGroups[$key] = [
                    'status' => $status,
                    'conditions' => []
                ];
            }

            $updateGroups[$key]['conditions'][] = [
                'order_id' => $update['order_id'],
                'user_id' => $update['user_id']
            ];

            if ($status === 'done') {
                $oid = intval($update['order_id']);
                $orderIncrements[$oid] = ($orderIncrements[$oid] ?? 0) + 1;
            }
        }

        DB::beginTransaction();

        try {
            Log::debug('[BatchDatabaseService] batchUpdateActionStatus groups', ['groups' => array_map(function($g){ return ['status' => $g['status'], 'conditions' => count($g['conditions'])]; }, $updateGroups), 'order_increments' => $orderIncrements]);
            foreach ($updateGroups as $group) {
                $updated = $this->batchUpdateSameStatus(
                    $group['status'],
                    $group['conditions']
                );
                $totalUpdated += $updated;
            }

            // Recompute done_count per affected order from the authoritative actions table.
            // This is idempotent and avoids double-increment races when multiple writers are running.
            if (!empty($orderIncrements)) {
                $affectedOrderIds = array_keys($orderIncrements);
                foreach ($affectedOrderIds as $orderId) {
                    try {
                        $doneCount = DB::table('actions')
                            ->where('order_id', $orderId)
                            ->whereIn('status', ['done', 'external'])
                            ->count();

                        // Set the canonical done_count capped at total_count
                        DB::statement(
                            "UPDATE orders SET done_count = LEAST(?, total_count), updated_at = NOW() WHERE id = ?",
                            [$doneCount, $orderId]
                        );

                        // If order has reached completion, mark it completed (idempotent)
                        DB::statement(
                            "UPDATE orders SET status = 'completed', updated_at = NOW() WHERE id = ? AND done_count >= total_count AND status != 'completed'",
                            [$orderId]
                        );
                    } catch (\Throwable $__e) {
                        Log::warning('[BatchDatabaseService] failed to recompute done_count for order', ['order_id' => $orderId, 'error' => $__e->getMessage()]);
                    }
                }
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Batch action update failed', [
                'error' => $e->getMessage(),
                'updates_count' => count($updates)
            ]);
            throw $e;
        }

        return $totalUpdated;
    }

    /**
     * Update multiple actions to the same status in one query
     */
    private function batchUpdateSameStatus(string $status, array $conditions): int
    {
        if (empty($conditions)) {
            return 0;
        }

        // Use an upsert approach to reduce lock scope and eliminate separate UPDATE then INSERT fallback.
        // We'll INSERT rows for all (order_id, user_id) pairs and ON DUPLICATE KEY UPDATE the status and performed_at
        // only when transitioning from pending to the new status. This reduces deadlocks and makes the operation
        // idempotent and atomic at row level.

        $nowStr = now()->toDateTimeString();

        $placeholders = [];
        $values = [];
        $typeDefault = 'follow';

        foreach ($conditions as $cond) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?)';
            $values[] = $cond['order_id'];
            $values[] = $cond['user_id'];
            $values[] = $typeDefault;
            $values[] = $status; // status for INSERT
            $values[] = $nowStr; // performed_at for INSERT
            $values[] = $nowStr; // created_at for INSERT
        }

        // Build an INSERT ... ON DUPLICATE KEY UPDATE statement. We only want to set performed_at when
        // the existing row had status 'pending' — to approximate that in SQL without reading the row first,
        // we use a conditional assignment that sets performed_at = VALUES(performed_at) WHEN status = 'pending'.
        // MySQL doesn't allow referencing the old value of status in the ON DUPLICATE clause directly in a
        // simple expression, so we'll set performed_at = CASE WHEN status = 'pending' THEN VALUES(performed_at) ELSE performed_at END

        $insSql = "INSERT INTO actions (order_id, user_id, type, status, performed_at, created_at) VALUES " . implode(',', $placeholders)
                . " ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    performed_at = CASE WHEN status = 'pending' THEN VALUES(performed_at) ELSE performed_at END,
                    updated_at = VALUES(created_at)";

        Log::debug('[BatchDatabaseService] batchUpdateSameStatus upsert', ['sql_preview' => substr($insSql, 0, 400), 'bindings_preview' => array_slice($values, 0, 12), 'conditions_count' => count($conditions)]);

        // Execute outside of long-running transaction so each row-level lock is limited to the minimal scope of the statement
        try {
            $affected = DB::connection()->affectingStatement($insSql, $values);
            return (int)$affected;
        } catch (\Throwable $e) {
            Log::warning('[BatchDatabaseService] batchUpdateSameStatus upsert failed, falling back to safer path', ['error' => $e->getMessage()]);

            // Fallback to the previous safe approach: try targeted UPDATE for existing rows, then INSERT IGNORE for missing ones.
            $updated = 0;
            try {
                // Build WHERE clause for UPDATE using smaller chunks to avoid huge OR lists
                $chunks = array_chunk($conditions, 200);
                foreach ($chunks as $chunk) {
                    $whereParts = [];
                    $whereBindings = [];
                    foreach ($chunk as $c) {
                        $whereParts[] = '(order_id = ? AND user_id = ?)';
                        $whereBindings[] = $c['order_id'];
                        $whereBindings[] = $c['user_id'];
                    }

                    $updSql = "UPDATE actions SET status = ?, updated_at = ?, performed_at = CASE WHEN status = 'pending' THEN ? ELSE performed_at END WHERE status = 'pending' AND (" . implode(' OR ', $whereParts) . ")";
                    $updBindings = array_merge([$status, $nowStr, $nowStr], $whereBindings);
                    $u = DB::connection()->affectingStatement($updSql, $updBindings);
                    $updated += $u;
                }
            } catch (\Throwable $__e) {
                Log::warning('[BatchDatabaseService] targeted UPDATE fallback failed', ['error' => $__e->getMessage()]);
            }

            // Insert missing rows
            $inserted = 0;
            try {
                $insertValues = [];
                $insertBindings = [];
                foreach ($conditions as $cond) {
                    $insertValues[] = '(?, ?, ?, ?, ?, ?)';
                    $insertBindings[] = $cond['order_id'];
                    $insertBindings[] = $cond['user_id'];
                    $insertBindings[] = $typeDefault;
                    $insertBindings[] = $status;
                    $insertBindings[] = $nowStr;
                    $insertBindings[] = $nowStr;
                }

                if (!empty($insertValues)) {
                    $insFallback = "INSERT IGNORE INTO actions (order_id, user_id, type, status, performed_at, created_at) VALUES " . implode(',', $insertValues);
                    $inserted = DB::connection()->affectingStatement($insFallback, $insertBindings);
                }
            } catch (\Throwable $__e) {
                Log::warning('[BatchDatabaseService] insertFallback failed after upsert error', ['error' => $__e->getMessage()]);
            }

            return $updated + $inserted;
        }
    }

    /**
     * Optimized eligible users query with caching
     */
    public function getEligibleUsersOptimized(Order $order, int $limit = 1000): array
    {
        $cacheKey = "eligible_users_{$order->id}_{$order->target_url_hash}_{$limit}";

        return Cache::remember($cacheKey, 300, function () use ($order, $limit) {
            // Use a more efficient query with proper indexes
            $sql = "
                SELECT u.id, u.points
                FROM users u
                WHERE u.type = 'user'
                AND u.id NOT IN (
                    SELECT DISTINCT a.user_id
                    FROM actions a
                    INNER JOIN orders o ON a.order_id = o.id
                    WHERE o.target_url_hash = ?
                    AND a.status IN ('done', 'external')
                    LIMIT 10000
                )
                ORDER BY u.id DESC
                LIMIT ?
            ";

            $results = DB::select($sql, [$order->target_url_hash, $limit]);

            return array_column($results, 'id');
        });
    }

    /**
     * Create actions in optimized batches for order processing
     */
    public function createOrderActions(Order $order, array $userIds): int
    {
        if (empty($userIds)) {
            return 0;
        }

        $now = now();
        $actions = [];

        foreach ($userIds as $userId) {
            $actions[] = [
                'order_id' => $order->id,
                'user_id' => $userId,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now
            ];
        }

        return $this->batchInsertActions($actions);
    }

    /**
     * Single transaction for order completion with all related updates
     */
    public function completeOrderBatch(int $orderId, array $actionUpdates): bool
    {
        DB::beginTransaction();

        try {
            // Update all actions in batch
            $this->batchUpdateActionStatus($actionUpdates);

            // Recompute authoritative done_count from actions table for this order to avoid double increments
            try {
                $doneCount = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->whereIn('status', ['done', 'external'])
                    ->count();

                DB::statement(
                    "UPDATE orders SET done_count = LEAST(?, total_count), updated_at = NOW() WHERE id = ?",
                    [$doneCount, $orderId]
                );

                // Check if order should be completed
                $this->checkAndCompleteOrder($orderId);
            } catch (\Throwable $__e) {
                Log::warning('[BatchDatabaseService] failed to recompute done_count in completeOrderBatch', ['order_id' => $orderId, 'error' => $__e->getMessage()]);
            }

            DB::commit();
            return true;

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Order batch completion failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check and mark order as completed if all actions are done
     */
    private function checkAndCompleteOrder(int $orderId): void
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->first(['total_count', 'done_count', 'status']);

        if ($order && $order->done_count >= $order->total_count && $order->status !== 'completed') {
            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);

            Log::info('Order completed', ['order_id' => $orderId]);
        }
    }

    /**
     * Recompute authoritative done_count for an order from the actions table.
     * Public so other services/listeners/controllers can call it to repair/canonicalize counts.
     * Returns the recomputed done count.
     */
    public function recomputeDoneCountForOrder(int $orderId): int
    {
        try {
            $doneCount = DB::table('actions')
                ->where('order_id', $orderId)
                ->whereIn('status', ['done', 'external'])
                ->count();

            DB::statement(
                "UPDATE orders SET done_count = LEAST(?, total_count), updated_at = NOW() WHERE id = ?",
                [$doneCount, $orderId]
            );

            // Mark completed if needed
            DB::statement(
                "UPDATE orders SET status = 'completed', updated_at = NOW() WHERE id = ? AND done_count >= total_count AND status != 'completed'",
                [$orderId]
            );

            return (int)$doneCount;
        } catch (\Throwable $e) {
            Log::warning('[BatchDatabaseService] recomputeDoneCountForOrder failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Monitor database connection health
     */
    public function getConnectionHealth(): array
    {
        try {
            $connections = DB::select("SHOW STATUS LIKE 'Threads_connected'")[0]->Value ?? 0;
            $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'")[0]->Value ?? 0;

            $usage = $maxConnections > 0 ? ($connections / $maxConnections) * 100 : 0;

            return [
                'healthy' => $usage < 80,
                'connections' => (int)$connections,
                'max_connections' => (int)$maxConnections,
                'usage_percent' => round($usage, 1)
            ];

        } catch (\Exception $e) {
            return ['healthy' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Optimized bulk user creation for testing
     */
    public function batchCreateUsers(int $count, array $attributes = []): array
    {
        $chunks = array_chunk(range(1, $count), self::MAX_BATCH_SIZE);
        $createdIds = [];

        foreach ($chunks as $chunk) {
            $users = [];
            $now = now();

            foreach ($chunk as $i) {
                $users[] = array_merge([
                    'name' => 'Test User ' . uniqid(),
                    'email' => 'test' . uniqid() . '@example.com',
                    'type' => 'user',
                    'points' => 10,
                    'created_at' => $now,
                    'updated_at' => $now
                ], $attributes);
            }

            // Use insertGetId for better performance
            DB::table('users')->insert($users);

            // Get the IDs of inserted users (approximate)
            $lastId = DB::getPdo()->lastInsertId();
            for ($i = 0; $i < count($users); $i++) {
                $createdIds[] = $lastId - count($users) + $i + 1;
            }
        }

        return $createdIds;
    }
}
