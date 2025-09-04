<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\SoketiTestController;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Admin\Dashboard;
use App\Http\Controllers\Api\PromocodeController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Broadcast::routes(['middleware' => ['auth:sanctum']]);
Route::post('register', [AuthController::class, 'register']);
Route::post('login', [AuthController::class, 'login']);

Route::post('/trigger-test-order', [SoketiTestController::class, 'triggerTestOrder']);
Route::post('/trigger-test-response', [SoketiTestController::class, 'triggerTestResponse']);

Route::get('/orders/{order_id}/eligible-users', [OrderController::class, 'eligibleUsers']);

Route::post('/orders/publish-announcement', [OrderController::class, 'publishAnnouncement']);

// Protected Routes (Require authentication)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders/{orderId}/complete', [OrderController::class, 'complete']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('/active-users-count', [SoketiTestController::class, 'getActiveUsersCount']);
    Route::post('/user/profile-link', [AuthController::class, 'updateProfileLink']);
    Route::post('/promocode/redeem', [PromocodeController::class, 'redeem']);
    Route::get('/user/points', [AuthController::class, 'points']);
    Route::post('/user/process-active-orders', [OrderController::class, 'processActiveUserOrders']);
    // Disconnect Google/Instagram account
    Route::post('/user/disconnect-account', [AuthController::class, 'disconnectAccount']);
});
Route::post('/mqtt/response', [\App\Http\Controllers\Api\MqttResponseController::class, 'handle']);
Route::post('/mqtt/recalculate-orders', [\App\Http\Controllers\Api\MqttResponseController::class, 'recalculateAllOrders']);
Route::post('/mqtt/trigger-order', [\App\Http\Controllers\Api\MqttResponseController::class, 'triggerOrder']);
Route::post('/mqtt/cleanup-stale-actions', [\App\Http\Controllers\Api\MqttResponseController::class, 'cleanupStaleActions']);
// routes/api.php
Route::post('/mqtt/device-activation', [\App\Http\Controllers\Api\MqttDeviceController::class, 'handle']);
// routes/api.php
Route::get('/device-activation-count', function () {
    return response()->json([
        'count' => Cache::get('device_activations_count', 0),
    ]);
});

Route::post('/login/google', [AuthController::class, 'googleLogin']);
// Test endpoint (read-only) to simulate processActiveUserOrders for a given user id
Route::get('/test/process-active-orders/{userId}', [OrderController::class, 'testProcessActiveUserOrders']);
Route::get('/settings', [SettingController::class, 'index']);
Route::get('/chart/users', [Dashboard::class, 'users']);
Route::get('/chart/actions', [Dashboard::class, 'actions']);

// Lightweight health endpoint: DB connectivity + queued actions size + last bulk run
Route::get('/health/queue-db', function () {
    try {
        DB::connection()->getPdo();
        $db = true;
    } catch (\Throwable $e) {
        $db = false;
    }

    $queueSize = \Illuminate\Support\Facades\Redis::llen('mqtt_actions_queue');
    $lastRun = Cache::get('bulk_order_last_run_at');

    return response()->json([
        'db_connected' => $db,
        'queued_actions' => is_array($queueSize) ? count($queueSize) : 0,
        'last_bulk_run_at' => $lastRun ? date('c', (int) $lastRun) : null,
    ]);
});
