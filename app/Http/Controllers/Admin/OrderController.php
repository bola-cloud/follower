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

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $query = Order::query()->with('user');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('target_url', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
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

            // if ($user->points === 0) {
            //     \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));
            // }

            DB::commit();

            app()->make(OrderService::class)->handleOrderCreated($order);

            return redirect()->route('admin.orders.index')->with('success', 'Order created and event broadcasted.');
        } catch (Throwable $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Failed to create order: ' . $e->getMessage());
        }
    }

    public function complete(Request $request, $orderId)
    {
        $user = $request->user();
        $order = Order::with('user')->find($orderId);

        if (!$user || !$order || $order->user_id !== $user->id) {
            return redirect()->back()->with('error', 'Unauthorized or invalid order.');
        }

        try {
            DB::beginTransaction();
            $result = app()->make(\App\Services\ResumeOrderService::class)->resume($order);
            DB::commit();

            return redirect()->route('admin.orders.index')->with('success', $result['message']);
        } catch (\Throwable $e) {
            DB::rollBack();
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function create()
    {
        return view('admin.orders.create');
    }
}
