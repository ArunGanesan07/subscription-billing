<?php

use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UsageController;
use Illuminate\Support\Facades\Route;

/*
| Every route is authenticated with a merchant API key (Bearer token) and
| tenant-scoped: route-bound models resolve only within the calling merchant.
*/

Route::prefix('v1')->middleware('auth.merchant')->group(function () {
    // Hot path: its own, higher-volume rate limit (see AppServiceProvider).
    Route::post('usage', [UsageController::class, 'store'])->middleware('throttle:usage');

    Route::middleware('throttle:api')->group(function () {
        Route::get('merchants/{merchant}/dashboard', DashboardController::class)->whereNumber('merchant');

        Route::apiResource('plans', PlanController::class)->except('destroy');
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);

        Route::post('customers/{customer}/subscriptions', [SubscriptionController::class, 'store']);
        Route::get('subscriptions/{subscription}', [SubscriptionController::class, 'show']);
        Route::post('subscriptions/{subscription}/change-plan', [SubscriptionController::class, 'changePlan']);
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);

        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    });
});
