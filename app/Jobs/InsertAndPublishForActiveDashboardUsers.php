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

        // No Redis run-lock: scheduling and run spacing are controlled by
        // `COORDINATOR_SCHEDULE_HOURS` in the Kernel (how often the command is
        // invoked) and by the per-run order limit below. We intentionally avoid
        // holding a Redis lock here to prevent any interaction with other
        // Redis-based processes. This job will still exit quickly if another
        // instance is manually started concurrently by operator action.
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

        // Decide whether to send an activation ping. Send only when:
        //  - there are uncompleted orders, AND
        //  - active users count is below threshold, AND
        //  - last ping was more than COORDINATOR_PING_INTERVAL_SECONDS ago.
        $pingKey = env('COORDINATOR_LAST_PING_KEY', 'coordinator:last_ping');
        $pingInterval = (int) env('COORDINATOR_PING_INTERVAL_SECONDS', 1800); // default 30 minutes
        $minActiveThreshold = (int) env('COORDINATOR_PING_MIN_ACTIVE_THRESHOLD', 10);

        $lastPing = 0;
        try {
            $lastPing = (int) $redis->get($pingKey);
        } catch (\Throwable $e) {
            $lastPing = 0;
        }

        $now = time();
        $timeSinceLastPing = $now - $lastPing;

        try {
            $hasUncompletedOrders = Order::where('status', 'active')->whereRaw('done_count < total_count')->exists();
        } catch (\Throwable $e) {
            Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to check for uncompleted orders', ['error' => $e->getMessage()]);
            $hasUncompletedOrders = false;
        }

        $activeCount = count($activeUsers);
        $shouldPing = ($hasUncompletedOrders && $activeCount < $minActiveThreshold && $timeSinceLastPing >= $pingInterval);

        if ($shouldPing) {
            $coordBatchId = 'coord_' . $now . '_' . random_int(1000, 9999);
            try {
                Log::info('[InsertAndPublishForActiveDashboardUsers] enqueuing activation ping to devices/activation/req', ['batch_id' => $coordBatchId]);

                $pingPayload = ['request' => 'ping'];
                $job = json_encode([
                    'topic' => 'devices/activation/req',
                    'payload' => $pingPayload,
                    'qos' => 1,
                    'retain' => false,
                    'meta' => ['enqueued_at' => $now, 'batch_id' => $coordBatchId, 'coordinator' => true]
                ]);

                $redis->rpush($queueKey, $job);
                try {
                    $redis->set($pingKey, $now);
                    $redis->expire($pingKey, max(0, $pingInterval));
                } catch (\Throwable $e) {
                    // best-effort; continue
                }
            } catch (\Throwable $e) {
                Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to enqueue activation ping', ['error' => $e->getMessage()]);
            }

            // Wait a short period to allow devices/dashboard to respond and Redis to stabilize
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
        } else {
            Log::info('[InsertAndPublishForActiveDashboardUsers] skipping ping', ['has_uncompleted_orders' => $hasUncompletedOrders ?? false, 'active_count' => $activeCount ?? 0, 'time_since_last_ping' => $timeSinceLastPing ?? null]);
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
    $defaultPerRun = (int) env('COORDINATOR_DEFAULT_ORDERS_PER_RUN', 100);
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

        // Build per-order metadata first: capacity, eligible set and pending users.
        $resumeService = app(ResumeOrderService::class);
        $ordersMeta = []; // order_id => meta (includes order model)
        $totalReserved = 0; // pending + reserved by user iteration (pre-claim)

        foreach ($orders as $order) {
            try {
                Log::info('[InsertAndPublishForActiveDashboardUsers] preparing order', ['order_id' => $order->id, 'total_count' => $order->total_count]);

                $doneCount = DB::table('actions')
                    ->where('order_id', $order->id)
                    ->where('status', 'done')
                    ->count();

                $available = max(0, $order->total_count - $doneCount);
                Log::info('[InsertAndPublishForActiveDashboardUsers] order capacity', ['order_id' => $order->id, 'done' => $doneCount, 'available' => $available]);

                if ($available <= 0) {
                    continue;
                }

                // Consider all currently active users for eligibility checks.
                // Previously we limited to the first $usersPerOrderLimit which could
                // skip eligible users. The second-phase selection still respects
                // per-user caps and global caps, so it's safe to consider the
                // full active set here.
                $candidates = $activeUsers;
                if (empty($candidates)) {
                    continue;
                }

                // Compute eligible users for this order (cached here so we can
                // test membership quickly when iterating active users). Measure
                // elapsed time to diagnose slow ResumeOrderService calls.
                try {
                    $t0 = microtime(true);

                    $candidateIds = $candidates;
                    if (empty($candidateIds)) {
                        $eligibleUsersCollection = collect([]);
                    } else {
                        // Pending users for this order
                        $pendingUserIds = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->where('status', 'pending')
                            ->pluck('user_id')
                            ->toArray();

                        $actualDoneCount = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->where('status', 'done')
                            ->count();

                        $recentPendingCount = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->where('status', 'pending')
                            ->where('created_at', '>=', now()->subMinutes(30))
                            ->count();

                        $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;

                        // If no remaining slots, only consider pending users (intersected with candidates)
                        if ($remaining <= 0) {
                            $intersectPending = array_values(array_intersect($pendingUserIds, $candidateIds));
                            $eligibleUsersCollection = \App\Models\User::whereIn('id', $intersectPending)->get();
                        } else {
                            // Query new eligible users restricted to the candidate IDs
                            $eligibleQuery = \App\Models\User::where('type', 'user')
                                ->whereIn('id', $candidateIds)
                                ->whereNotIn('id', function ($q) use ($order) {
                                    $q->select('user_id')->from('actions')->where('order_id', $order->id)->whereIn('status', ['done', 'external']);
                                })
                                ->whereNotIn('id', $pendingUserIds)
                                ->where('profile_link', '!=', $order->target_url)
                                ->whereNotIn('id', function ($sub) use ($order) {
                                    $sub->select('a1.user_id')
                                        ->from('actions as a1')
                                        ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                                        ->whereIn('a1.status', ['done', 'external'])
                                        ->whereColumn('o1.target_url', 'users.profile_link')
                                        ->where('o1.user_id', $order->user_id);
                                });

                            $eligibleUsers = $eligibleQuery->get();

                            // Include pending users (within candidates) at front
                            $intersectPending = array_values(array_intersect($pendingUserIds, $candidateIds));
                            $pendingUsers = \App\Models\User::whereIn('id', $intersectPending)->get();
                            $eligibleUsersCollection = $pendingUsers->merge($eligibleUsers);
                        }
                    }

                    $t1 = microtime(true);
                    $eligibleIdsAll = $eligibleUsersCollection->pluck('id')->toArray();
                    $elapsedMs = round(($t1 - $t0) * 1000, 2);
                    Log::info('[ResumeOrderService] getEligibleUsers (batched) completed', ['order_id' => $order->id, 'elapsed_ms' => $elapsedMs]);
                    if ($elapsedMs > 500) {
                        Log::warning('[ResumeOrderService] getEligibleUsers (batched) slow', ['order_id' => $order->id, 'elapsed_ms' => $elapsedMs]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to compute eligible users (batched)', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    $eligibleIdsAll = $candidates;
                }

                $eligible = array_values(array_intersect($eligibleIdsAll, $candidates));
                Log::info('[InsertAndPublishForActiveDashboardUsers] eligible intersection counts', ['order_id' => $order->id, 'eligible_total' => count($eligibleIdsAll), 'active_checked' => count($candidates), 'eligible_after_intersect' => count($eligible)]);
                if (empty($eligible)) {
                    // No eligible active users for this order
                    continue;
                }

                // Pending users among the eligible+active set — they'll be published first
                $pendingUsers = DB::table('actions')
                    ->where('order_id', $order->id)
                    ->where('status', 'pending')
                    ->whereIn('user_id', $eligible)
                    ->pluck('user_id')
                    ->toArray();

                $ordersSummary[$order->id] = [
                    'pending_found' => count($pendingUsers),
                    'pending_added' => 0,
                    'claim_attempted' => 0,
                    'inserted' => 0,
                    'claimed_added' => 0
                ];

                $payloadBase = [
                    'url' => $order->target_url,
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'mediaId' => $order->mediaId ?? null,
                    'userPk' => $order->userPk ?? null,
                ];

                // Add existing pending users first (respecting per-user and global caps)
                $assigned = 0;
                foreach ($pendingUsers as $uid) {
                    if ($assigned >= $available) break;
                    if ($totalPublishes + $totalReserved >= $maxTotal) break;
                    $uid = (int) $uid;
                    $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                    if ($uc >= $perUserLimit) continue;

                    $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $payloadBase];
                    $userCounts[$uid] = $uc + 1;
                    $assigned++;
                    $ordersSummary[$order->id]['pending_added']++;
                    // Keep the global publishes counter in sync with the list so
                    // the later batching logic sees the correct total.
                    $totalPublishes++;
                }

                // Reduce available by already-added pending publishes
                $remaining = max(0, $available - $assigned);

                // Precompute which active users already have any action for this order
                $alreadyActionedActive = DB::table('actions')
                    ->where('order_id', $order->id)
                    ->whereIn('user_id', $activeUsers)
                    ->pluck('user_id')
                    ->toArray();

                // Store metadata for the user-driven fill phase
                $ordersMeta[$order->id] = [
                    'order' => $order,
                    'remaining' => $remaining,
                    'eligibleSet' => array_flip($eligible), // quick membership test
                    'alreadyActioned' => array_flip($alreadyActionedActive),
                    'toClaim' => [],
                    'payloadBase' => $payloadBase,
                ];

                // Count pending adds towards reserved (they will be published)
                $totalReserved += $ordersSummary[$order->id]['pending_added'];

            } catch (\Throwable $e) {
                Log::error('[InsertAndPublishForActiveDashboardUsers] error preparing order', ['order_id' => $order->id ?? null, 'error' => $e->getMessage()]);
            }
        }

        // Recompute per-order eligible->active membership: each order may have
        // a different eligible set. Intersect the precomputed eligible set with
        // the current active users so we only consider users that are both
        // eligible for this order and currently active.
        foreach ($ordersMeta as $oid => &$metaRef) {
            $eligibleKeys = array_keys($metaRef['eligibleSet']);
            $eligibleActive = array_values(array_intersect($eligibleKeys, $activeUsers));
            $metaRef['eligibleActive'] = array_flip($eligibleActive);
        }
        unset($metaRef);

        // Second phase: for each order, iterate active users and try to fill
        // remaining slots. This is an order-major approach: pick users for
        // the oldest order first, then move to the next order. It better
        // matches the requirement: "get all active users and check their
        // eligibility then choose from them the number of actions needed
        // to be completed then loop on other uncompleted orders".
    // By default allow claiming new eligible users so orders can be
    // completed using both existing pending actions and newly-claimed
    // eligible users. Operators can still disable this behavior by
    // setting RESUME_CLAIM_NEW=false in the environment if desired.
    $claimNew = filter_var(env('RESUME_CLAIM_NEW', true), FILTER_VALIDATE_BOOLEAN);
        if ($claimNew && !empty($ordersMeta)) {
            foreach ($orders as $order) {
                $oid = $order->id;
                if (!isset($ordersMeta[$oid])) continue;
                // If nothing to fill, skip
                if ($ordersMeta[$oid]['remaining'] <= 0) continue;

                foreach ($activeUsers as $uid) {
                    if ($ordersMeta[$oid]['remaining'] <= 0) break;
                    if ($totalReserved >= $maxTotal) break 2; // global reservation cap

                    $uid = (int) $uid;
                    $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                    if ($uc >= $perUserLimit) continue;

                    // Skip if user already has action or is not eligible for
                    // this particular order. Use the per-order eligibleActive
                    // set computed above to ensure we respect order-specific
                    // eligibility.
                    if (isset($ordersMeta[$oid]['alreadyActioned'][$uid])) continue;
                    if (!isset($ordersMeta[$oid]['eligibleActive'][$uid])) continue;

                    // Avoid adding the same uid twice
                    if (in_array($uid, $ordersMeta[$oid]['toClaim'], true)) continue;

                    // Reserve a slot for this user on this order
                    $ordersMeta[$oid]['toClaim'][] = $uid;
                    $ordersMeta[$oid]['remaining']--;
                    $userCounts[$uid] = $uc + 1;
                    $totalReserved++;
                }
            }
        }

        // Third phase: perform batch inserts for claimed users per order and add resulting publishes
        foreach ($ordersMeta as $oid => $meta) {
            $order = $meta['order'];
            $toClaim = $meta['toClaim'];
            if (empty($toClaim)) continue;

            $ordersSummary[$oid]['claim_attempted'] = count($toClaim);
            try {
                $batchService = app(BatchActionService::class);
                $result = $batchService->batchInsertPendingAction($order, $toClaim);
                Log::info('[InsertAndPublishForActiveDashboardUsers] BatchActionService result', ['order_id' => $order->id, 'result' => $result]);
                if (is_array($result) && isset($result['inserted'])) {
                    $ordersSummary[$oid]['inserted'] = intval($result['inserted']);
                    $totalInserted += intval($result['inserted']);
                }
            } catch (\Throwable $e) {
                Log::warning('[InsertAndPublishForActiveDashboardUsers] BatchActionService failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }

            // Determine which of the attempted users now have actions (pending/done/external)
            $existingUserIds = DB::table('actions')
                ->where('order_id', $order->id)
                ->whereIn('user_id', $toClaim)
                ->whereIn('status', ['pending', 'done', 'external'])
                ->pluck('user_id')
                ->toArray();

            // Add claimed ones to the global publish list (respecting per-user and global caps)
            foreach ($existingUserIds as $uid) {
                if ($totalPublishes >= $maxTotal) break;
                $uid = (int) $uid;
                $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                if ($uc > $perUserLimit) continue;

                $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $meta['payloadBase']];
                $ordersSummary[$oid]['claimed_added']++;
                $totalPublishes++;
            }
        }

    // After collecting everything across orders, ensure totalPublishes matches
    // the publishList length (this keeps accounting accurate) and push
    // publishes in up to $maxBatches batches
    $totalPublishes = count($publishList);
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
        Log::info('[InsertAndPublishForActiveDashboardUsers] finished');
    }
}
