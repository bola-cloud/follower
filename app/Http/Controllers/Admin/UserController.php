<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        $users = User::where('type', 'user')->get();
        return view('admin.normal_users.index', compact('users'));
    }
    public function orders(User $user)
    {
        $orders = $user->orders()->latest()->get();
        return view('admin.normal_users.orders', compact('user', 'orders'));
    }

}
