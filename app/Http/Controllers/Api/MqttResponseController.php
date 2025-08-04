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

        // Update action status
        $updated = DB::table('actions')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->update(['status' => $status]);


        // Recalculate the number of done actions and update done_count to avoid duplicates
        $doneCount = DB::table('actions')
            ->where('order_id', $orderId)
            ->where('status', 'done')
            ->count();

        // Get the order to check total_count
        $order = DB::table('orders')->where('id', $orderId)->first();

        if ($order) {
            $updateData = ['done_count' => $doneCount];

            // If done_count equals total_count, mark order as completed
            if ($doneCount >= $order->total_count) {
                $updateData['status'] = 'completed';
            }

            DB::table('orders')
                ->where('id', $orderId)
                ->update($updateData);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action status updated successfully.',
            'updated_rows' => $updated
        ]);
    }

    public function recalculateAllOrders(Request $request)
    {
        $orders = DB::table('orders')->get();
        $updatedCount = 0;

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

        return response()->json([
            'success' => true,
            'message' => 'Orders recalculated successfully.',
            'updated_orders' => $updatedCount
        ]);
    }

    public function triggerOrder(Request $request)
    {
        \Log::info('[triggerOrder] Incoming request', $request->all());
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
            'type' => 'required|string|in:create,resume',
            'activation' => 'sometimes|boolean',
        ]);

        \Log::info('[triggerOrder] Validated data', $validated);

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];
        $type = $validated['type'];
        $activation = $validated['activation'] ?? true;

        // Get the order and user
        $order = \App\Models\Order::find($orderId);
        $user = \App\Models\User::find($userId);

        \Log::info('[triggerOrder] Order found', ['order' => $order]);
        \Log::info('[triggerOrder] User found', ['user' => $user]);

        if (!$order || !$user) {
            \Log::error('[triggerOrder] Order or user not found', ['order_id' => $orderId, 'user_id' => $userId]);
            return response()->json([
                'success' => false,
                'message' => 'Order or user not found.'
            ], 404);
        }

        // Check remaining actions for the order
        $actualDoneCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'done')
            ->count();

        $remaining = $order->total_count - $actualDoneCount;

        if ($remaining <= 0) {
            \Log::info('[triggerOrder] No remaining actions for order', ['order_id' => $order->id]);
            return response()->json(['error' => 'No remaining actions available for this order.'], 400);
        }

        \Log::info('[triggerOrder] Remaining actions for order', ['order_id' => $order->id, 'remaining' => $remaining]);

        // Process the order based on type
        if ($type === 'resume') {
            $service = app(\App\Services\ResumeOrderService::class);
            $result = $service->handle($order, $user);
        } else {
            $service = app(\App\Services\OrderService::class);
            $result = $service->handle($order, $user);
        }
        // Log activation flag
        \Log::info('[triggerOrder] Activation flag', ['activation' => $activation]);

        \Log::info('[triggerOrder] Service result', ['result' => $result]);

        return response()->json($result);
    }
}
