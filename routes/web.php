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
    'prefix' => LaravelLocalization::setLocale(),
    'as' => 'admin.',
    'namespace' => 'App\Http\Controllers\Admin',
    'middleware' => [
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
        'admin', // تأكد أن المستخدم مدير
    ]
], function () {
    Route::get('/', 'Dashboard@index')->name('dashboard');
    Route::prefix('admin/users')->group(function () {
        Route::get('/', 'AdminUserController@index')->name('users.index');
        Route::get('/create', 'AdminUserController@create')->name('users.create');
        Route::post('/', 'AdminUserController@store')->name('users.store');
        Route::get('/{user}/edit', 'AdminUserController@edit')->name('users.edit');
        Route::put('/{user}', 'AdminUserController@update')->name('users.update');
        Route::delete('/{user}', 'AdminUserController@destroy')->name('users.destroy');
    });
    // Only index for normal users
    Route::get('/users', 'UserController@index')->name('normal_users.index');
});
Route::get('/dashboard/active-users', [\App\Http\Controllers\DashboardController::class, 'activeUsers']);

Route::get('/active', function () {
    return view('active-users');
});
Route::get('/test-active', fn () => view('test-active'));
