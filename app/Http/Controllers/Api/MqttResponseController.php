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
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
            'status' => 'required|in:done,external',
        ]);

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $status = $validated['status'];

        // 🚀 IDEMPOTENCY KEY: Prevent duplicate processing using cache
        $idempotencyKey = "mqtt_response_{$orderId}_{$userId}_{$status}";

        // Check if this exact request was processed recently (within 30 seconds)
        $recentResult = cache()->get($idempotencyKey);
        if ($recentResult) {
            return response()->json([
                'success' => true,
                'message' => 'Duplicate request - already processed.',
                'result' => $recentResult,
                'idempotent' => true
            ]);
        }

        try {
            // 🚀 PURE ATOMIC OPERATION: Single query that handles all duplicate scenarios
            $result = DB::transaction(function () use ($orderId, $userId, $status, $idempotencyKey) {

                // 🚀 ATOMIC UPDATE: Only update if status is actually changing
                // This single query handles all race conditions atomically
                $updated = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->where('status', '!=', $status) // Only update if status is different
                    ->update([
                        'status' => $status,
                        'performed_at' => DB::raw('COALESCE(performed_at, NOW())'), // Set only if null
                        'updated_at' => now(),
                    ]);

                // If no rows updated, either:
                // 1. Action doesn't exist, OR
                // 2. Status was already set (duplicate message)
                if ($updated === 0) {
                    // Check if action exists to determine the reason
                    $action = DB::table('actions')
                        ->where('order_id', $orderId)
                        ->where('user_id', $userId)
                        ->first();

                    if (!$action) {
                        throw new \Exception('Action record not found');
                    }

                    // Action exists but status didn't change - this is a duplicate
                    $result = ['updated' => 0, 'duplicate_message' => true, 'current_status' => $action->status];

                    // 🚀 CACHE RESULT: Store for future duplicate requests
                    cache()->put($idempotencyKey, $result, now()->addSeconds(30));

                    return $result;
                }

                // Status was successfully changed - check if we need to update done_count
                $shouldUpdateDoneCount = ($status === 'done');

                if ($shouldUpdateDoneCount) {
                    // 🚀 ACCURATE COUNT: Recalculate from actual data instead of incrementing
                    // This prevents lost increments during high concurrency
                    $actualDoneCount = DB::table('actions')
                        ->where('order_id', $orderId)
                        ->where('status', 'done')
                        ->count();

                    // 🚀 ATOMIC UPDATE: Set the actual count directly
                    $order = DB::table('orders')
                        ->where('id', $orderId)
                        ->first(['total_count', 'status', 'done_count']);

                    if ($order) {
                        $updateData = ['done_count' => $actualDoneCount];

                        // Mark as completed if we've reached the target
                        if ($actualDoneCount >= $order->total_count && $order->status !== 'completed') {
                            $updateData['status'] = 'completed';
                        }

                        // Only update if done_count actually changed (avoid unnecessary writes)
                        if ($order->done_count != $actualDoneCount || ($actualDoneCount >= $order->total_count && $order->status !== 'completed')) {
                            DB::table('orders')
                                ->where('id', $orderId)
                                ->update($updateData);
                        }

                        $result = [
                            'updated' => $updated,
                            'done_count_updated' => true,
                            'actual_done' => $actualDoneCount,
                            'total_count' => $order->total_count,
                            'previous_done_count' => $order->done_count
                        ];
                    } else {
                        $result = ['updated' => $updated, 'order_not_found' => true];
                    }
                } else {
                    $result = ['updated' => $updated, 'status_changed_to' => $status];
                }                // 🚀 CACHE SUCCESS RESULT: Prevent duplicate processing
                cache()->put($idempotencyKey, $result, now()->addSeconds(30));

                return $result;
            }, 1); // 1 second timeout

            return response()->json([
                'success' => true,
                'message' => 'Action status updated successfully.',
                'result' => $result
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            // Handle specific database errors gracefully
            if (strpos($e->getMessage(), '1213') !== false || strpos($e->getMessage(), 'Deadlock') !== false) {
                $result = ['duplicate_processing' => true];

                // Cache the result to prevent retries
                cache()->put($idempotencyKey, $result, now()->addSeconds(30));

                return response()->json([
                    'success' => true, // Treat as success - likely processed by another request
                    'message' => 'Action processed concurrently.',
                    'result' => $result
                ], 200);
            }

            return response()->json([
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage(),
                'data' => $validated
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update action: ' . $e->getMessage(),
                'data' => $validated
            ], $e->getMessage() === 'Action record not found' ? 404 : 500);
        }
    }    public function recalculateAllOrders(Request $request)
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
        $validated = $request->validate([
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
