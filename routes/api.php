<?php

use App\Http\Controllers\Api\Account\AuthController as AccountAuthController;
use App\Http\Controllers\Api\Account\SubscriptionController as AccountSubscriptionController;
use App\Http\Controllers\Api\Admin\AdminController;
use App\Http\Controllers\Api\Admin\AuthController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\PaystackWebhookController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Website (public)
|--------------------------------------------------------------------------
*/

Route::get('plans', [PlanController::class, 'index']);
Route::get('plans/{slug}', [PlanController::class, 'show']);
Route::get('settings', SettingsController::class);

// Paystack's own callback. No auth and no throttle: it authenticates itself
// with a signature, and throttling it would lose payments.
Route::post('paystack/webhook', PaystackWebhookController::class);

/*
|--------------------------------------------------------------------------
| Trader accounts
|--------------------------------------------------------------------------
|
| Traders, on their own guard. `user.active` makes a suspension from the
| dashboard take effect on the very next request.
|
*/

Route::prefix('account')->group(function () {
    Route::post('register', [AccountAuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AccountAuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:customer', 'user.active'])->group(function () {
        Route::get('me', [AccountAuthController::class, 'me']);
        Route::post('logout', [AccountAuthController::class, 'logout']);
        Route::put('profile', [AccountAuthController::class, 'updateProfile']);
        Route::put('password', [AccountAuthController::class, 'updatePassword']);

        Route::get('subscriptions', [AccountSubscriptionController::class, 'index']);
        Route::post('subscriptions', [AccountSubscriptionController::class, 'store'])->middleware('throttle:10,1');
        Route::get('subscriptions/{reference}', [AccountSubscriptionController::class, 'show']);
        Route::post('subscriptions/{reference}/paystack', [AccountSubscriptionController::class, 'paystack'])
            ->middleware('throttle:10,1');
        // Reaches out to Paystack on each call, hence the throttle.
        Route::post('subscriptions/{reference}/verify-payment', [AccountSubscriptionController::class, 'verifyPayment'])
            ->middleware('throttle:20,1');
        Route::post('subscriptions/{reference}/transfer-sent', [AccountSubscriptionController::class, 'transferSent'])
            ->middleware('throttle:10,1');
        Route::post('subscriptions/{reference}/cancel', [AccountSubscriptionController::class, 'cancel']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin dashboard
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth:admin', 'admin.active'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::put('password', [AuthController::class, 'updatePassword']);

        Route::get('dashboard', DashboardController::class);

        Route::apiResource('plans', AdminPlanController::class);
        Route::patch('plans/{plan}/toggle', [AdminPlanController::class, 'toggle']);

        Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}', [AdminSubscriptionController::class, 'show']);
        Route::patch('subscriptions/{subscription}/activate', [AdminSubscriptionController::class, 'activate']);
        Route::patch('subscriptions/{subscription}/cancel', [AdminSubscriptionController::class, 'cancel']);
        Route::patch('subscriptions/{subscription}/note', [AdminSubscriptionController::class, 'updateNote']);
        Route::delete('subscriptions/{subscription}', [AdminSubscriptionController::class, 'destroy']);

        Route::get('users', [AdminUserController::class, 'index']);
        Route::get('users/{user}', [AdminUserController::class, 'show']);
        Route::patch('users/{user}/toggle', [AdminUserController::class, 'toggle']);
        Route::post('users/{user}/subscriptions', [AdminUserController::class, 'grant']);
        Route::delete('users/{user}', [AdminUserController::class, 'destroy']);

        Route::get('settings', [AdminSettingsController::class, 'show']);
        Route::put('settings', [AdminSettingsController::class, 'update']);

        // Team management is owner-only.
        Route::middleware('admin.role:owner')->group(function () {
            Route::get('team', [AdminController::class, 'index']);
            Route::post('team', [AdminController::class, 'store']);
            Route::put('team/{admin}', [AdminController::class, 'update']);
            Route::delete('team/{admin}', [AdminController::class, 'destroy']);
        });
    });
});
