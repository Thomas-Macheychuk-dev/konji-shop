<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductShowController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\CartItemStoreController;
use App\Http\Controllers\CartShowController;
use App\Http\Controllers\CartItemUpdateController;
use App\Http\Controllers\CartItemDestroyController;

Route::get('/', HomeController::class)->name('home');

Route::get('/products/{product:slug}', ProductShowController::class)
    ->name('products.show');

Route::get('/cart', CartShowController::class)->name('cart.show');
Route::post('/cart/items', CartItemStoreController::class)->name('cart.items.store');
Route::patch('/cart/items/{cartItem}', CartItemUpdateController::class)->name('cart.items.update');
Route::delete('/cart/items/{cartItem}', CartItemDestroyController::class)->name('cart.items.destroy');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
