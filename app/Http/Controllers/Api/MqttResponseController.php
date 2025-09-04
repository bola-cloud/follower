<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Order;
use App\Models\User;

class MqttResponseController extends Controller
{
    public function handle(Request $request)
    {
        // Check database connectivity first
        if (!$this->checkDatabaseConnectivity()) {
            \Log::error("[MQTT_API] Database connection failed, rejecting request");
            return response()->json(['error' => 'Database temporarily unavailable'], 503);
        }

        // 🚀 DEBUG: Log every incoming request
        // \Log::info("[MQTT_API] Request received", [
        //     'payload' => $request->all(),
        //     'ip' => $request->ip(),
        //     'timestamp' => now()->toDateTimeString()
        // ]);

        try {
            $validated = $request->validate([
                'order_id' => 'required|integer',
                'user_id' => 'required|integer',
                'status' => 'required|in:done,external',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::warning("[MQTT_API] Validation failed", [
                'payload' => $request->all(),
                'errors' => $e->errors()
            ]);
            return response()->json(['error' => 'Invalid request data'], 422);
        }

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $status = $validated['status'];

        // \Log::info("[MQTT_API] Processing", [
        //     'order_id' => $orderId,
        //     'user_id' => $userId,
        //     'status' => $status
        // ]);

        // 🚀 QUEUE ACTIONS: Store actions for batch processing to prevent database overload
        if (env('MQTT_USE_QUEUE', true)) {
            return $this->queueActionForProcessing($orderId, $userId, $status, $request);
        }

        try {
            // 🚀 OPTIMIZED: Use UPDATE with WHERE conditions to handle race conditions
            // This avoids locks but prevents duplicate updates and lost increments
            $autoCreate = env('MQTT_AUTO_CREATE_MISSING', false);
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

                    $created = DB::table('actions')->insertOrIgnore([
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'type' => $actionType,
                        'status' => $status,
                        'performed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($existingAction && $existingAction->status === 'done') {
                    // Action already completed, this is fine
                    $updated = 1; // Treat as successful update to proceed with done_count increment
                }
            }

            // \Log::info("[MQTT_API] Update result", [
            //     'order_id' => $orderId,
            //     'user_id' => $userId,
            //     'status' => $status,
            //     'rows_updated' => $updated,
            //     'created' => $created
            // ]);

            if ($updated === 0 && !$created) {
                // verify whether action exists to give a helpful response
                $action = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->first();

                if (!$action) {
                    // \Log::warning("[MQTT_API] Action not found", [
                    //     'order_id' => $orderId,
                    //     'user_id' => $userId
                    // ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Action record not found'
                    ], 404);
                }

                // Action exists but wasn't updated (likely already done)
                // \Log::info("[MQTT_API] Action exists but not updated", [
                //     'order_id' => $orderId,
                //     'user_id' => $userId,
                //     'current_status' => $action->status,
                //     'requested_status' => $status
                // ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Action already processed',
                    'current_status' => $action->status
                ]);
            }

            // If we updated a row or created one and the incoming status is 'done', increment order.done_count
            // Use increment() which is atomic and handles concurrent updates safely
            if (($updated > 0 || $created) && $status === 'done') {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->increment('done_count');

                // \Log::info("[MQTT_API] Incremented done_count for order", ['order_id' => $orderId]);

                // Check if order should be marked as completed (use fresh data)
                $order = DB::table('orders')
                    ->where('id', $orderId)
                    ->first(['done_count', 'total_count', 'status']);

                if ($order && $order->done_count >= $order->total_count && $order->status !== 'completed') {
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->where('status', '!=', 'completed') // Prevent race condition
                        ->update(['status' => 'completed']);

                    // \Log::info("[MQTT_API] Order marked as completed", [
                    //     'order_id' => $orderId,
                    //     'done_count' => $order->done_count,
                    //     'total_count' => $order->total_count
                    // ]);
                }
            }

            // \Log::info("[MQTT_API] Success", [
            //     'order_id' => $orderId,
            //     'user_id' => $userId,
            //     'status' => $status
            // ]);

            return response()->json([
                'success' => true,
                'message' => 'Action status updated successfully'
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

        return response()->json($result);
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

            // Store in cache queue for batch processing
            $cacheKey = 'mqtt_actions_queue';
            $existing = \Cache::get($cacheKey, []);
            $existing[] = $actionData;
            
            // Keep only recent actions (last 1000)
            if (count($existing) > 1000) {
                $existing = array_slice($existing, -1000);
            }
            
            \Cache::put($cacheKey, $existing, now()->addHours(1));

            \Log::info("[MQTT_API] Action queued for batch processing", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'queue_size' => count($existing)
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
                    'updated_at' => now(),
                ]);

            if ($updated > 0 && $status === 'done') {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->increment('done_count');
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
}
