<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\Order;
use App\Models\User;

class BatchDatabaseService
{
    private const MAX_BATCH_SIZE = 500;
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
            foreach ($updateGroups as $group) {
                $updated = $this->batchUpdateSameStatus(
                    $group['status'],
                    $group['conditions']
                );
                $totalUpdated += $updated;
            }

            // Apply order done_count increments within the same transaction to keep counts consistent
            if (!empty($orderIncrements)) {
                foreach ($orderIncrements as $orderId => $inc) {
                    DB::statement(
                        "UPDATE orders SET done_count = LEAST(done_count + ?, total_count), updated_at = NOW() WHERE id = ? AND done_count < total_count",
                        [$inc, $orderId]
                    );

                    // If order has reached completion, mark it completed (idempotent)
                    DB::statement(
                        "UPDATE orders SET status = 'completed', updated_at = NOW() WHERE id = ? AND done_count >= total_count AND status != 'completed'",
                        [$orderId]
                    );
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

        // Build WHERE clause with OR conditions
        $whereConditions = [];

        // Bindings must follow the order of placeholders in the SQL above.
        // SQL placeholders order: status, updated_at, performed_at, then all condition values.
        $bindings = [$status, now(), /* performed_at placeholder will be bound below */];

        foreach ($conditions as $condition) {
            $whereConditions[] = '(order_id = ? AND user_id = ?)';
            $bindings[] = $condition['order_id'];
            $bindings[] = $condition['user_id'];
        }

        // performed_at should be the same as the timestamp we already bound for updated_at
        // so insert it at position 3 (index 2)
        $performedAt = now();
        array_splice($bindings, 2, 0, [$performedAt]);

        $sql = "UPDATE actions SET
                    status = ?,
                    updated_at = ?,
                    performed_at = CASE WHEN status = 'pending' THEN ? ELSE performed_at END
                WHERE status = 'pending' AND (" . implode(' OR ', $whereConditions) . ")";

        return DB::connection()->affectingStatement($sql, $bindings);
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

            // Update order done_count in single query
            $doneCount = count(array_filter($actionUpdates, function($update) {
                return $update['status'] === 'done';
            }));

            if ($doneCount > 0) {
                // Use atomic update to add the batch increment while capping at total_count
                DB::statement(
                    "UPDATE orders SET done_count = LEAST(done_count + ?, total_count), updated_at = NOW() WHERE id = ? AND done_count < total_count",
                    [$doneCount, $orderId]
                );

                // Check if order should be completed
                $this->checkAndCompleteOrder($orderId);
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
