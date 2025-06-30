<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Order;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:follow,like',
            'total_count' => 'required|integer|min:1',
            'target_url' => 'required|url',
            'cost' => 'nullable|integer|min:0', // optionally override cost
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // Optional: calculate cost (e.g., 1 point per action)
        $cost = $request->input('cost', $request->total_count);

        if ($user->points < $cost) {
            return response()->json(['error' => 'Insufficient points.'], 403);
        }

        // Deduct points
        $user->decrement('points', $cost);

        // Create order
        $order = Order::create([
            'type' => $request->type,
            'total_count' => $request->total_count,
            'done_count' => 0,
            'cost' => $cost,
            'status' => 'active',
            'target_url' => $request->target_url,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order,
        ], 201);
    }
}
