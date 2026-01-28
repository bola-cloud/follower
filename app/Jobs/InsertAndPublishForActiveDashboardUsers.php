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
        $totalReserved = 0; // pending + reserved by user iteration (pre-claim)

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

        // Dynamic Rotating Window Strategy:
        // Balance between oldest (completion), newest (freshness), and rotating window (fairness)
        // - Always fetch some oldest orders to ensure stuck orders complete
        // - Always fetch some newest orders to ensure fresh orders start
        // - Rotate through mid-range orders progressively using a sliding window

        $oldestPercentage = (int) env('COORDINATOR_OLDEST_PERCENTAGE', 40); // default 40%
        $newestPercentage = (int) env('COORDINATOR_NEWEST_PERCENTAGE', 20); // default 20%
        $rotatingPercentage = 100 - $oldestPercentage - $newestPercentage; // remaining 40%

        // Clamp percentages
        $oldestPercentage = max(0, min(100, $oldestPercentage));
        $newestPercentage = max(0, min(100, $newestPercentage));
        $rotatingPercentage = max(0, 100 - $oldestPercentage - $newestPercentage);

        $oldestCount = (int) floor($effectiveOrdersLimit * ($oldestPercentage / 100));
        $newestCount = (int) floor($effectiveOrdersLimit * ($newestPercentage / 100));
        $rotatingCount = $effectiveOrdersLimit - $oldestCount - $newestCount;

        // Get rotating window position from Redis (tracks last processed order ID)
        $rotatingWindowKey = env('COORDINATOR_ROTATING_WINDOW_KEY', 'coordinator:rotating_window_last_id');
        $lastRotatingId = 0;
        try {
            $lastRotatingId = (int) $redis->get($rotatingWindowKey);
        } catch (\Throwable $e) {
            $lastRotatingId = 0;
        }

        Log::info('[InsertAndPublishForActiveDashboardUsers] dynamic rotating window strategy', [
            'total_limit' => $effectiveOrdersLimit,
            'oldest_count' => $oldestCount,
            'newest_count' => $newestCount,
            'rotating_count' => $rotatingCount,
            'last_rotating_id' => $lastRotatingId,
            'percentages' => "{$oldestPercentage}% oldest / {$rotatingPercentage}% rotating / {$newestPercentage}% newest"
        ]);

        $orders = collect();

        // 1. Fetch OLDEST orders (always process to ensure completion)
        if ($oldestCount > 0) {
            $oldestOrders = Order::where('status', 'active')
                ->whereRaw('done_count < total_count')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                ->orderBy('orders.created_at', 'asc')
                ->select('orders.*')
                ->limit($oldestCount)
                ->get();

            $orders = $orders->merge($oldestOrders);
            Log::info('[InsertAndPublishForActiveDashboardUsers] fetched oldest orders', [
                'count' => $oldestOrders->count(),
                'first_id' => $oldestOrders->first()->id ?? null,
                'last_id' => $oldestOrders->last()->id ?? null
            ]);
        }

        // 2. Fetch ROTATING WINDOW orders (progressive scan through mid-range)
        if ($rotatingCount > 0) {
            $rotatingOrders = Order::where('status', 'active')
                ->whereRaw('done_count < total_count')
                ->where('orders.id', '>', $lastRotatingId) // Continue from last position - specify table
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                ->orderBy('orders.id', 'asc') // Use ID order for consistent progression
                ->select('orders.*')
                ->limit($rotatingCount)
                ->get();

            // If we got fewer than requested, we've reached the end - restart from beginning
            if ($rotatingOrders->count() < $rotatingCount && $lastRotatingId > 0) {
                Log::info('[InsertAndPublishForActiveDashboardUsers] rotating window reached end, restarting from beginning', [
                    'fetched' => $rotatingOrders->count(),
                    'needed' => $rotatingCount
                ]);

                $remaining = $rotatingCount - $rotatingOrders->count();
                $restartOrders = Order::where('status', 'active')
                    ->whereRaw('done_count < total_count')
                    ->join('users', 'orders.user_id', '=', 'users.id')
                    ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                    ->orderBy('orders.id', 'asc')
                    ->select('orders.*')
                    ->limit($remaining)
                    ->get();

                $rotatingOrders = $rotatingOrders->merge($restartOrders);

                // Update window position to the last ID from restart batch
                if ($restartOrders->isNotEmpty()) {
                    $newLastId = $restartOrders->last()->id;
                    try {
                        $redis->set($rotatingWindowKey, $newLastId);
                    } catch (\Throwable $e) {
                        Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to update rotating window position', ['error' => $e->getMessage()]);
                    }
                }
            } else {
                // Update window position to continue from this point next run
                if ($rotatingOrders->isNotEmpty()) {
                    $newLastId = $rotatingOrders->last()->id;
                    try {
                        $redis->set($rotatingWindowKey, $newLastId);
                    } catch (\Throwable $e) {
                        Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to update rotating window position', ['error' => $e->getMessage()]);
                    }
                }
            }

            $orders = $orders->merge($rotatingOrders);
            Log::info('[InsertAndPublishForActiveDashboardUsers] fetched rotating window orders', [
                'count' => $rotatingOrders->count(),
                'first_id' => $rotatingOrders->first()->id ?? null,
                'last_id' => $rotatingOrders->last()->id ?? null,
                'new_window_position' => $rotatingOrders->last()->id ?? $lastRotatingId
            ]);
        }

        // 3. Fetch NEWEST orders (ensure fresh orders start processing)
        if ($newestCount > 0) {
            $newestOrders = Order::where('status', 'active')
                ->whereRaw('done_count < total_count')
                ->join('users', 'orders.user_id', '=', 'users.id')
                ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                ->orderBy('orders.created_at', 'desc')
                ->select('orders.*')
                ->limit($newestCount)
                ->get();

            $orders = $orders->merge($newestOrders);
            Log::info('[InsertAndPublishForActiveDashboardUsers] fetched newest orders', [
                'count' => $newestOrders->count(),
                'first_id' => $newestOrders->first()->id ?? null,
                'last_id' => $newestOrders->last()->id ?? null
            ]);
        }

        // Remove duplicates (order may appear in multiple batches)
        $orders = $orders->unique('id');

        Log::info('[InsertAndPublishForActiveDashboardUsers] orders selected (initial)', [
            'total_count' => $orders->count(),
            'oldest_batch' => $oldestCount,
            'rotating_batch' => $rotatingCount,
            'newest_batch' => $newestCount
        ]);

        // Global publish collection to enforce total and per-user caps
        $publishList = []; // each item: ['user_id' => int, 'order_id' => int, 'payload' => array]
        // track seen user/order pairs to avoid enqueueing duplicates within a run
        $seenPublish = [];
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

        // ✅ FIX ISSUE #3: Re-read active users from Redis immediately before eligibility checks
        // This ensures we capture any users who became active AFTER the initial snapshot
        // (e.g., users who responded to ping or opened dashboard during job execution)
        try {
            $activeUsers = $redis->smembers($activeKey) ?: [];
            $activeUsers = array_values(array_filter(array_map('intval', $activeUsers)));
            Log::info('[InsertAndPublishForActiveDashboardUsers] refreshed active users before eligibility checks', ['count' => count($activeUsers)]);
        } catch (\Throwable $e) {
            Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to refresh active users, using previous snapshot', ['error' => $e->getMessage()]);
        }

        // Build per-order metadata first: capacity, eligible set and pending users.
        $resumeService = app(ResumeOrderService::class);
        $ordersMeta = []; // order_id => meta (includes order model)
        $totalReserved = 0; // pending + reserved by user iteration (pre-claim)

        // ✅ CRITICAL FIX: Track user assignments by normalized target string WITHIN THIS RUN
        // Prevents same user from being assigned to multiple orders with same link
        // when those orders are processed in the same coordinator run
        $assignedUsersByTarget = []; // normalized_target => [user_id1, user_id2, ...]

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

                // Consider all currently active users for eligibility checks
                $candidates = $activeUsers;
                if (empty($candidates)) {
                    continue;
                }

                // ✅ CRITICAL: Use centralized normalization helper from ResumeOrderService
                // This ensures the same canonical key is used across all jobs and services.
                $normalizedTarget = $resumeService->getNormalizedTargetKey($order->target_url);
                $targetKey = $normalizedTarget; // use normalized string as run-level key
                $alreadyAssignedToThisLink = $assignedUsersByTarget[$targetKey] ?? [];
                if (!empty($alreadyAssignedToThisLink)) {
                    $candidates = array_values(array_diff($candidates, $alreadyAssignedToThisLink));
                    Log::info('[InsertAndPublishForActiveDashboardUsers] excluded users already assigned to this link in current run', [
                        'order_id' => $order->id,
                        'target_key' => $targetKey,
                        'excluded_count' => count($alreadyAssignedToThisLink),
                        'remaining_candidates' => count($candidates)
                    ]);
                }

                if (empty($candidates)) {
                    Log::info('[InsertAndPublishForActiveDashboardUsers] no candidates left after excluding already-assigned users', ['order_id' => $order->id]);
                    continue;
                }

                // ✅ FIX ISSUE #1 & #2: Use new batch eligibility method instead of duplicated logic
                // This is faster, cleaner, and correctly excludes users who took action on same link in OTHER orders
                try {
                    // Detailed start log for batch eligibility
                    Log::info('[InsertAndPublishForActiveDashboardUsers] batchEligibility.start', [
                        'order_id' => $order->id,
                        'candidates_count' => count($candidates),
                        'candidates_sample' => array_slice($candidates, 0, 10),
                        'normalized_target' => $normalizedTarget,
                        'target_key' => $targetKey,
                        'already_assigned_count' => count($alreadyAssignedToThisLink)
                    ]);

                    // Coordinator-level diagnostics: list other orders that normalize to the same target
                    try {
                        // Fetch candidate orders (active) and filter in PHP using the centralized normalizer.
                        // This avoids fragile DB regex constructs and guarantees identical canonicalization.
                        $candidateOrders = \App\Models\Order::where('status', 'active')
                            ->where('id', '!=', $order->id)
                            ->select('id', 'target_url', 'target_url_hash')
                            ->get();

                        $matchingOrders = $candidateOrders->filter(function ($r) use ($resumeService, $normalizedTarget) {
                            return $resumeService->getNormalizedTargetKey($r->target_url) === $normalizedTarget;
                        })->values();

                        if ($matchingOrders->isNotEmpty()) {
                            Log::info('[InsertAndPublishForActiveDashboardUsers] matching_orders_for_target', [
                                'order_id' => $order->id,
                                'normalized_target' => $normalizedTarget,
                                'matching_count' => $matchingOrders->count(),
                                'matching_sample' => $matchingOrders->take(10)->map(function ($r) {
                                    return ['id' => $r->id, 'url' => $r->target_url, 'hash' => $r->target_url_hash];
                                })->toArray()
                            ]);

                            $matchingOrderIds = $matchingOrders->pluck('id')->toArray();
                            $actionsOnMatching = DB::table('actions as a1')
                                ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                                ->whereIn('o1.id', $matchingOrderIds)
                                ->whereIn('a1.status', ['done', 'external'])
                                ->select('a1.user_id', 'a1.order_id', 'a1.status')
                                ->get();

                            Log::info('[InsertAndPublishForActiveDashboardUsers] actions_on_matching_orders', [
                                'order_id' => $order->id,
                                'normalized_target' => $normalizedTarget,
                                'actions_count' => $actionsOnMatching->count(),
                                'actions_sample' => $actionsOnMatching->take(20)->toArray()
                            ]);

                            // Extra verbose trace for the known problematic link
                            if ($normalizedTarget === 'instagram.com/reel/drqcdg0ddzs') {
                                Log::warning('[InsertAndPublishForActiveDashboardUsers] VERBOSE: problematic target detailed dump', [
                                    'order_id' => $order->id,
                                    'matching_orders' => $matchingOrders->toArray(),
                                    'actions_on_matching' => $actionsOnMatching->toArray()
                                ]);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to fetch matching orders/actions', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    }

                    $t0 = microtime(true);

                    $eligibleIdsAll = $resumeService->batchCheckEligibility($order, $candidates);

                    $t1 = microtime(true);
                    $elapsedMs = round(($t1 - $t0) * 1000, 2);
                    Log::info('[ResumeOrderService] batchCheckEligibility completed', ['order_id' => $order->id, 'elapsed_ms' => $elapsedMs, 'eligible_count' => count($eligibleIdsAll)]);
                    if ($elapsedMs > 500) {
                        Log::warning('[ResumeOrderService] batchCheckEligibility slow', ['order_id' => $order->id, 'elapsed_ms' => $elapsedMs]);
                    }

                    // Post-check diagnostics: who was removed from candidates
                    $removed = array_values(array_diff($candidates, $eligibleIdsAll));
                    Log::info('[InsertAndPublishForActiveDashboardUsers] batchEligibility.result', [
                        'order_id' => $order->id,
                        'candidates_count' => count($candidates),
                        'eligible_count' => count($eligibleIdsAll),
                        'removed_count' => count($removed),
                        'removed_sample' => array_slice($removed, 0, 10)
                    ]);

                } catch (\Throwable $e) {
                    Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to compute eligible users (batched)', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                    $eligibleIdsAll = $candidates;
                }

                $eligible = $eligibleIdsAll;
                Log::info('[InsertAndPublishForActiveDashboardUsers] eligible counts', ['order_id' => $order->id, 'eligible_count' => count($eligible), 'active_checked' => count($candidates)]);
                if (empty($eligible)) {
                    // No eligible active users for this order
                    continue;
                }

                // Pending users among the eligible+active set — they'll be published first
                // batchCheckEligibility already verified these users are eligible (no done/external on same normalized URL)
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

                // Enforce mutual exclusion for ID fields to handle legacy/tainted data
                $mediaId = null;
                $userPk = null;
                if (in_array($order->type, ['like', 'comment'])) {
                    $mediaId = $order->mediaId ?? null;
                } elseif ($order->type === 'follow') {
                    $userPk = $order->userPk ?? null;
                }

                $payloadBase = [
                    'url' => $order->target_url,
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'mediaId' => $mediaId,
                    'userPk' => $userPk,
                ];

                // Inject comment if applicable (for 'comment' type)
                if ($order->type === 'comment') {
                    // We need to fetch the comment for each specific user later, 
                    // as the comment is specific to the assigned action. 
                    // However, here we are building a BASE payload. 
                    // The actual comment needs to be injected per-user in the loop below.
                    // So we leave payloadBase as is here, and modify the loop.
                }

                // Add existing pending users first (respecting per-user and global caps)
                $assigned = 0;
                foreach ($pendingUsers as $uid) {
                    if ($assigned >= $available)
                        break;
                    if ($totalPublishes + $totalReserved >= $maxTotal)
                        break;
                    $uid = (int) $uid;
                    $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                    if ($uc >= $perUserLimit)
                        continue;

                    // avoid enqueueing the same (order,user) pair twice in one run
                    $pairKey = $order->id . ':' . $uid;
                    if (isset($seenPublish[$pairKey])) {
                        continue;
                    }

                    $userPayload = $payloadBase;
                    if ($order->type === 'comment') {
                        $actionData = DB::table('actions')
                            ->where('order_id', $order->id)
                            ->where('user_id', $uid)
                            ->value('data');

                        if ($actionData) {
                            $decoded = json_decode($actionData, true);
                            if (isset($decoded['comment'])) {
                                $userPayload['comment'] = $decoded['comment'];
                            }
                        }
                    }

                    $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $userPayload];
                    $seenPublish[$pairKey] = true;
                    $userCounts[$uid] = $uc + 1;
                    $assigned++;
                    $ordersSummary[$order->id]['pending_added']++;

                    // Track this user as assigned to this normalized target for this run
                    // Use the $targetKey variable computed earlier (normalized string)
                    if (!isset($assignedUsersByTarget[$targetKey])) {
                        $assignedUsersByTarget[$targetKey] = [];
                    }
                    $assignedUsersByTarget[$targetKey][] = $uid;
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
                    'targetKey' => $targetKey, // store normalized key for tracking assignments
                ];

                // Count pending adds towards reserved (they will be published)
                $totalReserved += $ordersSummary[$order->id]['pending_added'];

            } catch (\Throwable $e) {
                Log::error('[InsertAndPublishForActiveDashboardUsers] error preparing order', ['order_id' => $order->id ?? null, 'error' => $e->getMessage()]);
            }
        }

        // Filter ordersMeta to only include orders that have eligible active users
        // This prevents us from repeatedly trying to process orders that can't
        // make progress with the current active user set
        $ordersMetaFiltered = [];
        foreach ($ordersMeta as $oid => $meta) {
            $eligibleKeys = array_keys($meta['eligibleSet']);
            $eligibleActive = array_values(array_intersect($eligibleKeys, $activeUsers));
            if (!empty($eligibleActive) || !empty($meta['eligibleSet'])) {
                $ordersMetaFiltered[$oid] = $meta;
            } else {
                Log::info('[InsertAndPublishForActiveDashboardUsers] skipping order - no eligible active users', [
                    'order_id' => $oid,
                    'remaining' => $meta['remaining']
                ]);
            }
        }
        $ordersMeta = $ordersMetaFiltered;

        Log::info('[InsertAndPublishForActiveDashboardUsers] orders after eligibility filter', [
            'original_count' => count($orders),
            'filtered_count' => count($ordersMeta)
        ]);

        // Backfill logic: if we have fewer orders than target after filtering,
        // fetch additional orders to reach the target (up to a safety limit)
        // Use same three-way strategy: oldest + rotating + newest
        $alreadySelectedIds = $orders->pluck('id')->toArray();
        $backfillAttempts = 0;
        $maxBackfillAttempts = (int) env('MAX_BACKFILL_ATTEMPTS', 10);

        while (count($ordersMeta) < $effectiveOrdersLimit && $backfillAttempts < $maxBackfillAttempts) {
            $needed = $effectiveOrdersLimit - count($ordersMeta);

            // Apply same three-way strategy to backfill
            $backfillOldestCount = (int) floor($needed * ($oldestPercentage / 100));
            $backfillNewestCount = (int) floor($needed * ($newestPercentage / 100));
            $backfillRotatingCount = $needed - $backfillOldestCount - $backfillNewestCount;

            Log::info('[InsertAndPublishForActiveDashboardUsers] backfill attempt', [
                'attempt' => $backfillAttempts + 1,
                'current_orders' => count($ordersMeta),
                'target' => $effectiveOrdersLimit,
                'needed' => $needed,
                'backfill_oldest' => $backfillOldestCount,
                'backfill_rotating' => $backfillRotatingCount,
                'backfill_newest' => $backfillNewestCount,
                'excluded_ids_count' => count($alreadySelectedIds)
            ]);

            $additionalOrders = collect();

            // Backfill oldest orders
            if ($backfillOldestCount > 0) {
                $backfillOldest = Order::where('status', 'active')
                    ->whereRaw('done_count < total_count')
                    ->whereNotIn('orders.id', $alreadySelectedIds) // Specify table
                    ->join('users', 'orders.user_id', '=', 'users.id')
                    ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                    ->orderBy('orders.created_at', 'asc')
                    ->select('orders.*')
                    ->limit($backfillOldestCount)
                    ->get();

                $additionalOrders = $additionalOrders->merge($backfillOldest);
            }

            // Backfill rotating window orders
            if ($backfillRotatingCount > 0) {
                // Get current window position
                try {
                    $currentRotatingId = (int) $redis->get($rotatingWindowKey);
                } catch (\Throwable $e) {
                    $currentRotatingId = 0;
                }

                $backfillRotating = Order::where('status', 'active')
                    ->whereRaw('done_count < total_count')
                    ->where('orders.id', '>', $currentRotatingId) // Specify table
                    ->whereNotIn('orders.id', $alreadySelectedIds) // Specify table
                    ->join('users', 'orders.user_id', '=', 'users.id')
                    ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                    ->orderBy('orders.id', 'asc')
                    ->select('orders.*')
                    ->limit($backfillRotatingCount)
                    ->get();

                // If reached end, wrap around
                if ($backfillRotating->count() < $backfillRotatingCount && $currentRotatingId > 0) {
                    $remaining = $backfillRotatingCount - $backfillRotating->count();
                    $wrapAround = Order::where('status', 'active')
                        ->whereRaw('done_count < total_count')
                        ->whereNotIn('orders.id', $alreadySelectedIds) // Specify table
                        ->join('users', 'orders.user_id', '=', 'users.id')
                        ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                        ->orderBy('orders.id', 'asc')
                        ->select('orders.*')
                        ->limit($remaining)
                        ->get();

                    $backfillRotating = $backfillRotating->merge($wrapAround);
                }

                $additionalOrders = $additionalOrders->merge($backfillRotating);
            }

            // Backfill newest orders
            if ($backfillNewestCount > 0) {
                $backfillNewest = Order::where('status', 'active')
                    ->whereRaw('done_count < total_count')
                    ->whereNotIn('orders.id', $alreadySelectedIds) // Specify table
                    ->join('users', 'orders.user_id', '=', 'users.id')
                    ->orderByRaw("CASE WHEN users.type = 'admin' THEN 0 ELSE 1 END")
                    ->orderBy('orders.created_at', 'desc')
                    ->select('orders.*')
                    ->limit($backfillNewestCount)
                    ->get();

                $additionalOrders = $additionalOrders->merge($backfillNewest);
            }

            // Remove duplicates
            $additionalOrders = $additionalOrders->unique('id');

            if ($additionalOrders->isEmpty()) {
                Log::info('[InsertAndPublishForActiveDashboardUsers] backfill complete - no more orders available');
                break; // No more orders to fetch
            }

            // Early termination: if we fetched significantly fewer orders than requested,
            // it means we've exhausted the available orders in the database.
            // Stop trying to backfill to avoid unnecessary iterations.
            $fetchedCount = $additionalOrders->count();
            $requestedCount = $needed; // Total requested for this backfill attempt
            $earlyStopThreshold = 0.3; // If we got less than 30% of requested, stop

            if ($fetchedCount < ($requestedCount * $earlyStopThreshold)) {
                Log::info('[InsertAndPublishForActiveDashboardUsers] backfill early stop - insufficient orders in database', [
                    'fetched_count' => $fetchedCount,
                    'requested_count' => $requestedCount,
                    'threshold' => ($requestedCount * $earlyStopThreshold),
                    'current_total' => count($ordersMeta)
                ]);
                // Process what we got, then break after this iteration
            }

            Log::info('[InsertAndPublishForActiveDashboardUsers] backfill fetched orders', [
                'fetched_count' => $fetchedCount,
                'requested_count' => $requestedCount
            ]);

            // Process additional orders (same logic as initial orders)
            $addedCount = 0;
            foreach ($additionalOrders as $order) {
                try {
                    Log::info('[InsertAndPublishForActiveDashboardUsers] preparing backfill order', ['order_id' => $order->id, 'total_count' => $order->total_count]);

                    $doneCount = DB::table('actions')
                        ->where('order_id', $order->id)
                        ->where('status', 'done')
                        ->count();

                    $available = max(0, $order->total_count - $doneCount);
                    Log::info('[InsertAndPublishForActiveDashboardUsers] backfill order capacity', ['order_id' => $order->id, 'done' => $doneCount, 'available' => $available]);

                    if ($available <= 0) {
                        $alreadySelectedIds[] = $order->id;
                        continue;
                    }

                    $candidates = $activeUsers;
                    if (empty($candidates)) {
                        $alreadySelectedIds[] = $order->id;
                        continue;
                    }

                    // ✅ CRITICAL: Use centralized normalization helper from ResumeOrderService
                    // This ensures the same canonical key is used across all jobs and services.
                    $normalizedTarget = $resumeService->getNormalizedTargetKey($order->target_url);
                    $targetKey = $normalizedTarget; // use normalized string as run-level key
                    $alreadyAssignedToThisLink = $assignedUsersByTarget[$targetKey] ?? [];
                    if (!empty($alreadyAssignedToThisLink)) {
                        $candidates = array_values(array_diff($candidates, $alreadyAssignedToThisLink));
                        Log::info('[InsertAndPublishForActiveDashboardUsers] backfill: excluded users already assigned to this link in current run', [
                            'order_id' => $order->id,
                            'target_key' => $targetKey,
                            'excluded_count' => count($alreadyAssignedToThisLink),
                            'remaining_candidates' => count($candidates)
                        ]);
                    }

                    if (empty($candidates)) {
                        Log::info('[InsertAndPublishForActiveDashboardUsers] backfill: no candidates left after excluding already-assigned users', ['order_id' => $order->id]);
                        $alreadySelectedIds[] = $order->id;
                        continue;
                    }

                    // ✅ Use batchCheckEligibility for backfill orders (same as initial orders)
                    // This ensures consistent eligibility logic and prevents duplicate link assignments
                    try {
                        $t0 = microtime(true);
                        $eligibleIdsAll = $resumeService->batchCheckEligibility($order, $candidates);
                        $t1 = microtime(true);
                        $elapsedMs = round(($t1 - $t0) * 1000, 2);
                        Log::info('[InsertAndPublishForActiveDashboardUsers] batchCheckEligibility (backfill) completed', [
                            'order_id' => $order->id,
                            'elapsed_ms' => $elapsedMs,
                            'eligible_count' => count($eligibleIdsAll)
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('[InsertAndPublishForActiveDashboardUsers] failed to compute eligible users (backfill)', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        $eligibleIdsAll = $candidates;
                    }

                    $eligible = array_values($eligibleIdsAll);
                    Log::info('[InsertAndPublishForActiveDashboardUsers] backfill eligible intersection counts', ['order_id' => $order->id, 'eligible_total' => count($eligibleIdsAll), 'active_checked' => count($candidates), 'eligible_after_intersect' => count($eligible)]);

                    $alreadySelectedIds[] = $order->id; // Track this order

                    if (empty($eligible)) {
                        Log::info('[InsertAndPublishForActiveDashboardUsers] skipping backfill order - no eligible active users', ['order_id' => $order->id]);
                        continue;
                    }

                    // Pending users among the eligible+active set
                    $pendingUsers = DB::table('actions')
                        ->where('order_id', $order->id)
                        ->where('status', 'pending')
                        ->whereIn('user_id', $eligible)
                        ->pluck('user_id')
                        ->toArray();

                    $alreadyActioned = DB::table('actions')
                        ->where('order_id', $order->id)
                        ->pluck('user_id')
                        ->toArray();

                    $eligibleSet = array_fill_keys($eligible, true);

                    $payloadBase = [
                        'url' => $order->target_url,
                        'order_id' => $order->id,
                        'type' => $order->type,
                        'mediaId' => $order->mediaId ?? null,
                        'userPk' => $order->userPk ?? null,
                    ];

                    $ordersSummary[$order->id] = [
                        'pending_found' => count($pendingUsers),
                        'pending_added' => 0,
                        'claim_attempted' => 0,
                        'inserted' => 0,
                        'claimed_added' => 0
                    ];

                    // Add existing pending users first (respecting per-user and global caps)
                    $assigned = 0;
                    foreach ($pendingUsers as $uid) {
                        if ($assigned >= $available)
                            break;
                        if ($totalPublishes + $totalReserved >= $maxTotal)
                            break;
                        $uid = (int) $uid;
                        $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                        if ($uc >= $perUserLimit)
                            continue;

                        // avoid enqueueing the same (order,user) pair twice in one run
                        $pairKey = $order->id . ':' . $uid;
                        if (isset($seenPublish[$pairKey])) {
                            continue;
                        }

                        $userPayload = $payloadBase;
                        if ($order->type === 'comment') {
                            $actionData = DB::table('actions')
                                ->where('order_id', $order->id)
                                ->where('user_id', $uid)
                                ->value('data');

                            if ($actionData) {
                                $decoded = json_decode($actionData, true);
                                if (isset($decoded['comment'])) {
                                    $userPayload['comment'] = $decoded['comment'];
                                }
                            }
                        }

                        $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $userPayload];
                        $seenPublish[$pairKey] = true;
                        $userCounts[$uid] = $uc + 1;
                        $assigned++;
                        $ordersSummary[$order->id]['pending_added']++;

                        // Track this user as assigned to this normalized target for this run
                        if (!isset($assignedUsersByTarget[$targetKey])) {
                            $assignedUsersByTarget[$targetKey] = [];
                        }
                        $assignedUsersByTarget[$targetKey][] = $uid;
                        // Keep the global publishes counter in sync with the list
                        $totalPublishes++;
                    }

                    // Reduce available by already-added pending publishes
                    $remaining = max(0, $available - $assigned);

                    $ordersMeta[$order->id] = [
                        'order' => $order,
                        'remaining' => $remaining,
                        'eligibleSet' => $eligibleSet,
                        'alreadyActioned' => array_flip($alreadyActioned),
                        'toClaim' => [],
                        'payloadBase' => $payloadBase,
                        'targetKey' => $targetKey, // store normalized key for tracking assignments
                    ];

                    // Count pending adds towards reserved (they will be published)
                    $totalReserved += $ordersSummary[$order->id]['pending_added'];

                    $addedCount++;
                    Log::info('[InsertAndPublishForActiveDashboardUsers] backfill order added', ['order_id' => $order->id, 'eligible_count' => count($eligible)]);

                } catch (\Throwable $e) {
                    Log::error('[InsertAndPublishForActiveDashboardUsers] error preparing backfill order', ['order_id' => $order->id ?? null, 'error' => $e->getMessage()]);
                    if (isset($order->id)) {
                        $alreadySelectedIds[] = $order->id;
                    }
                }
            }

            Log::info('[InsertAndPublishForActiveDashboardUsers] backfill attempt complete', [
                'attempt' => $backfillAttempts + 1,
                'fetched' => $additionalOrders->count(),
                'added' => $addedCount,
                'current_total' => count($ordersMeta)
            ]);

            $backfillAttempts++;

            // If we didn't add any new orders this round, stop trying
            if ($addedCount === 0) {
                Log::info('[InsertAndPublishForActiveDashboardUsers] backfill stopping - no eligible orders found in this batch');
                break;
            }

            // Stop if we detected insufficient orders in database (early stop condition)
            if (isset($fetchedCount) && isset($requestedCount) && $fetchedCount < ($requestedCount * 0.3)) {
                Log::info('[InsertAndPublishForActiveDashboardUsers] backfill stopping - insufficient orders available in database');
                break;
            }
        }

        if ($backfillAttempts > 0) {
            Log::info('[InsertAndPublishForActiveDashboardUsers] backfill summary', [
                'attempts' => $backfillAttempts,
                'final_count' => count($ordersMeta),
                'target' => $effectiveOrdersLimit,
                'reached_target' => count($ordersMeta) >= $effectiveOrdersLimit
            ]);
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
        // All eligibility checks are performed by batchCheckEligibility() which is
        // the single authoritative source - we trust its eligible sets completely.
        // By default allow claiming new eligible users so orders can be
        // completed using both existing pending actions and newly-claimed
        // eligible users. Operators can still disable this behavior by
        // setting RESUME_CLAIM_NEW=false in the environment if desired.
        $claimNew = filter_var(env('RESUME_CLAIM_NEW', true), FILTER_VALIDATE_BOOLEAN);
        if ($claimNew && !empty($ordersMeta)) {
            foreach ($orders as $order) {
                $oid = $order->id;
                if (!isset($ordersMeta[$oid]))
                    continue;
                // If nothing to fill, skip
                if ($ordersMeta[$oid]['remaining'] <= 0)
                    continue;

                foreach ($activeUsers as $uid) {
                    if ($ordersMeta[$oid]['remaining'] <= 0)
                        break;
                    if ($totalReserved >= $maxTotal)
                        break 2; // global reservation cap

                    $uid = (int) $uid;
                    $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                    if ($uc >= $perUserLimit)
                        continue;

                    // Skip if user already has action
                    if (isset($ordersMeta[$oid]['alreadyActioned'][$uid]))
                        continue;

                    // ✅ CRITICAL FIX: Check if user is in the eligible set from batchCheckEligibility
                    // The eligible set already excludes:
                    // - Users with done/external on THIS order
                    // - Users with done/external on OTHER orders with same target URL
                    // - Users whose profile_link matches the target
                    // This is the authoritative source - if user is not in eligibleSet, skip them
                    if (!isset($ordersMeta[$oid]['eligibleSet'][$uid]))
                        continue;

                    // 🔥 CRITICAL DUPLICATE PREVENTION: Check if this user was already assigned
                    // to ANY order with the same normalized target in THIS RUN.
                    // This prevents duplicates when multiple orders with same link are processed
                    // in the same coordinator run, because eligibility was checked before
                    // actions were inserted for previous orders.
                    $tKey = $ordersMeta[$oid]['targetKey'];
                    $alreadyAssignedThisRun = $assignedUsersByTarget[$tKey] ?? [];
                    if (in_array($uid, $alreadyAssignedThisRun, true)) {
                        // Skip this user - they were already assigned to another order with same link
                        continue;
                    }

                    // Avoid adding the same uid twice
                    if (in_array($uid, $ordersMeta[$oid]['toClaim'], true))
                        continue;

                    // Reserve a slot for this user on this order
                    $ordersMeta[$oid]['toClaim'][] = $uid;
                    $ordersMeta[$oid]['remaining']--;
                    $userCounts[$uid] = $uc + 1;
                    $totalReserved++;

                    // Track this user as assigned to this normalized target for this run
                    if (!isset($assignedUsersByTarget[$tKey])) {
                        $assignedUsersByTarget[$tKey] = [];
                    }
                    $assignedUsersByTarget[$tKey][] = $uid;
                }
            }
        }

        // Third phase: perform batch inserts for claimed users per order and add resulting publishes
        foreach ($ordersMeta as $oid => $meta) {
            $order = $meta['order'];
            $toClaim = $meta['toClaim'];
            if (empty($toClaim))
                continue;

            $ordersSummary[$oid]['claim_attempted'] = count($toClaim);
            try {
                // Use centralized normalizer for debug comparisons as well
                $normalizedOrderTarget = $resumeService->getNormalizedTargetKey($order->target_url);

                if ($normalizedOrderTarget === 'instagram.com/reel/drqcdg0ddzs') {
                    Log::warning('[InsertAndPublishForActiveDashboardUsers] DEBUG: about to batchInsertPendingAction', [
                        'order_id' => $order->id,
                        'toClaim_count' => count($toClaim),
                        'toClaim_sample' => array_slice($toClaim, 0, 20),
                        'normalized_target' => $normalizedOrderTarget
                    ]);
                }

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

            // Post-insert diagnostic for problematic normalized target: dump actions rows for claimed users
            if (isset($normalizedOrderTarget) && $normalizedOrderTarget === 'instagram.com/reel/drqcdg0ddzs') {
                try {
                    $actionsRows = DB::table('actions')
                        ->where('order_id', $order->id)
                        ->whereIn('user_id', $toClaim)
                        ->select('user_id', 'status', 'created_at', 'updated_at')
                        ->get();

                    Log::warning('[InsertAndPublishForActiveDashboardUsers] DEBUG: post-insert actions snapshot', [
                        'order_id' => $order->id,
                        'normalized_target' => $normalizedOrderTarget,
                        'actions_count' => $actionsRows->count(),
                        'actions_sample' => $actionsRows->toArray()
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('[InsertAndPublishForActiveDashboardUsers] DEBUG: failed to read post-insert actions', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
            }

            // Add claimed ones to the global publish list (respecting per-user and global caps)
            foreach ($existingUserIds as $uid) {
                if ($totalPublishes >= $maxTotal)
                    break;
                $uid = (int) $uid;
                $uc = isset($userCounts[$uid]) ? $userCounts[$uid] : 0;
                if ($uc >= $perUserLimit)
                    continue;
                // avoid enqueueing duplicates if this (order,user) was already added
                $pairKey = $order->id . ':' . $uid;
                if (isset($seenPublish[$pairKey])) {
                    continue;
                }

                $userPayload = $meta['payloadBase'];
                if ($order->type === 'comment') {
                    $actionData = DB::table('actions')
                        ->where('order_id', $order->id)
                        ->where('user_id', $uid)
                        ->value('data');

                    if ($actionData) {
                        $decoded = json_decode($actionData, true);
                        if (isset($decoded['comment'])) {
                            $userPayload['comment'] = $decoded['comment'];
                        }
                    }
                }

                $publishList[] = ['user_id' => $uid, 'order_id' => $order->id, 'payload' => $userPayload];
                $seenPublish[$pairKey] = true;
                $ordersSummary[$oid]['claimed_added']++;
                $totalPublishes++;

                // Track this user as assigned to this normalized target for this run
                $tKey = $meta['targetKey'];
                if (!isset($assignedUsersByTarget[$tKey])) {
                    $assignedUsersByTarget[$tKey] = [];
                }
                $assignedUsersByTarget[$tKey][] = $uid;
            }
        }

        // After collecting everything across orders, ensure totalPublishes matches
        // the publishList length (this keeps accounting accurate) and push
        // publishes in up to $maxBatches batches
        $totalPublishes = count($publishList);
        Log::info('[InsertAndPublishForActiveDashboardUsers] total publishes collected', ['total' => $totalPublishes]);

        // Final dedupe: ensure we never enqueue the same URL to the same user
        // more than once (canonicalize URL by trimming trailing slash).
        $deduped = [];
        $seenUrlUser = [];
        foreach ($publishList as $item) {
            $uid = intval($item['user_id']);
            $url = isset($item['payload']['url']) ? rtrim($item['payload']['url'], '/') : '';
            $key = $url . ':' . $uid;
            if (isset($seenUrlUser[$key])) {
                // skip duplicate
                continue;
            }
            $seenUrlUser[$key] = true;
            $deduped[] = $item;
        }
        $publishList = $deduped;

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
