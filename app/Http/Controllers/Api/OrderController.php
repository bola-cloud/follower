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
use App\Services\PingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use App\Models\User;

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

        if(!$user->profile_link || $user->profile_link === null) {
            return response()->json(['error' => 'User profile does not be completed.'], 422);
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
                if (!$user->timer || now()->greaterThan($user->timer)) {
                    \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));
                    $newTimer = now()->addMinutes(30);
                    $user->update(['timer' => $newTimer]);
                    Log::info("[OrderStore] Timer set for user #{$user->id} at {$newTimer}");
                }
            } else {
                $user->update(['timer' => null]); // Reset timer
                Log::info("[OrderStore] Timer reset for user #{$user->id}");
            }

            // Commit the transaction
            DB::commit();

            // ✅ Send ping to activate order with type 'create'
            try {
                $pingService = app()->make(PingService::class);
            $pingService->sendPing('order/ping/req', [
                'type' => 'create',
                'order_id' => $order->id,
                'activation' => true,
            ]);
                Log::info("[OrderStore] Ping sent for order {$order->id} with type 'create'");
            } catch (\Throwable $e) {
                Log::error("[OrderStore] Error sending ping: " . $e->getMessage());
            }

            return response()->json([
                'message' => 'Order created and Mqtt sent.',
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
        $order = Order::with('user')->find($orderId);

        if (!$user || !$order || $order->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized or invalid order.'], 401);
        }

        // ✅ Check if order is paused
        if ($order->status === 'paused') {
            return response()->json(['error' => 'This order has been canceled and cannot be resumed.'], 403);
        }

        try {
            DB::beginTransaction();

            // ✅ Send ping to activate order with type 'resume'
            try {
                $pingService = app()->make(PingService::class);
            $pingService->sendPing('order/ping/req', [
                'type' => 'resume',
                'order_id' => $order->id,
                'activation' => true,
            ]);
                Log::info("[OrderComplete] Ping sent for order {$order->id} with type 'resume'");
            } catch (\Throwable $e) {
                Log::error("[OrderComplete] Error sending ping: " . $e->getMessage());
            }

            DB::commit();

            return response()->json(['message' => 'Resume ping sent successfully.'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function eligibleUsers($order_id)
    {
        $order = Order::find($order_id);

        if (!$order) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        $order->loadMissing('user');

        $query = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')
                    ->from('actions')
                    ->whereIn('order_id', function ($s) use ($order) {
                        $s->select('id')->from('orders')->where('target_url', $order->target_url);
                    })
                    ->whereIn('status', ['done', 'external'])
                    ->whereNotExists(function ($reciprocal) use ($order) {
                        $reciprocal->select(DB::raw(1))
                            ->from('actions as a2')
                            ->join('orders as o2', 'a2.order_id', '=', 'o2.id')
                            ->whereColumn('a2.user_id', 'actions.user_id')
                            ->where('a2.status', 'done')
                            ->where('o2.user_id', $order->user_id)
                            ->whereColumn('o2.target_url', 'users.profile_link');
                    });
            });

        $eligibleUsers = $query->get(); // Fetch the results as a collection

        return response()->json([
            'order_id' => $order->id,
            'eligible_users' => $eligibleUsers,
            'count' => $eligibleUsers->count(),
        ]);
    }
}
