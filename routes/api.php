<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\SoketiTestController;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Admin\Dashboard;

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
    Route::put('/user/profile-link', [AuthController::class, 'updateProfileLink']);

});
Route::post('/mqtt/response', [\App\Http\Controllers\Api\MqttResponseController::class, 'handle']);
// routes/api.php
Route::post('/mqtt/device-activation', [\App\Http\Controllers\Api\MqttDeviceController::class, 'handle']);
// routes/api.php
Route::get('/device-activation-count', function () {
    return response()->json([
        'count' => Cache::get('device_activations_count', 0),
    ]);
});

Route::post('/login/google', [AuthController::class, 'googleLogin']);
Route::get('/settings', [SettingController::class, 'index']);
Route::get('/chart/users', [Dashboard::class, 'users']);
Route::get('/chart/actions', [Dashboard::class, 'actions']);
