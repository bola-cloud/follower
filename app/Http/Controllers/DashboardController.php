<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DashboardController extends Controller
{
    public function activeUsers()
    {
        $response = Http::withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
        ])->post(url('/laravel-websockets/statistics'));

        if ($response->successful()) {
            $data = $response->json();
            $activeConnections = $data['peak_connection_count'] ?? 0;

            return view('dashboard.active-users', compact('activeConnections'));
        }

        return view('dashboard.active-users', ['activeConnections' => 0]);
    }
}
