<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Events\OrderCreated;
use App\Events\OrderCompleted;
use Throwable;
use Illuminate\Support\Facades\Log;
use App\Services\OrderService;
use App\Services\ResumeOrderService;
use App\Services\PingService;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query()->with('user');

        // Filter by status if provided
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Improved search for target_url and user name
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('target_url', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($userQ) use ($search) {
                      $userQ->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->latest()->paginate(15);

        return view('admin.orders.index', compact('orders'));
    }

    public function show($id)
    {
        $order = Order::with('user')->findOrFail($id);

        $actionUsers = \DB::table('actions')
            ->join('users', 'users.id', '=', 'actions.user_id')
            ->where('actions.order_id', $id)
            ->where('actions.status', 'done')
            ->select('users.name', 'users.email', 'actions.performed_at')
            ->get();

        return view('admin.orders.show', compact('order', 'actionUsers'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:follow,like',
            'total_count' => 'required|integer|min:1',
            'target_url' => 'required',
            'cost' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $user = $request->user();

        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $targetId = $data['target_url'];
        $targetUrl = $targetId;
        $targetUrlHash = sha1($targetUrl);

        $pointsPerAction = function_exists('setting') ? setting("points_per_{$data['type']}", 1) : 1;
        $cost = $data['cost'] ?? ($data['total_count'] * $pointsPerAction);

        try {
            DB::beginTransaction();

            if ($user->type === 'admin') {
                $cost = 0;
            }

            if ($user->points < $cost) {
                return redirect()->back()->with('error', 'Insufficient points.');
            }

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

            if (!$order) {
                return redirect()->back()->with('error', 'Failed to create order.');
            }

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

            // Redirect back to previous page with query string if possible
            $redirectUrl = url()->previous() ?? route('admin.orders.index');
            // If previous URL is the create page, fallback to index
            if (str_contains($redirectUrl, '/admin/orders/create')) {
                $redirectUrl = route('admin.orders.index');
            }
            return redirect($redirectUrl)->with('success', 'Order created and event broadcasted.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("[OrderStore] Exception occurred: " . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to create order: ' . $e->getMessage());
        }
    }

    public function complete(Request $request, $orderId)
    {
        $user = $request->user();
        $order = Order::with('user')->find($orderId);

        if (!$user || !$order || ($user->type !== 'admin' && $order->user_id !== $user->id)) {
            Log::warning("[OrderComplete] Unauthorized access attempt or invalid order ID: {$orderId}");
            return redirect()->back()->with('error', 'Unauthorized or invalid order.');
        }

        if ($order->status === 'completed') {
            Log::info("[OrderComplete] Attempt to complete an already completed order (ID: {$order->id})");
            return redirect()->back()->with('error', 'Cannot complete an already completed order.');
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
                Log::info("[OrderComplete] Cleaned up {$deletedCount} stale pending actions for order {$order->id}");
            }

            Log::info("[OrderComplete] Starting resume process for Order #{$order->id}");

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

            // Redirect back to previous page with query string if possible
            $redirectUrl = url()->previous() ?? route('admin.orders.index');
            // If previous URL is the show page, fallback to index
            if (str_contains($redirectUrl, "/admin/orders/{$orderId}")) {
                $redirectUrl = route('admin.orders.index');
            }
            return redirect($redirectUrl)->with('success', 'Resume ping sent successfully.');
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("[OrderComplete] Exception occurred: {$e->getMessage()}");
            return redirect()->back()->with('error', 'An error occurred: ' . $e->getMessage());
        }
    }

    public function create()
    {
        return view('admin.orders.create');
    }

    public function cancel(Request $request, Order $order)
    {
        if ($order->status === 'completed') {
            return redirect()->back()->with('error', 'لا يمكن إلغاء طلب مكتمل.');
        }

        $order->update(['status' => 'paused']);

        return redirect()->back()->with('success', 'تم إلغاء الطلب بنجاح.');
    }

    public function cancelAll(Request $request)
    {
        // Only pause active orders
        $affected = Order::where('status', 'active')->update(['status' => 'paused']);

        return redirect()->back()->with('success', "تم إلغاء جميع الطلبات النشطة  بنجاح.");
    }

}
