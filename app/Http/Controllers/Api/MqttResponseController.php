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

        // Check if the action exists
        $action = DB::table('actions')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$action) {
            return response()->json([
                'success' => false,
                'message' => 'Action record not found.',
                'data' => $validated
            ], 404);
        }

        // Only increment done_count if status is changing to 'done' from something else
        $incrementDone = false;
        if ($action->status !== $status && $status === 'done' && $action->status !== 'done') {
            $incrementDone = true;
        }

        // Only update action and recalculate order if status actually changed
        if ($action->status !== $status) {
            // Update action status
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->update([
                    'status' => $status,
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);

            // Only recalculate if this change affects done count
            if ($incrementDone || $action->status === 'done') {
                // Recalculate the number of done actions and update done_count
                $doneCount = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('status', 'done')
                    ->count();

                // Get the order to check total_count
                $order = DB::table('orders')->select('id', 'total_count', 'status')->where('id', $orderId)->first();

                if ($order) {
                    $updateData = ['done_count' => $doneCount];

                    // If done_count equals total_count, mark order as completed
                    if ($doneCount >= $order->total_count && $order->status !== 'completed') {
                        $updateData['status'] = 'completed';
                    }

                    DB::table('orders')
                        ->where('id', $orderId)
                        ->update($updateData);
                }
            }
        } else {
            // Status didn't change, just update performed_at if needed
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where('performed_at', null)
                ->update([
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action status updated successfully.',
            'updated_rows' => $updated
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
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
            'type' => 'required|string|in:create,resume',
            'activation' => 'sometimes|boolean',
        ]);


        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $type = $validated['type'];
        $activation = $validated['activation'] ?? true;

        // Get the order and user (select only needed fields)
        $order = \App\Models\Order::select('id', 'total_count', 'done_count', 'status')->find($orderId);
        $user = \App\Models\User::select('id', 'type')->find($userId);


        if (!$order || !$user) {
            return response()->json([
                'success' => false,
                'message' => 'Order or user not found.'
            ], 404);
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
                $counts = DB::table('actions')
                    ->selectRaw("
                        SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_count,
                        SUM(CASE WHEN status = 'pending' AND created_at >= ? THEN 1 ELSE 0 END) as recent_pending_count
                    ")
                    ->where('order_id', $lockedOrder->id)
                    ->setBindings([now()->subMinutes(15)])
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
}
