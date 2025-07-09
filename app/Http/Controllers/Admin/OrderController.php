<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\OrderController as ApiOrderController;
use Illuminate\Http\Request;
use App\Models\Order;

class OrderController extends ApiOrderController
{
    public function index(Request $request)
    {
        $query = Order::query();

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('target_url', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%"));
        }

        $orders = $query->latest()->paginate(15);

        return view('admin.orders.index', compact('orders'));
    }
}
