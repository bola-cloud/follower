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

Route::group([
    'prefix' => LaravelLocalization::setLocale(), // Set the language prefix correctly
    'middleware' => [
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
        'admin', // Ensure the user is an admin
    ]
], function () {
    Route::get('/', [\App\Http\Controllers\Admin\Dashboard::class, 'index'])->name('dashboard');
});
Route::get('/dashboard/active-users', [\App\Http\Controllers\DashboardController::class, 'activeUsers']);

Route::get('/active', function () {
    return view('active-users');
});
Route::get('/test-active', fn () => view('test-active'));
