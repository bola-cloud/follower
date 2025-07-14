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

        $action = DB::table('actions')
            ->where('order_id', $validated['order_id'])
            ->where('user_id', $validated['user_id'])
            ->first();

        if (!$action) {
            return response()->json([
                'success' => false,
                'message' => 'Action record not found.',
                'data' => $validated
            ], 404);
        }

        $updated = DB::table('actions')
            ->where('order_id', $validated['order_id'])
            ->where('user_id', $validated['user_id'])
            ->update(['status' => $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Action status updated successfully.',
            'updated_rows' => $updated
        ]);
    }
}
