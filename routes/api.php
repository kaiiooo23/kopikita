<?php

use App\Http\Controllers\Api\OrderApiController;
use Illuminate\Support\Facades\Route;

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

Route::prefix('orders')->group(function () {
    Route::post('/checkout', [OrderApiController::class, 'checkout'])->name('api.orders.checkout');
    Route::patch('/{id}/status', [OrderApiController::class, 'updateStatus'])->name('api.orders.update-status');
    Route::post('/{id}/snap-token', [OrderApiController::class, 'generateSnapToken'])->name('api.orders.snap-token');
    Route::post('/{id}/cash', [OrderApiController::class, 'markPaidCash'])->name('api.orders.cash');
});

// Also expose /api/checkout as a direct alias
Route::post('/checkout', [OrderApiController::class, 'checkout'])->name('api.checkout');
Route::patch('/orders/{id}/status', [OrderApiController::class, 'updateStatus']);
Route::post('/payment/callback', [OrderApiController::class, 'paymentCallback'])->name('api.payment.callback');
