<?php

use App\Http\Controllers\Account\AccountDetailsShowController;
use App\Http\Controllers\Account\AccountDetailsUpdateController;
use App\Http\Controllers\Account\OrderCancelController;
use App\Http\Controllers\Account\OrderIndexController;
use App\Http\Controllers\Account\OrderShowController;
use App\Http\Controllers\CartItemDestroyController;
use App\Http\Controllers\CartItemStoreController;
use App\Http\Controllers\CartItemUpdateController;
use App\Http\Controllers\CartShowController;
use App\Http\Controllers\CartSummaryController;
use App\Http\Controllers\CheckoutPlaceOrderController;
use App\Http\Controllers\CheckoutShowController;
use App\Http\Controllers\CheckoutSuccessController;
use App\Http\Controllers\GuestOrderCancelController;
use App\Http\Controllers\GuestOrderShowController;
use App\Http\Controllers\GuestOrderTrackLookupController;
use App\Http\Controllers\GuestOrderTrackShowController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProductShowController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::get('/products/{product:slug}', ProductShowController::class)
    ->name('products.show');

Route::get('/cart', CartShowController::class)->name('cart.show');
Route::post('/cart/items', CartItemStoreController::class)->name('cart.items.store');
Route::patch('/cart/items/{cartItem}', CartItemUpdateController::class)->name('cart.items.update');
Route::delete('/cart/items/{cartItem}', CartItemDestroyController::class)->name('cart.items.destroy');
Route::get('/cart/summary', CartSummaryController::class)->name('cart.summary');

Route::get('/checkout', CheckoutShowController::class)->name('checkout.show');
Route::post('/checkout', CheckoutPlaceOrderController::class)->name('checkout.place');
Route::get('/checkout/success/{order}', CheckoutSuccessController::class)->name('checkout.success');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('account')->name('account.')->group(function () {
        Route::get('/details', AccountDetailsShowController::class)
            ->name('details.show');

        Route::patch('/details', AccountDetailsUpdateController::class)
            ->name('details.update');

        Route::get('/orders', OrderIndexController::class)->name('orders.index');
        Route::get('/orders/{orderId}', OrderShowController::class)->name('orders.show');
        Route::post('/orders/{orderId}/cancel', OrderCancelController::class)->name('orders.cancel');
    });
});

Route::prefix('guest/orders')->name('guest.orders.')->group(function () {
    Route::get('/track', GuestOrderTrackShowController::class)->name('track.show');
    Route::post('/track', GuestOrderTrackLookupController::class)->name('track.lookup');
    Route::get('/status/{order}', GuestOrderShowController::class)->name('show');
    Route::post('/status/{order}/cancel', GuestOrderCancelController::class)->name('cancel');
});

Route::view('/cookie-policy', 'pages.legal.cookie-policy')->name('legal.cookie-policy');

require __DIR__.'/settings.php';
