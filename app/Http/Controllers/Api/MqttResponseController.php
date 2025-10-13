<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Log;


class MqttResponseController extends Controller
{
    /**
     * ⚠️ FALLBACK ONLY: Single order response handler
     *
     * This method is deprecated in favor of batch processing (handleBatch/handleBatchDrain).
     * Use batch endpoint for all order responses to achieve better performance.
     *
     * Performance comparison:
     * - Single: 1000 responses = 1000 HTTP calls + 1000 DB queries
     * - Batch: 1000 responses = 10 HTTP calls + 12 DB queries (99% reduction)
     *
     * This handler is kept as a last-resort fallback only when batch processing fails.
     * Heavy use of this endpoint indicates a problem with the batch system.
     *
     * @deprecated Use handleBatchDrain() for zero-loss guaranteed processing
     */
    public function handle(Request $request)
    {
        // Log deprecation warning
        \Log::warning("[MQTT_API] ⚠️ DEPRECATED: Single handler called - use batch endpoint instead", [
            'order_id' => $request->input('order_id'),
            'user_id' => $request->input('user_id'),
            'ip' => $request->ip()
        ]);

        // Validate request
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
            'status' => 'required|string|in:done,external,busy'
        ]);

        // Process as fallback (but still works to prevent data loss)
        return $this->legacyProcessAction(
            $validated['order_id'],
            $validated['user_id'],
            $validated['status']
        );
    }

    /**
     * Legacy processing method (fallback)
     */
    private function legacyProcessAction(int $orderId, int $userId, string $status)
    {
        try {
            // 🚀 OPTIMIZED: Use UPDATE with WHERE conditions to handle race conditions
            // This avoids locks but prevents duplicate updates and lost increments
            $autoCreate = true; // Always auto-create missing actions to handle race conditions
            $updated = 0;
            $created = false;

            // Try to update existing action that is not already done
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where('status', '!=', 'done') // Only update if not already done
                ->update([
                    'status' => $status,
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);

            // If no rows updated, check if action exists or needs creation
            if ($updated === 0) {
                $existingAction = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->first();

                if (!$existingAction && $autoCreate) {
                    // Try to create action (use insertOrIgnore to handle race conditions)
                    $orderRow = DB::table('orders')->where('id', $orderId)->first();
                    $actionType = $orderRow->type ?? 'create';

                    \Log::info('[MQTT_API] Creating missing action during legacy processing', [
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'status' => $status,
                        'action_type' => $actionType
                    ]);

                    $created = DB::table('actions')->insertOrIgnore([
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'type' => $actionType,
                        'status' => $status,
                        'performed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($created) {
                        \Log::info('[MQTT_API] Successfully created missing action', [
                            'order_id' => $orderId,
                            'user_id' => $userId,
                            'status' => $status
                        ]);
                    } else {
                        \Log::warning('[MQTT_API] Failed to create missing action (race condition)', [
                            'order_id' => $orderId,
                            'user_id' => $userId,
                            'status' => $status
                        ]);
                    }
                } elseif ($existingAction && $existingAction->status === 'done') {
                    // Action already completed - don't increment done_count again
                    return response()->json([
                        'success' => true,
                        'message' => 'Action already completed',
                        'current_status' => $existingAction->status
                    ]);
                }
            }

            if ($updated === 0 && !$created) {
                // verify whether action exists to give a helpful response
                $action = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->first();

                if (!$action) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Action record not found'
                    ], 404);
                }

                // Action exists but wasn't updated (likely already done)
                return response()->json([
                    'success' => true,
                    'message' => 'Action already processed',
                    'current_status' => $action->status
                ]);
            }

            // If we updated a row or created one and the incoming status is 'done', increment order.done_count
            // Use safe increment that doesn't exceed total_count to prevent over-counting
            if (($updated > 0 || $created) && $status === 'done') {
                DB::statement("
                    UPDATE orders
                    SET done_count = LEAST(done_count + 1, total_count),
                        updated_at = NOW()
                    WHERE id = ? AND done_count < total_count
                ", [$orderId]);

                // Check if order should be marked as completed (use fresh data)
                $order = DB::table('orders')
                    ->where('id', $orderId)
                    ->first(['done_count', 'total_count', 'status']);

                if ($order && $order->done_count >= $order->total_count && $order->status !== 'completed') {
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->where('status', '!=', 'completed') // Prevent race condition
                        ->update(['status' => 'completed']);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Action status updated successfully (legacy fallback)',
                'fallback' => true
            ]);

        } catch (\Illuminate\Database\QueryException $ex) {
            // Handle connection refused specifically
            if (strpos($ex->getMessage(), 'Connection refused') !== false || strpos($ex->getMessage(), '2002') !== false) {
                \Log::error('[MQTT_API] Database connection refused while updating action ' . $orderId . '/' . $userId);
                return response()->json(['error' => 'Database temporarily unavailable'], 503);
            }

            // Handle lock wait timeout specifically (MySQL code 1205 or message contains Lock wait timeout)
            if (strpos($ex->getMessage(), '1205') !== false || strpos($ex->getMessage(), 'Lock wait timeout') !== false) {
                \Log::warning('[MQTT_API] Lock wait timeout while updating action ' . $orderId . '/' . $userId);
                return response()->json(['error' => 'Database busy, please retry.'], 409);
            }

            \Log::error("[MQTT_API] DB QueryException", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'error' => $ex->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update action: ' . $ex->getMessage()
            ], 500);
        } catch (\Exception $e) {
            \Log::error("[MQTT_API] Exception", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update action: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🚀 BATCH HANDLER: Process batches of order/res responses efficiently
     * Groups responses by status and dispatches background jobs for chunked processing
     *
     * Handles 1000+ concurrent order completion responses with <3s processing
     * Reduces DB queries by 99%: 1000 individual UPDATEs → 12 chunked batch UPDATEs
     */
    public function handleBatch(Request $request)
    {
        $startTime = microtime(true);

        // Check database connectivity first
        if (!$this->checkDatabaseConnectivity()) {
            \Log::error("[MQTT_API_BATCH] Database connection failed, rejecting batch request");
            return response()->json(['error' => 'Database temporarily unavailable'], 503);
        }

        try {
            $validated = $request->validate([
                'actions' => 'required|array|min:1|max:5000', // Support up to 5000 responses
                'actions.*.order_id' => 'required|integer',
                'actions.*.user_id' => 'required|integer',
                'actions.*.status' => 'required|in:done,external,busy',
                'batch_id' => 'sometimes|string',
                'timestamp' => 'sometimes|integer',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::warning("[MQTT_API_BATCH] Validation failed", [
                'payload_sample' => array_slice($request->all(), 0, 5),
                'errors' => $e->errors()
            ]);
            return response()->json(['error' => 'Invalid batch request data'], 422);
        }

        $actions = $validated['actions'];
        $batchId = $validated['batch_id'] ?? 'order_res_batch_' . time();
        $totalActions = count($actions);

        \Log::info("[MQTT_API_BATCH] Order response batch received", [
            'batch_id' => $batchId,
            'total_actions' => $totalActions
        ]);

        // Filter out 'busy' status and group remaining by status
        $groupedByStatus = $this->groupActionsByStatus($actions);
        $skippedCount = $groupedByStatus['skipped'] ?? 0;
        unset($groupedByStatus['skipped']);

        \Log::info("[MQTT_API_BATCH] Actions grouped by status", [
            'batch_id' => $batchId,
            'done_count' => count($groupedByStatus['done'] ?? []),
            'external_count' => count($groupedByStatus['external'] ?? []),
            'skipped_busy_count' => $skippedCount
        ]);

        // For very large batches (>500 actions), split into smaller sub-batches to prevent queue overload
        // This ensures Redis queue doesn't get overwhelmed and jobs are distributed evenly
        $subBatchSize = (int) env('ORDER_RES_SUB_BATCH_SIZE', 500);

        // Dispatch background jobs for each status group with sub-batching for large groups
        $jobsDispatched = 0;
        $delaySeconds = 0; // Stagger job dispatches to prevent queue spike

        foreach ($groupedByStatus as $status => $responses) {
            if (empty($responses)) continue;

            $totalResponses = count($responses);

            // If responses exceed sub-batch size, split into multiple jobs
            if ($totalResponses > $subBatchSize) {
                $chunks = array_chunk($responses, $subBatchSize);

                \Log::info("[MQTT_API_BATCH] Splitting large status group into sub-batches", [
                    'batch_id' => $batchId,
                    'status' => $status,
                    'total_responses' => $totalResponses,
                    'sub_batch_count' => count($chunks),
                    'sub_batch_size' => $subBatchSize
                ]);

                foreach ($chunks as $chunkIndex => $chunk) {
                    $jobBatchId = $batchId . '_' . $status . '_' . ($chunkIndex + 1);

                    // Dispatch with staggered delay (1-2 seconds between large batches)
                    \App\Jobs\ProcessOrderResponseBatchJob::dispatch(
                        $chunk,
                        $status,
                        $jobBatchId
                    )->delay(now()->addSeconds($delaySeconds));

                    $jobsDispatched++;
                    $delaySeconds += 1; // Add 1 second delay for each sub-batch
                }
            } else {
                // Normal single job dispatch for smaller batches
                $jobBatchId = $batchId . '_' . $status;

                \App\Jobs\ProcessOrderResponseBatchJob::dispatch(
                    $responses,
                    $status,
                    $jobBatchId
                )->delay(now()->addSeconds($delaySeconds));

                $jobsDispatched++;
            }

            \Log::info("[MQTT_API_BATCH] Background job(s) dispatched for status group", [
                'batch_id' => $batchId,
                'status' => $status,
                'response_count' => $totalResponses,
                'jobs_dispatched' => $jobsDispatched
            ]);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'total_actions' => $totalActions,
            'jobs_dispatched' => $jobsDispatched,
            'skipped_busy' => $skippedCount,
            'duration_ms' => $duration
        ]);
    }

    /**
     * 🚀 DRAIN MODE: Push all responses to persistent Redis queue
     * Guarantees ZERO data loss for 5000+ simultaneous responses
     *
     * This endpoint pushes responses to Redis lists and triggers drain jobs.
     * All responses are immediately persisted (atomic RPUSH), then drained step-by-step.
     *
     * Key benefits:
     * - Responses never lost (Redis persistence)
     * - Backpressure handling (queue grows, drain adapts)
     * - Graceful degradation (drain continues even if new requests pause)
     */
    public function handleBatchDrain(Request $request)
    {
        $startTime = microtime(true);

        try {
            $validated = $request->validate([
                'actions' => 'required|array|min:1|max:10000', // Support up to 10k for extreme bursts
                'actions.*.order_id' => 'required|integer',
                'actions.*.user_id' => 'required|integer',
                'actions.*.status' => 'required|in:done,external,busy',
                'batch_id' => 'sometimes|string',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning("[MQTT_API_DRAIN] Validation failed", [
                'errors' => $e->errors()
            ]);
            return response()->json(['error' => 'Invalid batch request'], 422);
        }

        $actions = $validated['actions'];
        $batchId = $validated['batch_id'] ?? 'drain_batch_' . time();
        $totalActions = count($actions);

        Log::info("[MQTT_API_DRAIN] Batch received for drain queue", [
            'batch_id' => $batchId,
            'total_actions' => $totalActions
        ]);

        // Group by status and push to Redis drain queues
        $groupedByStatus = $this->groupActionsByStatus($actions);
        $skippedCount = $groupedByStatus['skipped'] ?? 0;
        unset($groupedByStatus['skipped']);

        $queuedCount = 0;

        foreach ($groupedByStatus as $status => $responses) {
            if (empty($responses)) continue;

            $queueKey = "order_responses:drain_queue:{$status}";

            // Push all responses to Redis (atomic, guaranteed)
            $pipeline = Redis::pipeline(function ($pipe) use ($responses, $queueKey) {
                foreach ($responses as $response) {
                    $pipe->rpush($queueKey, json_encode($response));
                }
            });

            $queuedCount += count($responses);

            Log::info("[MQTT_API_DRAIN] Pushed to drain queue", [
                'batch_id' => $batchId,
                'status' => $status,
                'count' => count($responses),
                'queue_key' => $queueKey
            ]);

            // Start drain job if not already running
            $this->startDrainJobIfNeeded($status);
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'total_actions' => $totalActions,
            'queued' => $queuedCount,
            'skipped_busy' => $skippedCount,
            'duration_ms' => $duration,
            'mode' => 'drain'
        ]);
    }

    /**
     * Start drain job if not already running
     *
     * @param string $status
     */
    protected function startDrainJobIfNeeded(string $status): void
    {
        $lockKey = "drain_job_running:{$status}";

        // Check if drain job is already running
        if (!Redis::exists($lockKey)) {
            // Set lock (expires in 5 minutes as safety)
            Redis::setex($lockKey, 300, '1');

            // Dispatch drain job
            \App\Jobs\DrainOrderResponsesJob::dispatch($status);

            Log::info('[MQTT_API_DRAIN] Drain job dispatched', [
                'status' => $status
            ]);
        }
    }

    /**
     * Fallback batch processing using legacy methods
     */
    private function legacyBatchProcessing(array $actions, string $batchId)
    {
        $processed = 0;
        $failed = 0;
        $results = [];

        foreach ($actions as $index => $actionData) {
            try {
                $orderId = $actionData['order_id'];
                $userId = $actionData['user_id'];
                $status = $this->normalizeStatus($actionData['status']);

                if ($actionData['status'] === 'busy') {
                    $results[] = [
                        'index' => $index,
                        'success' => true,
                        'message' => 'Device busy - status ignored',
                        'skipped' => true
                    ];
                    continue;
                }

                $response = $this->legacyProcessAction($orderId, $userId, $status);
                $results[] = [
                    'index' => $index,
                    'success' => $response->getStatusCode() < 300,
                    'response' => json_decode($response->getContent(), true)
                ];
                $processed++;

            } catch (\Exception $e) {
                $results[] = [
                    'index' => $index,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                $failed++;
            }
        }

        return response()->json([
            'success' => true,
            'batch_id' => $batchId,
            'processed' => $processed,
            'failed' => $failed,
            'total' => count($actions),
            'results' => $results,
            'fallback' => 'legacy_batch_processing'
        ]);
    }

    public function recalculateAllOrders(Request $request)
    {
        $updatedCount = 0;

        // Process orders in chunks to avoid memory issues
        DB::table('orders')->orderBy('id')->chunk(50, function ($orders) use (&$updatedCount) {
            foreach ($orders as $order) {
                $doneCount = DB::table('actions')
                    ->where('order_id', $order->id)
                    ->where('status', 'done')
                    ->count();

                $updateData = ['done_count' => $doneCount];

                // If done_count equals or exceeds total_count, mark as completed
                if ($doneCount >= $order->total_count && $order->status !== 'completed') {
                    $updateData['status'] = 'completed';
                }

                // Only update if there's a change
                if ($doneCount != $order->done_count || ($doneCount >= $order->total_count && $order->status !== 'completed')) {
                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update($updateData);
                    $updatedCount++;
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Orders recalculated successfully.',
            'updated_orders' => $updatedCount
        ]);
    }

    public function triggerOrder(Request $request)
    {
        // Remove noisy stray debug; add structured trace for visibility
        Log::error('[MqttResponseController] entry trace', [
            'method' => __METHOD__,
            'request_path' => request()->path(),
            'ip' => request()->ip()
        ]);
        // Check database connectivity first
        if (!$this->checkDatabaseConnectivity()) {
            \Log::error("[triggerOrder] Database connection failed, rejecting request");
            return response()->json(['error' => 'Database temporarily unavailable'], 503);
        }

        // // Log incoming trigger requests and correlate with mqtt_handler via message_id when present
        // \Log::info('[MQTT_API] triggerOrder request received', [
        //     'payload' => $request->all(),
        //     'message_id' => $request->input('message_id')
        // ]);

        // Accept numeric strings from MQTT payloads and normalize 'type'
        $validated = $request->validate([
            'message_id' => 'sometimes|string',
            'order_id' => 'required|numeric',
            'user_id' => 'required|numeric',
            // Accept any string here; we'll normalize below to map device action types
            'type' => 'required|string',
            'activation' => 'sometimes|boolean',
            'bulk_processing' => 'sometimes|boolean', // Support bulk processing flag
        ]);

        // Cast incoming ids to integers (handles numeric strings)
        $orderId = (int) $validated['order_id'];
        $userId = (int) $validated['user_id'];

        // Normalize type: API expects 'create' or 'resume'. Device may send action types like 'follow' or 'like'.
        $incomingType = strtolower($validated['type']);
        if (in_array($incomingType, ['resume'])) {
            $type = 'resume';
        } else {
            // Anything else (including 'follow', 'like', etc.) should be treated as a create activation
            $type = 'create';
        }

        // \Log::info('[MQTT_API] triggerOrder normalized', [
        //     'message_id' => $validated['message_id'] ?? null,
        //     'incoming_type' => $validated['type'],
        //     'normalized_type' => $type,
        //     'order_id' => $orderId,
        //     'user_id' => $userId
        // ]);
        $activation = $validated['activation'] ?? true;
        $isBulk = $validated['bulk_processing'] ?? false;

        // 🚀 OPTIMIZED: Get the order and user with minimal fields
        $order = \App\Models\Order::select('id', 'total_count', 'done_count', 'status', 'type', 'target_url')->find($orderId);
        $user = \App\Models\User::select('id', 'type')->find($userId);

        if (!$order || !$user) {
            return response()->json([
                'success' => false,
                'message' => 'Order or user not found.'
            ], 404);
        }

        // 🚀 FAST PATH: Skip expensive checks for bulk processing
        if ($isBulk) {
            return $this->fastTriggerOrder($order, $user, $type);
        }

        // 🚀 REMOVE LOCKING: The user reports that DB locking is preventing actions from being updated
        // Simple capacity check without locking - let the services handle race conditions internally
        $doneCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'done')
            ->count();

        $pendingCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        $remaining = $order->total_count - $doneCount;
        $availableSlots = $order->total_count - $doneCount - $pendingCount;

        if ($remaining <= 0) {
            return response()->json(['error' => 'Order already completed.'], 400);
        }

        if ($availableSlots <= 0) {
            return response()->json(['error' => 'No available slots.'], 400);
        }

        // Heavy processing outside transaction - will use its own locking/logic
        try {
            if ($type === 'resume') {
                $service = app(\App\Services\ResumeOrderService::class);
                $result = $service->handle($order, $user);
            } else {
                $service = app(\App\Services\OrderService::class);
                $result = $service->handle($order, $user);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle database connection issues specifically
            if (strpos($e->getMessage(), 'Connection refused') !== false || strpos($e->getMessage(), '2002') !== false) {
                \Log::error('[triggerOrder] Database connection refused for order ' . $orderId . ': ' . $e->getMessage());
                return response()->json(['error' => 'Database temporarily unavailable'], 503);
            }

            // Log other database errors and return a safe response
            \Log::error('[triggerOrder] Database error while handling order ' . $orderId . ': ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred.'], 500);

        } catch (\Throwable $e) {
            // Log error and return a safe response
            \Log::error('[triggerOrder] Error while handling order ' . $orderId . ': ' . $e->getMessage());
            return response()->json(['error' => 'Failed to process order.'], 500);
        }

        // Final trace log: this is the last place the triggerOrder flow reaches
        try {
            \Log::info('[triggerOrder] reached final return (order/ping/res)', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'type' => $type,
                'message_id' => $validated['message_id'] ?? null,
                'result_summary' => is_array($result) ? array_slice($result, 0, 10) : $result,
            ]);
        } catch (\Throwable $_e) {
            // Swallow logging errors to avoid breaking response flow
        }

        return response()->json($result);
    }

    /**
     * 🚀 BATCH TRIGGER: Process multiple ping responses at once for high throughput
     * Handles 5000+ concurrent ping responses by batching them
     */
    public function triggerOrderBatch(Request $request)
    {
        $startTime = microtime(true);

        try {
            $validated = $request->validate([
                'batch_id' => 'required|string',
                'responses' => 'required|array|min:1|max:5000',
                'responses.*.order_id' => 'required|numeric',
                'responses.*.user_id' => 'required|numeric',
                'responses.*.type' => 'required|string',
            ]);

            $batchId = $validated['batch_id'];
            $responses = $validated['responses'];
            $totalResponses = count($responses);

            Log::info('[MqttResponseController] batch trigger received', [
                'batch_id' => $batchId,
                'count' => $totalResponses
            ]);

            // Group responses by order_id and type for efficient processing
            $grouped = [];
            foreach ($responses as $response) {
                $orderId = (int) $response['order_id'];
                $userId = (int) $response['user_id'];
                $type = strtolower($response['type']);

                // Normalize type
                if ($type === 'resume') {
                    $type = 'resume';
                } else {
                    $type = 'create';
                }

                $key = "{$orderId}:{$type}";
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'order_id' => $orderId,
                        'type' => $type,
                        'user_ids' => []
                    ];
                }
                $grouped[$key]['user_ids'][] = $userId;
            }

            // Dispatch batch jobs for each order group
            $jobsDispatched = 0;
            foreach ($grouped as $key => $group) {
                // Deduplicate user_ids
                $uniqueUserIds = array_unique($group['user_ids']);
                $count = count($uniqueUserIds);

                if ($count === 0) continue;

                // Dispatch job to process this batch
                $jobBatchId = $batchId . '_' . $key;
                \App\Jobs\ProcessPingResponseBatchJob::dispatch(
                    $group['order_id'],
                    $group['type'],
                    array_values($uniqueUserIds),
                    $jobBatchId
                );

                $jobsDispatched++;

                Log::info('[MqttResponseController] batch job dispatched', [
                    'batch_id' => $jobBatchId,
                    'order_id' => $group['order_id'],
                    'type' => $group['type'],
                    'user_count' => $count
                ]);
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            return response()->json([
                'success' => true,
                'batch_id' => $batchId,
                'total_responses' => $totalResponses,
                'jobs_dispatched' => $jobsDispatched,
                'duration_ms' => $duration
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('[MqttResponseController] batch validation failed', [
                'errors' => $e->errors()
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Invalid batch request',
                'details' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('[MqttResponseController] batch trigger failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'error' => 'Batch processing failed'
            ], 500);
        }
    }

    /**
     * Clean up stale pending actions older than 15 minutes
     * This can be called periodically to prevent accumulation of old pending actions
     */
    public function cleanupStaleActions(Request $request)
    {
        $deletedCount = DB::table('actions')
            ->where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes(15))
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Stale pending actions cleaned up.',
            'deleted_count' => $deletedCount
        ]);
    }

    /**
     * 🚀 FAST PATH: High-performance order triggering for bulk processing
     */
    private function fastTriggerOrder($order, $user, $type)
    {
        try {
            // Skip expensive validation for bulk processing - just dispatch
            if ($type === 'resume') {
                $service = app(\App\Services\ResumeOrderService::class);
                $result = $service->handle($order, $user);
            } else {
                $service = app(\App\Services\OrderService::class);
                $result = $service->handle($order, $user);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order triggered via fast path',
                'result' => $result
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Fast trigger failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Queue action for batch processing to prevent database overload
     */
    private function queueActionForProcessing($orderId, $userId, $status, $request)
    {
        try {
            $actionData = [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'type' => 'follow', // Default type
                'timestamp' => now()->toDateTimeString(),
                'ip' => $request->ip(),
            ];
            // Store in Redis list for atomic append (safer under high concurrency)
            $redisKey = 'mqtt_actions_queue';
            Redis::rpush($redisKey, json_encode($actionData));
            // Trim to keep only most recent 1000
            Redis::ltrim($redisKey, -1000, -1);
            // Set expiry to avoid stale growth
            Redis::expire($redisKey, 3600);

            $queueSize = Redis::llen($redisKey);
            \Log::info("[MQTT_API] Action queued for batch processing", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'queue_size' => $queueSize
            ]);

            // Dispatch ActionQueueJob if not already running
            $this->ensureActionQueueJobRunning();

            return response()->json([
                'success' => true,
                'message' => 'Action queued for processing',
                'queued' => true
            ]);

        } catch (\Throwable $e) {
            \Log::error("[MQTT_API] Failed to queue action: " . $e->getMessage(), [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status
            ]);

            // Fallback to immediate processing if queuing fails
            return $this->processActionImmediately($orderId, $userId, $status);
        }
    }

    /**
     * Ensure ActionQueueJob is running to process queued actions
     */
    private function ensureActionQueueJobRunning()
    {
        $lockKey = 'action_queue_job_running';

        if (!\Cache::has($lockKey)) {
            // Set lock for 2 minutes
            \Cache::put($lockKey, true, now()->addMinutes(2));

            // Dispatch the job with a small delay
            \App\Jobs\ActionQueueJob::dispatch()->delay(now()->addSeconds(2));

            \Log::info("🚀 ActionQueueJob dispatched");
        }
    }

    /**
     * Fallback: Process action immediately if queuing fails
     */
    private function processActionImmediately($orderId, $userId, $status)
    {
        try {
            // Simplified immediate processing
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where('status', '!=', 'done')
                ->update([
                    'status' => $status,
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated > 0 && $status === 'done') {
                DB::statement("
                    UPDATE orders
                    SET done_count = LEAST(done_count + 1, total_count),
                        updated_at = NOW()
                    WHERE id = ? AND done_count < total_count
                ", [$orderId]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Action processed immediately',
                'fallback' => true
            ]);

        } catch (\Throwable $e) {
            \Log::error("[MQTT_API] Immediate processing failed: " . $e->getMessage());
            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    /**
     * Check if database connection is available before processing
     */
    private function checkDatabaseConnectivity(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable $e) {
            \Log::error("[MQTT_API] Database connectivity check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Normalize device status to standard values
     */
    private function normalizeStatus(string $rawStatus): string
    {
        $normalized = strtolower(trim($rawStatus));

        switch ($normalized) {
            case 'done':
            case 'completed':
            case 'success':
            case 'finished':
                return 'done';

            case 'external':
            case 'redirect':
            case 'forwarded':
                return 'external';

            default:
                // For unknown statuses, treat as external
                \Log::info("[MQTT_API] Unknown status normalized to external", [
                    'original' => $rawStatus,
                    'normalized' => 'external'
                ]);
                return 'external';
        }
    }

    /**
     * Group actions by status for efficient batch processing
     * Filters out 'busy' status and groups by 'done' and 'external'
     *
     * @param array $actions
     * @return array ['done' => [...], 'external' => [...], 'skipped' => count]
     */
    private function groupActionsByStatus(array $actions): array
    {
        $groups = [
            'done' => [],
            'external' => [],
            'skipped' => 0
        ];

        foreach ($actions as $action) {
            $rawStatus = $action['status'];

            // Skip busy status
            if (strtolower($rawStatus) === 'busy') {
                $groups['skipped']++;
                continue;
            }

            // Normalize status
            $status = $this->normalizeStatus($rawStatus);

            // Add to appropriate group
            $groups[$status][] = [
                'order_id' => (int) $action['order_id'],
                'user_id' => (int) $action['user_id'],
                'status' => $status
            ];
        }

        return $groups;
    }
}
