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

        // Prevent re-incrementing if already done
        if ($action->status === 'done' && $status === 'done') {
            return response()->json([
                'success' => true,
                'message' => 'Action already marked as done.',
                'updated_rows' => 0
            ]);
        }

        // Update action status
        $updated = DB::table('actions')
            ->where('order_id', $orderId)
            ->where('user_id', $userId)
            ->update(['status' => $status]);

        // If status is "done", increment the done_count on the order
        if ($status === 'done') {
            DB::table('orders')
                ->where('id', $orderId)
                ->increment('done_count');
        }

        return response()->json([
            'success' => true,
            'message' => 'Action status updated successfully.',
            'updated_rows' => $updated
        ]);
    }
}
