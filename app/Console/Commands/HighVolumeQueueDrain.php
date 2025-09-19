<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Services\BatchDatabaseService;
use App\Models\Order;

class HighVolumeQueueDrain extends Command
{
    protected $signature = 'high-volume:drain {--limit=1000} {--once=false}';
    protected $description = 'Drain the high-volume actions Redis queue and apply batch updates/inserts safely.';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $once = filter_var($this->option('once'), FILTER_VALIDATE_BOOLEAN);

        $queueKey = env('HIGH_VOLUME_QUEUE_KEY', 'high_volume_actions_queue');
        $maxPerMinute = (int) env('BATCH_ACTION_MAX_PER_MIN', 5000);
        $chunkSize = (int) env('BATCH_ACTION_CHUNK_SIZE', 200);

        $batchService = app(BatchDatabaseService::class);

        $this->info('Starting drain of ' . $queueKey . ' (limit=' . $limit . ')');

        $processed = 0;

        do {
            $items = [];
            // Pop up to $limit items
            for ($i = 0; $i < $limit; $i++) {
                $raw = Redis::lpop($queueKey);
                if (!$raw) break;
                $decoded = json_decode($raw, true);
                if ($decoded) $items[] = $decoded;
            }

            if (empty($items)) {
                if ($once) {
                    $this->info('Queue empty. Exiting.');
                    break;
                }

                // When running as a daemon (not --once), don't exit on empty queue.
                // Sleep briefly to avoid a tight restart loop under Supervisor.
                usleep(200000); // 200ms
                continue;
            }

            // Separate creates (pending) and status updates
            $creates = []; // order_id => [userIds]
            $updates = []; // list of ['order_id','user_id','status']

            foreach ($items as $it) {
                if (!isset($it['order_id']) || !isset($it['user_id']) || !isset($it['status'])) {
                    continue;
                }
                if ($it['status'] === 'pending') {
                    $creates[$it['order_id']][] = $it['user_id'];
                } else {
                    $updates[] = ['order_id' => $it['order_id'], 'user_id' => $it['user_id'], 'status' => $it['status']];
                }
            }

            // Process creates per order with rate limiting
            foreach ($creates as $orderId => $userIds) {
                // Chunk userIds to avoid huge inserts
                $chunks = array_chunk($userIds, $chunkSize);
                foreach ($chunks as $chunk) {
                    $nowMinute = gmdate('YmdHi');
                    $globalKey = 'batch_action_rate_global:' . $nowMinute;
                    try {
                        $current = Redis::incrby($globalKey, count($chunk));
                        Redis::expire($globalKey, 70);
                    } catch (\Throwable $e) {
                        Log::warning('[HighVolumeQueueDrain] Redis unavailable for rate limiting, proceeding', ['error' => $e->getMessage(), 'order_id' => $orderId]);
                        $current = count($chunk);
                    }

                    if ($current > $maxPerMinute) {
                        // Requeue this chunk back to the head so it will be retried later
                        try {
                            Redis::lpush($queueKey, json_encode(['order_id' => $orderId, 'user_id' => $chunk[0] ?? null, 'status' => 'pending', 'note' => 'requeued_chunk']));
                            // For multiple user_ids we push a combined payload so workers can detect and handle
                            Redis::rpush(env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $orderId), json_encode(['order_id' => $orderId, 'user_ids' => $chunk]));
                        } catch (\Throwable $e) {
                            Log::warning('[HighVolumeQueueDrain] Failed to requeue chunk due to rate limit', ['error' => $e->getMessage(), 'order_id' => $orderId]);
                        }
                        continue;
                    }

                    // Use BatchDatabaseService to create actions for the order
                    try {
                        $order = Order::find($orderId);
                        if (!$order) {
                            Log::warning('[HighVolumeQueueDrain] Order not found, skipping', ['order_id' => $orderId]);
                            continue;
                        }

                        $inserted = $batchService->createOrderActions($order, $chunk);
                        $processed += $inserted;
                        $this->info('Inserted ' . $inserted . " actions for order {$orderId}");
                    } catch (\Throwable $e) {
                        Log::error('[HighVolumeQueueDrain] Failed to insert actions', ['error' => $e->getMessage(), 'order_id' => $orderId]);
                        // Requeue the chunk for later retry
                        try { Redis::rpush(env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:' . $orderId), json_encode(['order_id' => $orderId, 'user_ids' => $chunk, 'error' => $e->getMessage()])); } catch (\Throwable $__e) {}
                    }
                }
            }

            // Process updates in batches
            if (!empty($updates)) {
                // Don't access class-private constants directly; use env fallback
                $maxBatch = (int) env('BATCH_DATABASE_MAX_BATCH', 500);
                $batches = array_chunk($updates, $maxBatch);
                foreach ($batches as $batch) {
                    try {
                        $updated = $batchService->batchUpdateActionStatus($batch);
                        $processed += $updated;
                        $this->info('Updated ' . $updated . " actions in batch");
                    } catch (\Throwable $e) {
                        Log::error('[HighVolumeQueueDrain] Failed to batch update actions', ['error' => $e->getMessage()]);
                        // Requeue individual updates on failure
                        foreach ($batch as $upd) {
                            try { Redis::rpush($queueKey, json_encode($upd)); } catch (\Throwable $__e) {}
                        }
                    }
                }
            }

            // Additionally, drain per-order batch action queues (e.g. batch_action_queue:{orderId})
            try {
                $requeueEnv = env('BATCH_ACTION_REQUEUE_KEY', 'batch_action_queue:{orderId}');
                if (strpos($requeueEnv, '{orderId}') !== false) {
                    $pattern = str_replace('{orderId}', '*', $requeueEnv);
                } else {
                    // treat as prefix
                    $pattern = rtrim($requeueEnv, ':') . ':*';
                }

                $keys = Redis::keys($pattern);
                if (!empty($keys)) {
                    foreach ($keys as $key) {
                        // Pop up to chunkSize items from this per-order queue
                        for ($pi = 0; $pi < $limit; $pi++) {
                            $raw = Redis::lpop($key);
                            if (!$raw) break;
                            $payload = json_decode($raw, true);
                            if (!$payload) continue;

                            // Detect payload shape: chunk insert (user_ids) vs single update
                            if (!empty($payload['user_ids']) && !empty($payload['order_id'])) {
                                $orderId = intval($payload['order_id']);
                                $userIds = is_array($payload['user_ids']) ? $payload['user_ids'] : [$payload['user_ids']];
                                $chunks = array_chunk($userIds, $chunkSize);
                                foreach ($chunks as $chunk) {
                                    $nowMinute = gmdate('YmdHi');
                                    $globalKey = 'batch_action_rate_global:' . $nowMinute;
                                    try {
                                        $current = Redis::incrby($globalKey, count($chunk));
                                        Redis::expire($globalKey, 70);
                                    } catch (\Throwable $e) {
                                        Log::warning('[HighVolumeQueueDrain] Redis unavailable for rate limiting (per-order), proceeding', ['error' => $e->getMessage(), 'order_key' => $key]);
                                        $current = count($chunk);
                                    }

                                    if ($current > $maxPerMinute) {
                                        // Requeue this chunk back to the same per-order queue
                                        try {
                                            Redis::rpush($key, json_encode(['order_id' => $orderId, 'user_ids' => $chunk]));
                                        } catch (\Throwable $__e) {
                                            Log::warning('[HighVolumeQueueDrain] Failed to requeue per-order chunk due to rate limit', ['error' => $__e->getMessage(), 'order_key' => $key]);
                                        }
                                        continue;
                                    }

                                    try {
                                        $order = Order::find($orderId);
                                        if (!$order) {
                                            Log::warning('[HighVolumeQueueDrain] Per-order queue item: Order not found, skipping', ['order_id' => $orderId, 'order_key' => $key]);
                                            continue;
                                        }

                                        $inserted = $batchService->createOrderActions($order, $chunk);
                                        $processed += $inserted;
                                        $this->info('Inserted ' . $inserted . " actions for order {$orderId} from requeue key {$key}");
                                    } catch (\Throwable $e) {
                                        Log::error('[HighVolumeQueueDrain] Failed to insert actions from per-order queue', ['error' => $e->getMessage(), 'order_id' => $orderId, 'order_key' => $key]);
                                        // Requeue the chunk for later retry
                                        try { Redis::rpush($key, json_encode(['order_id' => $orderId, 'user_ids' => $chunk, 'error' => $e->getMessage()])); } catch (\Throwable $__e) {}
                                    }
                                }
                            } else {
                                // Treat as single update: expects order_id, user_id, status
                                if (!empty($payload['order_id']) && !empty($payload['user_id']) && !empty($payload['status'])) {
                                    $updates[] = ['order_id' => intval($payload['order_id']), 'user_id' => intval($payload['user_id']), 'status' => $payload['status']];
                                } else {
                                    Log::warning('[HighVolumeQueueDrain] Unknown payload in per-order queue, skipping', ['payload' => $payload, 'order_key' => $key]);
                                }
                            }
                        }
                    }
                    // If updates were accumulated from per-order queues, process them in batches now
                    if (!empty($updates)) {
                        // Use environment override or sensible default to control batch update size
                        $maxBatch = (int) env('BATCH_DATABASE_MAX_BATCH', 500);
                        $batches = array_chunk($updates, $maxBatch);
                        foreach ($batches as $batch) {
                            try {
                                $updated = $batchService->batchUpdateActionStatus($batch);
                                $processed += $updated;
                                $this->info('Updated ' . $updated . " actions in batch (from per-order queues)");
                            } catch (\Throwable $e) {
                                Log::error('[HighVolumeQueueDrain] Failed to batch update actions (from per-order queues)', ['error' => $e->getMessage()]);
                                foreach ($batch as $upd) { try { Redis::rpush($queueKey, json_encode($upd)); } catch (\Throwable $__e) {} }
                            }
                        }
                        // clear updates to avoid double-processing on next loop
                        $updates = [];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[HighVolumeQueueDrain] Error while scanning per-order requeue keys', ['error' => $e->getMessage()]);
            }

            // If once flag is set, exit after one pass
            if ($once) break;

            // If we've processed fewer than limit, sleep a small amount to avoid tight loop
            if ($processed < $limit) usleep(100000); // 100ms

        } while (true);

        $this->info('Drain completed. Total processed: ' . $processed);
        return 0;
    }
}
