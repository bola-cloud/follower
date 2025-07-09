<?php

use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use App\Events\TestBroadcast;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/
Broadcast::routes(['middleware' => []]);

Route::group([
    'prefix' => LaravelLocalization::setLocale(), // Set the language prefix correctly
    'middleware' => [
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
    ]
], function () {
    Route::get('/', [\App\Http\Controllers\Admin\Dashboard::class, 'index'])->name('dashboard');
});
Route::get('/dashboard/active-users', [\App\Http\Controllers\DashboardController::class, 'activeUsers']);

Route::get('/active', function () {
    return view('active-users');
});
Route::get('/api/active-users-count', function () {
    $appId = config('broadcasting.connections.pusher.app_id');
    $apiToken = env('SOKETI_SERVER_API_TOKEN');

    $response = \Illuminate\Support\Facades\Http::withToken($apiToken)
        ->get("http://127.0.0.1:6001/api/v1/apps/{$appId}/channels/presence-active-users");

    if ($response->status() === 404) {
        // Channel not created yet — no active users
        return response()->json([
            'active_users_count' => 0
        ]);
    }

    if ($response->failed()) {
        return response()->json([
            'error' => 'Failed to fetch active users count.',
            'details' => $response->body()
        ], 500);
    }

    $channel = $response->json();
    $count = $channel['subscription_count'] ?? 0;

    return response()->json([
        'active_users_count' => $count
    ]);
});
Route::get('/test-active', fn () => view('test-active'));

Route::get('/api/active-users-count', function () {
    $appId = config('broadcasting.connections.pusher.app_id');
    $apiToken = env('SOKETI_SERVER_API_TOKEN');

    $response = \Illuminate\Support\Facades\Http::withToken($apiToken)
        ->get("http://127.0.0.1:6001/api/v1/apps/{$appId}/channels/presence.active.users");

    if ($response->status() === 404) {
        return response()->json([
            'active_users_count' => 0
        ]);
    }

    if ($response->failed()) {
        return response()->json([
            'error' => 'Failed to fetch active users count.',
            'details' => $response->body()
        ], 500);
    }

    $channel = $response->json();
    $count = $channel['subscription_count'] ?? 0;

    return response()->json([
        'active_users_count' => $count
    ]);
});

