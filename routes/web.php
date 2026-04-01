<?php

use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use App\Events\TestBroadcast;
use App\Http\Controllers\Admin\front\SettingsController;
use App\Http\Controllers\Admin\front\ApkController;
use App\Http\Controllers\Admin\front\ArticleController;
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
Route::get('/', function () {
    return view('new-design');
});



Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'as' => 'admin.',
    'namespace' => 'App\Http\Controllers\Admin',
    'middleware' => [
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
        'admin',
    ]
], function () {
    Route::get('/dashboard', 'Dashboard@index')->name('dashboard');

    Route::prefix('admin/users')->group(function () {
        Route::get('/', 'AdminUserController@index')->name('users.index');
        Route::get('/create', 'AdminUserController@create')->name('users.create');
        Route::post('/', 'AdminUserController@store')->name('users.store');
        Route::get('/{user}/edit', 'AdminUserController@edit')->name('users.edit');
        Route::put('/{user}', 'AdminUserController@update')->name('users.update');
        Route::delete('/{user}', 'AdminUserController@destroy')->name('users.destroy');
    });

    Route::get('/users', 'UserController@index')->name('normal_users.index');
    Route::get('/users/{user}/orders', 'UserController@orders')->name('normal_users.orders');

    // Admin orders management
    Route::prefix('orders')->group(function () {
        Route::get('/', 'OrderController@index')->name('orders.index');
        Route::get('/create', 'OrderController@create')->name('orders.create');
        Route::post('/', 'OrderController@store')->name('orders.store');
        Route::post('/{order}/complete', 'OrderController@complete')->name('orders.complete');
        Route::post('/cancel-all', 'OrderController@cancelAll')->name('orders.cancelAll');
    });
    Route::post('/admin/settings/update', 'SettingController@update')->name('settings.update');
    Route::get('/admin/settings', 'SettingController@index')->name('settings.index');
    Route::post('/admin/normal-users/{user}/add-points', 'UserController@addPoints')->name('normal_users.add_points');
    Route::get('/admin/orders/{id}', 'OrderController@show')->name('orders.show');
    Route::resource('promocodes', 'PromocodeAdminController')->names('promocodes');
    Route::delete('/admin/promocodes/bulk-delete', 'PromocodeAdminController@bulkDelete')->name('promocodes.bulkDelete');
    Route::post('/admin/orders/{order}/cancel', [\App\Http\Controllers\Admin\OrderController::class, 'cancel'])->name('orders.cancel');
    // Slider management
    Route::resource('sliders', \App\Http\Controllers\Admin\front\SliderController::class)->names('sliders');
    // Slider toggle and quick order update
    Route::post('sliders/{slider}/toggle', [\App\Http\Controllers\Admin\front\SliderController::class, 'toggle'])->name('sliders.toggle');
    Route::post('sliders/{slider}/order', [\App\Http\Controllers\Admin\front\SliderController::class, 'updateOrder'])->name('sliders.order');

    // Notifications management
    Route::resource('notifications', \App\Http\Controllers\Admin\front\NotificationController::class)->names('notifications');
    Route::post('notifications/{notification}/resend', [\App\Http\Controllers\Admin\front\NotificationController::class, 'resend'])->name('notifications.resend');

    // APK Management
    // APK Management Routes (protected)
    Route::prefix('front/admin')->group(function () {
        Route::get('/dashboards', function () {
            return view('reactx.index');
        })->name('reactx.dashboard');
        Route::post('/apk/upload', [ApkController::class, 'store'])->name('apk.store');
        Route::post('/apk/{id}/activate', [ApkController::class, 'activate'])->name('apk.activate');
        Route::delete('/apk/{id}', [ApkController::class, 'destroy'])->name('apk.destroy');
        Route::post('/apk/chunk', [ApkController::class, 'uploadChunk'])->name('apk.upload.chunk');
        Route::post('/apk/complete', [ApkController::class, 'completeChunkUpload'])->name('apk.upload.complete');
        Route::get('/api/apk-stats', [ApkController::class, 'getStats'])->name('apk.stats');

        // Settings routes
        Route::get('/settings', [SettingsController::class, 'index'])->name('front.settings.index');
        Route::post('/settings', [SettingsController::class, 'update'])->name('front.settings.update');
    });
    // Article management (admin)
    Route::resource('articles', ArticleController::class)->names('articles');

});

Route::get('/front/api/settings', [SettingsController::class, 'getPublic'])->name('settings.public');
// Public settings endpoint
// Public APK endpoints
Route::get('/api/apks', [ApkController::class, 'getAllApks'])->name('apk.list');
Route::get('/api/apk/generate-link/{id}', [ApkController::class, 'generateDownloadLink'])->name('apk.generate_link');
Route::get('/download-latest', [ApkController::class, 'downloadLatest'])->name('apk.download.latest'); // Direct download link
Route::get('/apk/download/{id}', [ApkController::class, 'download'])->name('apk.download');
// (moved sliders API to routes/api.php)
Route::get('/apk-downloads', function () {
    return view('apk-downloads');
})->name('apk.downloads');
// Public articles endpoint (used by landing page)
Route::get('/api/articles', [ArticlePublicController::class, 'index'])->name('articles.public');

Route::get('/dashboard/active-users', [\App\Http\Controllers\DashboardController::class, 'activeUsers']);

Route::get('/active', function () {
    return view('active-users');
});
Route::get('/test-active', fn() => view('test-active'));

// Blog Route
Route::get('/blog/{slug}', [App\Http\Controllers\BlogController::class, 'show'])->name('blog.show');
