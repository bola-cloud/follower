<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $validated = $request->validate([
            'order_id' => 'required|integer',
            'user_id' => 'required|integer',
        ]);

        $orderId = $validated['order_id'];
        $userId = $validated['user_id'];

        // Verify the action exists and is pending
        $action = DB::table('actions')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->first();

        if (!$action) {
            return response()->json([
                'success' => false,
                'message' => 'No pending action found for this user/order.'
            ], 404);
        }

        // Get order details
        $order = DB::table('orders')->where('id', $orderId)->first();
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found.'
            ], 404);
        }

        // Dispatch the MQTT job
        dispatch(new \App\Jobs\SendMqttToUserJob(
            $userId,
            $orderId,
            $order->type,
            $order->target_url
        ));

        return response()->json([
            'success' => true,
            'message' => 'MQTT job dispatched successfully.',
            'user_id' => $userId,
            'order_id' => $orderId
        ]);
    }
}
