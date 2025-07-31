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

        // Increment done_count if needed
        if ($incrementDone) {
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
