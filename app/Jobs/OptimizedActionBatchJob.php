<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\BatchDatabaseService;
use App\Services\DatabaseConnectionManager;

class OptimizedActionBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 2;
    public $backoff = [5, 10];

    private $batchSize = 1000;
    private $maxBatches = 50;

    public function __construct(
        private array $actionUpdates = [],
        private bool $useConnectionPool = true
    ) {
        $this->onQueue('optimized-actions');
    }

    public function handle()
    {
        $startTime = microtime(true);
        $totalProcessed = 0;

        Log::info('OptimizedActionBatchJob started', [
            'updates_count' => count($this->actionUpdates),
            'use_connection_pool' => $this->useConnectionPool
        ]);

        try {
            $batchService = app(BatchDatabaseService::class);

            // Check database health before processing
            $health = $batchService->getConnectionHealth();
            if (!$health['healthy']) {
                Log::warning('Database unhealthy, reducing batch size', $health);
                $this->batchSize = 500; // Reduce load
            }

            if (!empty($this->actionUpdates)) {
                $totalProcessed = $this->processBatchUpdates($batchService);
            } else {
                $totalProcessed = $this->processQueuedActions($batchService);
            }

            $duration = microtime(true) - $startTime;

            Log::info('OptimizedActionBatchJob completed', [
                'processed' => $totalProcessed,
                'duration' => round($duration, 2),
                'rate_per_second' => round($totalProcessed / max($duration, 0.1), 2)
            ]);

        } catch (\Exception $e) {
            Log::error('OptimizedActionBatchJob failed', [
                'error' => $e->getMessage(),
                'updates_count' => count($this->actionUpdates)
            ]);
            throw $e;
        }
    }

    /**
     * Process pre-defined batch updates
     */
    private function processBatchUpdates(BatchDatabaseService $batchService): int
    {
        if ($this->useConnectionPool) {
            return DatabaseConnectionManager::executeBatch(function($connection) use ($batchService) {
                return $batchService->batchUpdateActionStatus($this->actionUpdates);
            });
        } else {
            return $batchService->batchUpdateActionStatus($this->actionUpdates);
        }
    }

    /**
     * Process queued actions from Redis/cache
     */
    private function processQueuedActions(BatchDatabaseService $batchService): int
    {
        $totalProcessed = 0;

        for ($batch = 0; $batch < $this->maxBatches; $batch++) {
            $actions = $this->getQueuedActions($this->batchSize);

            if (empty($actions)) {
                Log::info('No more queued actions to process');
                break;
            }

            if ($this->useConnectionPool) {
                $processed = DatabaseConnectionManager::executeBatch(function($connection) use ($batchService, $actions) {
                    return $this->processBatchActions($batchService, $actions);
                });
            } else {
                $processed = $this->processBatchActions($batchService, $actions);
            }

            $totalProcessed += $processed;

            Log::info("Batch {$batch} processed", [
                'actions' => count($actions),
                'processed' => $processed,
                'total' => $totalProcessed
            ]);

            // Brief pause between batches to prevent overload
            if ($batch < $this->maxBatches - 1 && count($actions) === $this->batchSize) {
                usleep(50000); // 50ms pause
            }
        }

        return $totalProcessed;
    }

    /**
     * Process a batch of actions efficiently
     */
    private function processBatchActions(BatchDatabaseService $batchService, array $actions): int
    {
        $processed = 0;
        $updates = [];
        $orderIncrements = [];

        foreach ($actions as $action) {
            try {
                $updates[] = [
                    'order_id' => $action['order_id'],
                    'user_id' => $action['user_id'],
                    'status' => $action['status']
                ];

                // Track order increments for batch processing
                if ($action['status'] === 'done') {
                    $orderId = $action['order_id'];
                    $orderIncrements[$orderId] = ($orderIncrements[$orderId] ?? 0) + 1;
                }

                $processed++;

            } catch (\Exception $e) {
                Log::warning('Failed to process single action', [
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Batch update all actions
        if (!empty($updates)) {
            $batchService->batchUpdateActionStatus($updates);
        }

        // Batch update order done_counts
        if (!empty($orderIncrements)) {
            $this->batchUpdateOrderCounts($orderIncrements);
        }

        return $processed;
    }

    /**
     * Update multiple order done_counts in batches
     */
    private function batchUpdateOrderCounts(array $orderIncrements): void
    {
        foreach ($orderIncrements as $orderId => $increment) {
            DB::statement(
                "UPDATE orders SET done_count = LEAST(done_count + ?, total_count), updated_at = NOW() WHERE id = ? AND done_count < total_count",
                [$increment, $orderId]
            );
        }

        Log::info('Order counts updated', [
            'orders' => count($orderIncrements),
            'total_increments' => array_sum($orderIncrements)
        ]);
    }

    /**
     * Get queued actions from Redis or other queue source
     */
    private function getQueuedActions(int $limit): array
    {
        // Implementation would depend on your queue storage
        // This is a placeholder for Redis/cache-based queue
        try {
            $redis = app('redis');
            $actions = [];

            for ($i = 0; $i < $limit; $i++) {
                $item = $redis->lpop('mqtt_actions_queue');
                if ($item === null) break;

                $decoded = json_decode($item, true);
                if ($decoded !== null) {
                    $actions[] = $decoded;
                }
            }

            return $actions;

        } catch (\Exception $e) {
            Log::warning('Failed to get queued actions', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Re-queue actions on failure
     */
    private function requeueActions(array $actions): void
    {
        try {
            $redis = app('redis');

            foreach ($actions as $action) {
                $redis->rpush('mqtt_actions_queue', json_encode($action));
            }

            Log::info('Actions re-queued', ['count' => count($actions)]);

        } catch (\Exception $e) {
            Log::error('Failed to re-queue actions', [
                'count' => count($actions),
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('OptimizedActionBatchJob failed permanently', [
            'updates_count' => count($this->actionUpdates),
            'exception' => $exception->getMessage()
        ]);

        // Re-queue actions if possible
        if (!empty($this->actionUpdates)) {
            $this->requeueActions($this->actionUpdates);
        }
    }

    /**
     * Static method to dispatch batch job with actions
     */
    public static function dispatchBatch(array $actionUpdates): void
    {
        self::dispatch($actionUpdates, true)->onQueue('optimized-actions');
    }
}
