<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\User;

class MqttResponseController extends Controller
{
    public function handle(Request $request)
    {
        // 🚀 DEBUG: Log every incoming request
        \Log::info("[MQTT_API] Request received", [
            'payload' => $request->all(),
            'ip' => $request->ip(),
            'timestamp' => now()->toDateTimeString()
        ]);

        $validated = $request->validate([
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
            'status' => 'required|in:done,external',
        ]);

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $status = $validated['status'];

        \Log::info("[MQTT_API] Processing", [
            'order_id' => $orderId,
            'user_id' => $userId,
            'status' => $status
        ]);

        try {
            // Use a transaction with a FOR UPDATE lock on the action row so concurrent
            // updates for the same (order_id,user_id) serialize and we avoid lost updates
            // or double increments of order.done_count.
            $autoCreate = env('MQTT_AUTO_CREATE_MISSING', false);
            $updated = 0;
            $created = false;

            DB::transaction(function () use (&$updated, &$created, $orderId, $userId, $status, $autoCreate) {
                // Attempt to lock the action row if it exists
                $action = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if ($action) {
                    // If action already done, nothing to do
                    if ($action->status === 'done') {
                        // leave $updated as 0 to indicate no change
                        return;
                    }

                    $updated = DB::table('actions')
                        ->where('order_id', $orderId)
                        ->where('user_id', $userId)
                        ->update([
                            'status' => $status,
                            'performed_at' => now(),
                            'updated_at' => now(),
                        ]);
                    return;
                }

                if ($autoCreate) {
                    // Try to infer type from the order record if available
                    $orderRow = DB::table('orders')->where('id', $orderId)->first();
                    $actionType = $orderRow->type ?? 'create';

                    DB::table('actions')->insert([
                        'order_id' => $orderId,
                        'user_id' => $userId,
                        'type' => $actionType,
                        'status' => $status,
                        'performed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created = true;
                    return;
                }

                // If we reach here: action doesn't exist and auto-create is disabled
                // leave $updated == 0 and $created == false so caller can return 404
            });

            \Log::info("[MQTT_API] Update result", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status,
                'rows_updated' => $updated,
                'created' => $created
            ]);

            if ($updated === 0 && !$created) {
                // verify whether action exists to give a helpful response
                $action = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->first();

                if (!$action) {
                    \Log::warning("[MQTT_API] Action not found", [
                        'order_id' => $orderId,
                        'user_id' => $userId
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Action record not found'
                    ], 404);
                }

                // Action exists but wasn't updated (likely already done)
                \Log::info("[MQTT_API] Action exists but not updated", [
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'current_status' => $action->status,
                    'requested_status' => $status
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Action already processed',
                    'current_status' => $action->status
                ]);
            }

            // If we updated a row or created one and the incoming status is 'done', increment order.done_count
            // This prevents double increments when the action was already done because we locked the row.
            if (($updated > 0 || $created) && $status === 'done') {
                DB::table('orders')
                    ->where('id', $orderId)
                    ->increment('done_count');

                \Log::info("[MQTT_API] Incremented done_count for order", ['order_id' => $orderId]);

                // Check if order should be marked as completed
                $order = DB::table('orders')
                    ->where('id', $orderId)
                    ->first(['done_count', 'total_count', 'status']);

                if ($order && $order->done_count >= $order->total_count && $order->status !== 'completed') {
                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update(['status' => 'completed']);

                    \Log::info("[MQTT_API] Order marked as completed", [
                        'order_id' => $orderId,
                        'done_count' => $order->done_count,
                        'total_count' => $order->total_count
                    ]);
                }
            }

            \Log::info("[MQTT_API] Success", [
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Action status updated successfully'
            ]);

        } catch (\Illuminate\Database\QueryException $ex) {
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
        // Log incoming trigger requests and correlate with mqtt_handler via message_id when present
        \Log::info('[MQTT_API] triggerOrder request received', [
            'payload' => $request->all(),
            'message_id' => $request->input('message_id')
        ]);

        $validated = $request->validate([
            'message_id' => 'sometimes|string',
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
            'type' => 'required|string|in:create,resume',
            'activation' => 'sometimes|boolean',
            'bulk_processing' => 'sometimes|boolean', // Support bulk processing flag
        ]);

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $type = $validated['type'];
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

        // Short transaction: only lock, check counts and decide if we can proceed.
        $canProceed = false;
        $lockedOrderSnapshot = null;

        try {
            DB::transaction(function () use ($order, &$canProceed, &$lockedOrderSnapshot) {
                // Lock the order row to prevent concurrent modifications (select minimal fields)
                $lockedOrder = \App\Models\Order::select('id', 'total_count', 'done_count')
                    ->where('id', $order->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedOrder) {
                    // not found under lock
                    return;
                }

                // Check remaining actions with fresh data (count done + recent pending only)
                $fifteenMinutesAgo = now()->subMinutes(15);
                $counts = DB::table('actions')
                    ->selectRaw("
                        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_count,
                        SUM(CASE WHEN status = 'pending' AND created_at >= ? THEN 1 ELSE 0 END) as recent_pending_count
                    ", [$fifteenMinutesAgo])
                    ->where('order_id', $lockedOrder->id)
                    ->first();

                $actualDoneCount = (int)$counts->done_count;
                $recentPendingCount = (int)$counts->recent_pending_count;

                $remaining = $lockedOrder->total_count - $actualDoneCount;
                $availableSlots = $lockedOrder->total_count - $actualDoneCount - $recentPendingCount;

                if ($remaining <= 0) {
                    // nothing to do, simply exit transaction
                    return;
                }

                if ($availableSlots <= 0) {
                    // no slots available
                    return;
                }

                // Safety check: ensure we don't exceed total_count by adding this validation
                // This acts as a final safeguard against race conditions
                if (($actualDoneCount + $recentPendingCount) >= $lockedOrder->total_count) {
                    // Already at capacity
                    return;
                }

                // take a lightweight snapshot to use outside transaction
                $lockedOrderSnapshot = [
                    'id' => $lockedOrder->id,
                    'total_count' => $lockedOrder->total_count,
                    'done_count' => $actualDoneCount,
                    'recent_pending_count' => $recentPendingCount,
                ];

                // mark allowed to proceed; do NOT call heavy services here
                $canProceed = true;
            });
        } catch (\Illuminate\Database\QueryException $ex) {
            // Handle lock wait timeout specifically
            // 1205 = lock wait timeout
            if (strpos($ex->getMessage(), '1205') !== false || strpos($ex->getMessage(), 'Lock wait timeout') !== false) {
                // Log and return a 409 so caller can retry later
                \Log::warning('[triggerOrder] Lock wait timeout while trying to lock order ' . $orderId);
                return response()->json(['error' => 'Database is busy, please retry.'], 409);
            }

            // rethrow other DB exceptions
            throw $ex;
        }

        if (!$canProceed || !$lockedOrderSnapshot) {
            return response()->json(['error' => 'No available slots or order not found.'], 400);
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
}
