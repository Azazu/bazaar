<?php

use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderPaymentController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReviewController;
use Illuminate\Support\Facades\Route;

/*
| REST API v1. Mounted under /api by the framework with the `api` middleware group
| (throttle:api + route bindings) — stateless, token auth via Sanctum, JSON errors.
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public: catalog + reviews (read).
    Route::get('categories', CategoryController::class)->name('categories.index');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{slug}', [ProductController::class, 'show'])->name('products.show');
    Route::get('products/{product:slug}/reviews', [ReviewController::class, 'index'])->name('products.reviews.index');

    // Token issuance gets its own, much tighter limit (credential guessing).
    Route::post('auth/tokens', [TokenController::class, 'store'])
        ->middleware('throttle:api-auth')
        ->name('auth.tokens.store');

    Route::middleware('auth:sanctum')->group(function () {
        Route::delete('auth/tokens/current', [TokenController::class, 'destroy'])->name('auth.tokens.destroy');
        Route::get('me', MeController::class)->name('me');

        Route::get('cart', [CartController::class, 'show'])->name('cart.show');
        Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');
        Route::post('cart/items', [CartController::class, 'store'])->name('cart.items.store');
        Route::patch('cart/items/{variant}', [CartController::class, 'update'])->name('cart.items.update');
        Route::delete('cart/items/{variant}', [CartController::class, 'destroy'])->name('cart.items.destroy');

        Route::post('checkout', CheckoutController::class)->name('checkout');
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('orders/{order}/pay', OrderPaymentController::class)->name('orders.pay');

        Route::post('products/{product:slug}/reviews', [ReviewController::class, 'store'])->name('products.reviews.store');
    });
});
