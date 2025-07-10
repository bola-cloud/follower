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

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:follow,like',
            'total_count' => 'required|integer|min:1',
            'target_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $user = auth()->user();

        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $targetUrl = $data['target_url'];
        $targetUrlHash = sha1($targetUrl);

        $pointsPerAction = function_exists('setting') ? setting("points_per_{$data['type']}", 1) : 1;
        $cost = $data['cost'] ?? ($data['total_count'] * $pointsPerAction);

        $alreadyExists = Order::where('user_id', $user->id)
            ->where('target_url_hash', $targetUrlHash)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($alreadyExists) {
            return redirect()->back()->with('error', 'You already have an active order for this link.');
        }

        try {
            DB::beginTransaction();

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

            if ($user->points === 0) {
                \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));
            }

            DB::commit();

            \Log::info('Order Created and go to event:', $order->toArray());
            event(new OrderCreated($order));

            return redirect()->route('admin.orders.index')->with('success', 'Order created and event broadcasted.');
        } catch (Throwable $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Failed to create order: ' . $e->getMessage());
        }
    }

    public function complete(Request $request, $orderId)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        $order = Order::where('id', $orderId)
            ->where('user_id', $user->id)
            ->first();

        if (!$order) {
            return redirect()->back()->with('error', 'Order not found or you are not authorized.');
        }

        if ($order->status === 'completed') {
            return redirect()->back()->with('error', 'Order is already completed.');
        }

        if ($order->done_count >= $order->total_count) {
            return redirect()->back()->with('error', 'Order is already fulfilled.');
        }

        try {
            DB::beginTransaction();

            DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'external',
                    'performed_at' => now(),
                ]);

            $uncompletedCount = $order->total_count - $order->done_count;
            $pointsPerAction = function_exists('setting') ? setting("points_per_{$order->type}", 1) : 1;
            $refundPoints = $uncompletedCount * $pointsPerAction;
            $user->increment('points', $refundPoints);

            $order->update(['status' => 'completed']);

            event(new \App\Events\OrderCompleted($order));

            DB::commit();

            return redirect()->route('admin.orders.index')->with('success', 'Order completed successfully.');
        } catch (Throwable $e) {
            DB::rollBack();
            \Log::error('Failed to complete order:', ['error' => $e->getMessage()]);
            return redirect()->back()->with('error', 'Failed to complete order: ' . $e->getMessage());
        }
    }

    public function create()
    {
        return view('admin.orders.create');
    }
}
