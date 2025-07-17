<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');

        $users = User::where('type', 'user')
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->paginate(15);

        return view('admin.normal_users.index', compact('users', 'search'));
    }

    public function addPoints(Request $request, User $user)
    {
        $request->validate([
            'points' => 'required|integer|min:1'
        ]);

        $user->increment('points', $request->points);

        return back()->with('success', 'تمت إضافة النقاط بنجاح.');
    }

    public function orders(User $user)
    {
        $orders = $user->orders()->latest()->get();
        return view('admin.normal_users.orders', compact('user', 'orders'));
    }

}
