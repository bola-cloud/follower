<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Events\OrderCreated;
use App\Events\OrderCompleted;
use Throwable;
use App\Services\OrderService;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:follow,like',
            'total_count' => 'required|integer|min:1',
            'target_url' => 'required',
            'cost' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $user = $request->user(); // Get authenticated user

        // Check if the user exists (this ensures $user is not null)
        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        $targetId = $data['target_url'];


        // if (!$targetId) {
        //     return response()->json(['error' => 'Invalid Instagram URL format.'], 422);
        // }

        $targetUrl = $targetId;  // We overwrite it to store only the ID
        $targetUrlHash = sha1($targetUrl);


        // Get point cost per action from settings (default = 1)
        $pointsPerAction = function_exists('setting') ? setting("points_per_{$data['type']}", 1) : 1;
        $cost = $data['cost'] ?? ($data['total_count'] * $pointsPerAction);

        // Prevent duplicate active orders from the same user for the same link
        // $alreadyExists = Order::where('user_id', $user->id)
        //     ->where('target_url_hash', $targetUrlHash)
        //     ->where('status', '!=', 'completed')
        //     ->exists();

        // if ($alreadyExists) {
        //     return response()->json(['error' => 'You already have an active order for this link.'], 409);
        // }

        try {
            DB::beginTransaction();

            // Check if user has enough points before proceeding
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
                'target_url' => $targetUrl, // ✅ only the ID (e.g. DLsNPlfu1V6)
                'target_url_hash' => $targetUrlHash,
                'user_id' => $user->id,
            ]);

            // Ensure order is created
            if (!$order) {
                return response()->json(['error' => 'Failed to create order.'], 500);
            }
            if ($user->points === 0) {
                \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));
            }

            // Commit the transaction
            DB::commit();

            \Log::info('Order Created and go to event:', $order->toArray());

            // Trigger the OrderCreated event to broadcast to eligible users
            app()->make(OrderService::class)->handleOrderCreated($order);

            return response()->json([
                'message' => 'Order created and event broadcasted.',
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

    private static function extractInstagramId($url)
    {
        // Match Instagram reel or post URLs
        if (preg_match('/(?:\/reel\/|\/p\/)([A-Za-z0-9_-]+)/', $url, $matches)) {
            return $matches[1];
        }

        return null;
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

    public function complete(Request $request, $orderId)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found or you are not authorized.'], 404);
        }

        if ($order->status === 'completed') {
            return response()->json(['error' => 'Order is already completed.'], 409);
        }

        if ($order->done_count >= $order->total_count) {
            return response()->json(['error' => 'Order is already fulfilled.'], 409);
        }

        try {
            DB::beginTransaction();

            // Mark remaining pending actions as external
            DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'external',
                    'performed_at' => now(),
                ]);

            // Refund points for uncompleted actions
            $uncompletedCount = $order->total_count - $order->done_count;
            $pointsPerAction = function_exists('setting') ? setting("points_per_{$order->type}", 1) : 1;
            $refundPoints = $uncompletedCount * $pointsPerAction;
            $user->increment('points', $refundPoints);

            // Mark order as completed
            $order->update(['status' => 'completed']);

            // Trigger OrderCompleted event
            event(new \App\Events\OrderCompleted($order));

            DB::commit();

            return response()->json([
                'message' => 'Order completed successfully.',
                'order' => $order,
                'refunded_points' => $refundPoints,
            ], 200);
        } catch (Throwable $e) {
            DB::rollBack();
            \Log::error('Failed to complete order:', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to complete order.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
