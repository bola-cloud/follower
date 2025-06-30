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
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:follow,like',
            'total_count' => 'required|integer|min:1',
            'target_url' => 'required|url',
            'cost' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $user = $request->user();

        $targetUrl = $data['target_url'];
        $targetUrlHash = sha1($targetUrl);

        // 🧠 Get cost per unit from settings (default to 1 if not set)
        $pointsPerAction = setting("points_per_{$data['type']}", 1);

        // 💰 Calculate total cost
        $cost = $data['cost'] ?? ($data['total_count'] * $pointsPerAction);

        // ❌ Prevent duplicate active orders for the same user and target
        $alreadyExists = Order::where('user_id', $user->id)
            ->where('target_url_hash', $targetUrlHash)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($alreadyExists) {
            return response()->json(['error' => 'You already have an active order for this link.'], 409);
        }

        // ❌ Check user points
        if ($user->points < $cost) {
            return response()->json(['error' => 'Insufficient points.'], 403);
        }

        // ✅ Deduct points and create the order
        $user->decrement('points', $cost);

        $order = Order::create([
            'type' => $data['type'],
            'total_count' => $data['total_count'],
            'done_count' => 0,
            'cost' => $cost,
            'status' => 'active',
            'target_url' => $targetUrl,
            'target_url_hash' => $targetUrlHash,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => 'Order created successfully.',
            'order' => $order,
        ], 201);
    }
}
