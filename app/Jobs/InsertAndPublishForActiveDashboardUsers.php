<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\ResumeOrderService;
use App\Services\BatchActionService;

class InsertAndPublishForActiveDashboardUsers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes

    public function __construct()
    {
        $this->onQueue(env('COORDINATOR_QUEUE', 'publish-coordinator'));
    }

    public function handle()
    {
        Log::info('[InsertAndPublishForActiveDashboardUsers] started');

        $redis = app('redis')->connection();

        // --- Run-lock and spacing controls -------------------------------------------------
        // Use Redis to ensure only one coordinator runs at a time and to enforce a
        // minimum interval between completed runs. This prevents overlapping runs
        // when a single run can take many hours or days.
        $lockKey = env('COORDINATOR_LOCK_KEY', 'orders_resume_lock');
        $lastRunKey = env('COORDINATOR_LAST_RUN_KEY', 'orders_resume_last_run');
        $minIntervalSecs = (int) env('COORDINATOR_MIN_INTERVAL_SECONDS', 3600); // default 1 hour
        // TTL for the lock (seconds). Should be larger than the expected max runtime.
        $lockTtl = (int) env('COORDINATOR_LOCK_TTL_SECONDS', 60 * 60 * 24 * 3); // default 3 days

        try {
            $lastRun = (int) $redis->get($lastRunKey);
        } catch (\Throwable $e) {
            $lastRun = 0;
        }

        if ($lastRun > 0 && (time() - $lastRun) < $minIntervalSecs) {
            Log::info('[InsertAndPublishForActiveDashboardUsers] skipping because min-interval not elapsed', ['last_run' => date('c', $lastRun), 'min_interval_secs' => $minIntervalSecs]);
            return;
        }

        // Try to acquire a Redis lock using SET NX EX
        try {
            $acquired = $redis->set($lockKey, time(), 'NX', 'EX', $lockTtl);
        } catch (\Throwable $e) {
            Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to check/acquire lock', ['error' => $e->getMessage()]);
            $acquired = false;
        }

        if (! $acquired) {
            // Someone else is running (or lock couldn't be acquired). Bail out.
            try {
                $ttl = $redis->ttl($lockKey);
            } catch (\Throwable $e) {
                $ttl = null;
            }
            Log::info('[InsertAndPublishForActiveDashboardUsers] another run is in progress or lock held - exiting', ['lock_ttl' => $ttl]);
            return;
        }

    // We'll ensure the lock is removed and last_run is set in the finally block below.
    // During the run we periodically refresh the lock TTL to avoid accidental expiry.
    $refreshLockTtl = $lockTtl;

    // Wrap the main work in a try/finally so the lock and last_run are
    // consistently updated even if we return early or exceptions occur.
    try {
        // ----------------------------------------------------------------------------------
        $queueKey = env('PUBLISH_QUEUE_KEY', env('MQTT_QUEUE_KEY', 'mqtt:publish'));

        $waitSeconds = (int) env('ACTIVATION_WAIT_SECONDS', env('WAIT_SECONDS_FOR_RESPONSES', 5));
        // ORDERS_PER_RUN: set to 0 to mean "no configured limit". We still enforce
        // TOTAL_PUBLISH_LIMIT and optionally HARD_MAX_ORDERS_SCAN to avoid accidental
        // full-table scans in production.
        $ordersPerRun = (int) env('ORDERS_PER_RUN', 0);
        $hardMaxOrdersScan = (int) env('HARD_MAX_ORDERS_SCAN', 0); // 0 = disabled
        $usersPerOrderLimit = (int) env('USERS_PER_ORDER_LIMIT', 50);
        $chunkSize = (int) env('PING_BATCH_PROCESS_CHUNK_SIZE', 80);
        $chunkDelayMs = (int) env('PING_BATCH_CHUNK_DELAY_MS', 50);

        $softLimit = (int) env('PUBLISH_QUEUE_SOFT_LIMIT', 50000);
        $highWatermark = (int) env('PUBLISH_QUEUE_HIGH_WATERMARK', 100000);
        $partialMax = (int) env('PUBLISH_PARTIAL_BATCH_MAX', 500);

        // Use the same Redis key the Dashboard uses; make it configurable
        $activeKey = env('DEVICE_ACTIVATIONS_KEY', 'device_activations_set');
        try {
            $activeUsers = $redis->smembers($activeKey) ?: [];
            $activeUsers = array_values(array_filter(array_map('intval', $activeUsers)));
        } catch (\Throwable $e) {
            Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to read active:dashboard', ['error' => $e->getMessage()]);
            $activeUsers = [];
        }

        Log::info('[InsertAndPublishForActiveDashboardUsers] active users count before ping', ['count' => count($activeUsers)]);

        if (empty($activeUsers)) {
                // create a batch id so downstream workers/metrics can correlate these publishes
                $coordBatchId = 'coord_' . time() . '_' . random_int(1000, 9999);
                try {
                    Log::info('[InsertAndPublishForActiveDashboardUsers] no active users found - enqueuing activation ping to devices/activation/req', ['batch_id' => $coordBatchId]);

                    $pingPayload = ['request' => 'ping'];
                    $job = json_encode([
                        'topic' => 'devices/activation/req',
                        'payload' => $pingPayload,
                        'qos' => 1,
                        'retain' => false,
                        'meta' => ['enqueued_at' => time(), 'batch_id' => $coordBatchId, 'coordinator' => true]
                    ]);

                    // push ping job to the same publish queue so the MQTT publisher will send it
                    $redis->rpush($queueKey, $job);
            } catch (\Throwable $e) {
                Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to enqueue activation ping', ['error' => $e->getMessage()]);
            }

            Log::info('[InsertAndPublishForActiveDashboardUsers] waiting for ping responses', ['wait_seconds' => $waitSeconds]);
            sleep(max(1, $waitSeconds));

            try {
                $activeUsers = $redis->smembers($activeKey) ?: [];
                $activeUsers = array_values(array_filter(array_map('intval', $activeUsers)));
            } catch (\Throwable $e) {
                Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to re-read device_activations_set', ['error' => $e->getMessage()]);
                $activeUsers = [];
            }
            Log::info('[InsertAndPublishForActiveDashboardUsers] active users count after ping', ['count' => count($activeUsers)]);
        }

        if (empty($activeUsers)) {
            Log::info('[InsertAndPublishForActiveDashboardUsers] no active users available - exiting');
            return;
        }

        $ordersQuery = Order::where('status', 'active')
            ->whereRaw('done_count < total_count');

        // Determine an effective per-run limit to bound work and ensure runs finish.
        // Priority:
        // 1) If ORDERS_PER_RUN > 0, use it.
        // 2) Else if HARD_MAX_ORDERS_SCAN > 0, use it.
        // 3) Else fall back to a safe default (configurable) to avoid unbounded runs.
        $defaultPerRun = (int) env('COORDINATOR_DEFAULT_ORDERS_PER_RUN', 500);
        if ($ordersPerRun > 0) {
            $effectiveOrdersLimit = $ordersPerRun;
        } elseif ($hardMaxOrdersScan > 0) {
            $effectiveOrdersLimit = $hardMaxOrdersScan;
        } else {
            $effectiveOrdersLimit = max(1, $defaultPerRun);
        }

        // Apply the effective limit to the query to guarantee a bounded run.
        $ordersQuery = $ordersQuery->limit($effectiveOrdersLimit);

        $orders = $ordersQuery->get();

        Log::info('[InsertAndPublishForActiveDashboardUsers] orders selected', ['count' => $orders->count()]);

        // Global publish collection to enforce total and per-user caps
        $publishList = []; // each item: ['user_id' => int, 'order_id' => int, 'payload' => array]
        $userCounts = []; // user_id => number of orders queued for this user
        $totalPublishes = 0;
        $maxTotal = (int) env('TOTAL_PUBLISH_LIMIT', 1000);
        $perUserLimit = (int) env('PER_USER_ORDER_LIMIT', 40);
        $maxBatches = max(1, (int) env('MAX_PUBLISH_BATCHES', 2));

        // Run-level instrumentation
        $runStart = now();
        $ordersSummary = []; // order_id => ['pending_found'=>int,'pending_added'=>int,'claim_attempted'=>int,'inserted'=>int,'claimed_added'=>int]
        $totalInserted = 0; // sum of inserted actions from BatchActionService
        $publishedEnqueued = 0; // actual number of publish jobs pushed to Redis

        foreach ($orders as $order) {
            try {
                Log::info('[InsertAndPublishForActiveDashboardUsers] processing order', ['order_id' => $order->id, 'total_count' => $order->total_count]);

                $doneCount = DB::table('actions')
                    ->where('order_id', $order->id)
                    ->where('status', 'done')
                    ->count();

                $available = max(0, $order->total_count - $doneCount);
                Log::info('[InsertAndPublishForActiveDashboardUsers] order capacity', ['order_id' => $order->id, 'done' => $doneCount, 'available' => $available]);

                if ($available <= 0) {
                    Log::info('[InsertAndPublishForActiveDashboardUsers] no available slots for order', ['order_id' => $order->id]);
                    continue;
                }

                $candidates = array_slice($activeUsers, 0, $usersPerOrderLimit);
                if (empty($candidates)) {
                    Log::info('[InsertAndPublishForActiveDashboardUsers] no candidate users for order', ['order_id' => $order->id]);
                    continue;
                }

                // Use ResumeOrderService to compute eligible users similarly to resume flow
                try {
                    $resumeService = app(ResumeOrderService::class);
                    $eligibleUsersCollection = $resumeService->getEligibleUsers($order);
                    $eligibleIdsAll = $eligibleUsersCollection->pluck('id')->toArray();
                } catch (\Throwable $e) {
                    Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to compute eligible users via ResumeOrderService', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    // Fallback: use candidates directly
                    $eligibleIdsAll = $candidates;
                }

                // Intersect with active candidates to only consider users currently active
                $eligible = array_values(array_intersect($eligibleIdsAll, $candidates));

                if (empty($eligible)) {
                    Log::info('[InsertAndPublishForActiveDashboardUsers] no eligible users after filtering with active set', ['order_id' => $order->id]);
                    continue;
                }

                // Pending users among the eligible+active set
                $pendingUsers = DB::table('actions')
                    ->where('order_id', $order->id)
                    ->where('status', 'pending')
                    ->whereIn('user_id', $eligible)
                    ->pluck('user_id')
                    ->toArray();

                Log::info('[InsertAndPublishForActiveDashboardUsers] pending users found', ['order_id' => $order->id, 'count' => count($pendingUsers)]);
                $ordersSummary[$order->id] = [
                    'pending_found' => count($pendingUsers),
                    'pending_added' => 0,
                    'claim_attempted' => 0,
                    'inserted' => 0,
                    'claimed_added' => 0
                ];

                if (!empty($pendingUsers) && $totalPublishes < $maxTotal) {
                    $payloadBase = [
                        'url' => $order->target_url,
                        'order_id' => $order->id,
                        'type' => $order->type,
                        'mediaId' => $order->mediaId ?? null,
                        'userPk' => $order->userPk ?? null,
                    ];

                    $orderAssigned = 0;
                    foreach ($pendingUsers as $uid) {
                        if ($orderAssigned >= $available) break; // respect order capacity
                        if ($totalPublishes >= $maxTotal) break 2; // reached global cap
                        $uid = (int) $uid;
                        $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                        if ($uc >= $perUserLimit) continue; // per-user cap reached

                        // add to publish list
                        $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $payloadBase];
                        $userCounts[$uid] = $uc + 1;
                        $orderAssigned++;
                        $totalPublishes++;
                        $ordersSummary[$order->id]['pending_added']++;
                    }
                }

                $claimNew = filter_var(env('RESUME_CLAIM_NEW', false), FILTER_VALIDATE_BOOLEAN);
                if ($claimNew && $available > 0) {
                    // Determine which eligible users don't already have actions
                    $alreadyActioned = DB::table('actions')
                        ->where('order_id', $order->id)
                        ->whereIn('user_id', $eligible)
                        ->pluck('user_id')
                        ->toArray();

                    $toClaim = array_values(array_diff($eligible, $alreadyActioned));
                    if (!empty($toClaim)) {
                        // Respect per-order available slots and global/per-user caps
                        $toClaimFiltered = [];
                        foreach ($toClaim as $uid) {
                            if ($available <= 0) break;
                            if ($totalPublishes >= $maxTotal) break;
                            $uid = (int) $uid;
                            $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                            if ($uc >= $perUserLimit) continue;
                            $toClaimFiltered[] = $uid;
                            // only increment global total now; userCounts will be incremented
                            // when the user is actually added to the publish list
                            $totalPublishes++;
                            $available--;
                        }

                        if (!empty($toClaimFiltered)) {
                            $ordersSummary[$order->id]['claim_attempted'] = count($toClaimFiltered);
                            try {
                                $batchService = app(BatchActionService::class);
                                $result = $batchService->batchInsertPendingAction($order, $toClaimFiltered);
                                Log::info('[InsertAndPublishForActiveDashboardUsers] BatchActionService result', ['order_id' => $order->id, 'result' => $result]);
                                if (is_array($result) && isset($result['inserted'])) {
                                    $ordersSummary[$order->id]['inserted'] = intval($result['inserted']);
                                    $totalInserted += intval($result['inserted']);
                                }
                            } catch (\Throwable $e) {
                                Log::warning('[InsertAndPublishForActiveDashboardUsers] BatchActionService failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                            }

                            // Determine which of the attempted users now have actions (pending/done/external)
                            $existingUserIds = DB::table('actions')
                                ->where('order_id', $order->id)
                                ->whereIn('user_id', $toClaimFiltered)
                                ->whereIn('status', ['pending', 'done', 'external'])
                                ->pluck('user_id')
                                ->toArray();

                            // Add claimed ones to the global publish list (respecting caps)
                            $payloadBase = [
                                'url' => $order->target_url,
                                'order_id' => $order->id,
                                'type' => $order->type,
                                'mediaId' => $order->mediaId ?? null,
                                'userPk' => $order->userPk ?? null,
                            ];

                            $orderAssigned = isset($orderAssigned) ? $orderAssigned : 0;
                            foreach ($existingUserIds as $uid) {
                                if ($orderAssigned >= $available) break;
                                if ($totalPublishes >= $maxTotal) break;
                                $uid = (int) $uid;
                                $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                                if ($uc >= $perUserLimit) continue;

                                $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $payloadBase];
                                $userCounts[$uid] = $uc + 1;
                                $orderAssigned++;
                                $ordersSummary[$order->id]['claimed_added']++;
                            }

                            $doneCount = DB::table('actions')
                                ->where('order_id', $order->id)
                                ->where('status', 'done')
                                ->count();
                            $available = max(0, $order->total_count - $doneCount);
                        }
                    }
                }

            } catch (\Throwable $e) {
                Log::error('[InsertAndPublishForActiveDashboardUsers] error processing order', ['order_id' => $order->id ?? null, 'error' => $e->getMessage()]);
            }

            // keep the lock alive while we're processing long runs
            try {
                $redis->expire($lockKey, $refreshLockTtl);
            } catch (\Throwable $e) {
                // best-effort; don't break the run if expiry refresh fails
            }
        }

        // After collecting everything across orders, push publishes in up to $maxBatches batches
        Log::info('[InsertAndPublishForActiveDashboardUsers] total publishes collected', ['total' => $totalPublishes]);

        // Enforce global cap strictly on the final publish list. It's possible
        // that due to reservation logic or race conditions the in-memory
        // $publishList length may exceed $maxTotal; truncate to be safe.
        if (count($publishList) > $maxTotal) {
            $publishList = array_slice($publishList, 0, $maxTotal);
            $totalPublishes = count($publishList);
            Log::warning('[InsertAndPublishForActiveDashboardUsers] publishList truncated to TOTAL_PUBLISH_LIMIT', ['limit' => $maxTotal, 'new_total' => $totalPublishes]);
        }

        if ($totalPublishes > 0) {
            // Ensure we have a batch id even if coordBatchId wasn't set earlier
            $coordBatchId = $coordBatchId ?? ('coord_' . time() . '_' . random_int(1000, 9999));
            $batchSize = (int) ceil($totalPublishes / $maxBatches);
            $batches = array_chunk($publishList, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                    try {
                        $queueLen = (int) $redis->llen($queueKey);
                    } catch (\Throwable $e) {
                        $queueLen = 0;
                    }

                    Log::info('[InsertAndPublishForActiveDashboardUsers] preparing to push batch', ['batch' => $batchIndex + 1, 'batch_count' => count($batch), 'queue_len' => $queueLen]);

                if ($queueLen > $highWatermark) {
                    Log::warning('[InsertAndPublishForActiveDashboardUsers] queue is above high watermark before batch push - releasing', ['len' => $queueLen]);
                    $this->release(30);
                    return;
                }

                // chunk inside batch to avoid too-large RPUSH
                $innerChunks = array_chunk($batch, max(1, min($chunkSize, count($batch))));
                foreach ($innerChunks as $inner) {
                    $jobs = [];
                    foreach ($inner as $item) {
                        $topic = "orders/{$item['user_id']}";
                        $jobs[] = json_encode(['topic' => $topic, 'payload' => $item['payload'], 'qos' => 0, 'retain' => false, 'meta' => ['enqueued_at' => time(), 'batch_id' => $coordBatchId, 'coordinator' => true]]);
                    }

                    try {
                        $redis->pipeline(function ($pipe) use ($queueKey, $jobs) {
                            foreach ($jobs as $job) {
                                $pipe->rpush($queueKey, $job);
                            }
                        });
                    } catch (\Throwable $e) {
                        Log::warning('[InsertAndPublishForActiveDashboardUsers] redis pipeline failed during batch push', ['error' => $e->getMessage()]);
                        $this->release(30);
                        return;
                    }

                    $publishedEnqueued += count($jobs);
                    Log::info('[InsertAndPublishForActiveDashboardUsers] pushed inner chunk', ['count' => count($jobs), 'published_total_so_far' => $publishedEnqueued]);

                    if ($chunkDelayMs > 0) {
                        usleep($chunkDelayMs * 1000);
                    }
                }
            }
        }

        $runEnd = now();
        $durationMs = round(($runEnd->getTimestamp() - $runStart->getTimestamp()) * 1000 + ($runEnd->micro - $runStart->micro) / 1000, 2);

        // Prepare compact per-order summary for logs (avoid huge payloads)
        $compactOrders = [];
        foreach ($ordersSummary as $oid => $s) {
            $compactOrders[] = [
                'order_id' => $oid,
                'pending_found' => $s['pending_found'] ?? 0,
                'pending_added' => $s['pending_added'] ?? 0,
                'claim_attempted' => $s['claim_attempted'] ?? 0,
                'inserted' => $s['inserted'] ?? 0,
                'claimed_added' => $s['claimed_added'] ?? 0,
            ];
        }

        Log::info('[InsertAndPublishForActiveDashboardUsers] completed', [
            'run_start' => $runStart->toDateTimeString(),
            'run_end' => $runEnd->toDateTimeString(),
            'duration_ms' => $durationMs,
            'orders_considered' => count($orders),
            'orders_summary' => $compactOrders,
            'total_planned_publishes' => $totalPublishes,
            'total_inserted_actions' => $totalInserted,
            'total_publishes_enqueued' => $publishedEnqueued,
        ]);

    // Emit a compact, easily searchable report line for downstream log parsing
        // Use a distinctive flag so operators can grep the logs quickly.
        try {
            $reportFlag = '[ORDERS_RESUME_REPORT]';

            // Distinct orders represented in the final publish list (post-truncate)
            $distinctOrders = 0;
            if (!empty($publishList)) {
                $distinctOrders = count(array_unique(array_column($publishList, 'order_id')));
            }

            // How many orders had any actions created/added during this run
            $ordersWithActions = 0;
            foreach ($ordersSummary as $s) {
                $added = ($s['pending_added'] ?? 0) + ($s['claimed_added'] ?? 0) + ($s['inserted'] ?? 0);
                if ($added > 0) {
                    $ordersWithActions++;
                }
            }

            Log::info($reportFlag, [
                'batch_id' => $coordBatchId ?? null,
                'run_start' => $runStart->toDateTimeString(),
                'run_end' => $runEnd->toDateTimeString(),
                'total_actions_inserted' => (int) $totalInserted,
                'total_publish_jobs_enqueued' => (int) $publishedEnqueued,
                'distinct_orders_in_publish_list' => (int) $distinctOrders,
                'orders_that_received_actions' => (int) $ordersWithActions,
                'total_planned_publishes' => (int) $totalPublishes,
                'total_publish_limit' => (int) $maxTotal,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to emit resume report', ['error' => $e->getMessage()]);
        }
        // finally: release lock and set last_run so the scheduler/min-interval logic works
        } finally {
            try {
                $redis->set($lastRunKey, time());
            } catch (\Throwable $e) {
                Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to write last_run', ['error' => $e->getMessage()]);
            }

            try {
                $redis->del($lockKey);
            } catch (\Throwable $e) {
                Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to release lock', ['error' => $e->getMessage()]);
            }

            Log::info('[InsertAndPublishForActiveDashboardUsers] finished and cleaned up lock/last_run', ['last_run_key' => $lastRunKey, 'lock_key' => $lockKey]);
        }
    }
}
