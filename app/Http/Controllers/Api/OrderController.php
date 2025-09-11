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
                }
            } else {
                $user->update(['timer' => null]); // Reset timer
            }

            // Commit the transaction
            DB::commit();

            // ✅ Send ping to activate order with type 'create'
            try {
                $pingService = app()->make(PingService::class);
            $pingService->sendPing('order/ping/req', [
                'type' => 'create',
                'order_id' => $order->id,
                'activation' => true
            ]);
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

            // Clean up stale pending actions (older than 24 hours)
            $deletedCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subHours(24))
                ->delete();
            if ($deletedCount > 0) {
                // Log::info("[OrderComplete] Cleaned up {$deletedCount} stale pending actions for order {$order->id}");
            }

            // ✅ Send ping to activate order with type 'resume'
            try {
                $pingService = app()->make(PingService::class);
                Log::debug('[OrderController] Sending resume ping', ['order_id' => $order->id, 'user_id' => $user->id]);
                $pingService->sendPing('order/ping/req', [
                    'type' => 'resume',
                    'order_id' => $order->id,
                    'activation' => true
                ]);
                Log::debug('[OrderController] Resume ping sent', ['order_id' => $order->id, 'user_id' => $user->id]);
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

    /**
     * Admin-only: Trigger the synchronous publisher for a specific user/order (testing/support)
     * POST payload: { user_id, order_id, type, url }
     */
    public function publishAnnouncement(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'user_id' => 'required|integer',
            'order_id' => 'required|integer',
            'type' => 'required|string',
            'url' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $service = app(\App\Services\OrderService::class);
        $result = $service->publishAnnouncementPublic((int)$data['user_id'], (int)$data['order_id'], $data['type'], $data['url']);

        return response()->json($result);
    }

    public function processActiveUserOrders(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        // 🚀 Faster rate limiting for immediate user activation
        $rateLimitKey = "process_orders_user_{$user->id}";
        $lastProcessed = cache()->get($rateLimitKey);

        if ($lastProcessed && now()->diffInSeconds($lastProcessed) < 3) { // Very fast cooldown
            return response()->json([
                'error' => 'Please wait before processing more orders. Try again in ' . (3 - now()->diffInSeconds($lastProcessed)) . ' seconds.',
                'retry_after' => 3 - now()->diffInSeconds($lastProcessed)
            ], 429);
        }

        // Set rate limit cache
        cache()->put($rateLimitKey, now(), now()->addMinutes(5));

        // Optimized query for immediate processing
        $orders = \App\Models\Order::where('status', 'active')
            ->whereNotExists(function ($query) use ($user) {
                $query->select(DB::raw(1))
                    ->from('actions')
                    ->whereColumn('actions.order_id', 'orders.id')
                    ->where('actions.user_id', $user->id)
                    ->whereIn('actions.status', ['done', 'external']);
            })
            ->orderBy('created_at', 'asc')
            ->with('user')
            ->limit(25) // Increased limit for processing more orders
            ->get();

        // Fast partitioning using array filters
        $adminOrders = $orders->filter(fn($order) => $order->user?->type === 'admin')->values()->all();
        $nonAdminOrders = $orders->filter(fn($order) => $order->user?->type !== 'admin')->values()->all();
        $prioritizedOrders = array_merge($adminOrders, $nonAdminOrders);

        $processed = [];
        $processedOrderIds = [];
        $count = 0;
        $service = app(\App\Services\ResumeOrderService::class);

        foreach ($prioritizedOrders as $order) {
            // Avoid duplicates
            if (in_array($order->id, $processedOrderIds)) {
                continue;
            }

            // Use ResumeOrderService eligibility logic
            if ($service->checkUserEligibility($order, $user)) {
                // 🚀 IMMEDIATE DISPATCH - No delays for user activation
                $result = $service->handle($order, $user);
                $processed[] = [
                    'order_id' => $order->id,
                    'result' => $result
                ];
                $processedOrderIds[] = $order->id;
                $count++;

                if ($count >= 25) break; // Process up to 25 orders immediately
            }
        }

        return response()->json([
            'user_id' => $user->id,
            'processed_count' => $count,
            'results' => $processed,
            'note' => 'All orders dispatched immediately for user activation'
        ]);
    }    /**
     * Test API: Simulate processActiveUserOrders for a specific user_id.
     * Returns the candidate orders and the order type that would be sent to the user.
     * This is read-only and does NOT create actions or dispatch jobs.
     */
    public function testProcessActiveUserOrders(Request $request, $userId)
    {
        $limit = (int) $request->query('limit', 25);

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['error' => 'User not found.'], 404);
        }

        // Fetch active orders where this user has no done/external actions
        $orders = Order::where('status', 'active')
            ->whereNotExists(function ($query) use ($user) {
                $query->select(DB::raw(1))
                    ->from('actions')
                    ->whereColumn('actions.order_id', 'orders.id')
                    ->where('actions.user_id', $user->id)
                    ->whereIn('actions.status', ['done', 'external']);
            })
            ->orderBy('created_at', 'asc')
            ->with('user')
            ->limit(200)
            ->get();

        // Prioritize admin-created orders
        $adminOrders = [];
        $nonAdminOrders = [];
        foreach ($orders as $order) {
            if ($order->user && $order->user->type === 'admin') {
                $adminOrders[] = $order;
            } else {
                $nonAdminOrders[] = $order;
            }
        }
        $prioritized = array_merge($adminOrders, $nonAdminOrders);

        $service = app(\App\Services\ResumeOrderService::class);
        $candidates = [];
        $count = 0;

        foreach ($prioritized as $order) {
            if ($service->checkUserEligibility($order, $user)) {
                $candidates[] = [
                    'order_id' => $order->id,
                    'type' => $order->type,
                    'target_url' => $order->target_url,
                    'order_owner_id' => $order->user_id,
                    'order_owner_type' => $order->user->type ?? null,
                    'created_at' => $order->created_at->toDateTimeString(),
                ];

                $count++;
                if ($count >= $limit) break;
            }
        }

        return response()->json([
            'user_id' => $user->id,
            'limit' => $limit,
            'candidates_count' => $count,
            'candidates' => $candidates,
        ]);
    }
}
