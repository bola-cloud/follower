<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use Throwable;

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

        // Get point cost per action from settings (default = 1)
        $pointsPerAction = setting("points_per_{$data['type']}", 1);
        $cost = $data['cost'] ?? ($data['total_count'] * $pointsPerAction);

        // Prevent duplicate active orders from the same user for the same link
        $alreadyExists = Order::where('user_id', $user->id)
            ->where('target_url_hash', $targetUrlHash)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($alreadyExists) {
            return response()->json(['error' => 'You already have an active order for this link.'], 409);
        }

        try {
            DB::beginTransaction();

            // Check user points before proceeding
            if ($user->points < $cost) {
                return response()->json(['error' => 'Insufficient points.'], 403);
            }

            // Deduct user points
            $user->decrement('points', $cost);

            // Create the order
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

            DB::commit();

            return response()->json([
                'message' => 'Order created successfully.',
                'order' => $order,
            ], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to create order.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $user = $request->user(); // Automatically retrieved from auth:sanctum middleware

        $orders = \App\Models\Order::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'orders' => $orders,
        ]);
    }

}
