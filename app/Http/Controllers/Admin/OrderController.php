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
        // Validate the input directly using the built-in validate() method
        $validated = $request->validate([
            'type' => 'required|in:follow,like',
            'total_count' => 'required|integer|min:1',
            'target_url' => 'required|url',
        ]);

        // Get the authenticated user
        $user = $request->user();

        // If the user is not authenticated, redirect back with an error message
        if (!$user) {
            return redirect()->back()->with('error', 'User not authenticated.');
        }

        // Extract validated data
        $targetUrl = $validated['target_url'];
        $targetUrlHash = sha1($targetUrl);

        // Get the cost for the action
        $pointsPerAction = function_exists('setting') ? setting("points_per_{$validated['type']}", 1) : 1;
        $cost = $validated['cost'] ?? ($validated['total_count'] * $pointsPerAction);

        // Prevent duplicate active orders from the same user for the same link
        $alreadyExists = Order::where('user_id', $user->id)
            ->where('target_url_hash', $targetUrlHash)
            ->where('status', '!=', 'completed')
            ->exists();

        if ($alreadyExists) {
            return redirect()->back()->with('error', 'You already have an active order for this link.');
        }

        try {
            DB::beginTransaction();

            // Check if the user has enough points
            if ($user->points < $cost) {
                return redirect()->back()->with('error', 'Insufficient points.');
            }

            // Deduct points from the user
            $user->decrement('points', $cost);

            // Create the order
            $order = Order::create([
                'type' => $validated['type'],
                'total_count' => $validated['total_count'],
                'done_count' => 0,
                'cost' => $cost,
                'status' => 'active',
                'target_url' => $targetUrl,
                'target_url_hash' => $targetUrlHash,
                'user_id' => $user->id,
            ]);

            // Check if the order was created successfully
            if (!$order) {
                return redirect()->back()->with('error', 'Failed to create order.');
            }

            // If the user points are zero, schedule a job to add points
            if ($user->points === 0) {
                \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));
            }

            DB::commit();

            // Trigger the OrderCreated event
            // event(new OrderCreated($order));

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
