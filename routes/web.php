<?php

use App\Http\Controllers\Account\AccountDetailsShowController;
use App\Http\Controllers\Account\AccountDetailsUpdateController;
use App\Http\Controllers\Account\OrderCancelController;
use App\Http\Controllers\Account\OrderIndexController;
use App\Http\Controllers\Account\OrderShowController;
use App\Http\Controllers\Admin\Delivery\AdminPolkurierAvailableCarriersRefreshController;
use App\Http\Controllers\Admin\Delivery\AdminPolkurierDiagnosticsController;
use App\Http\Controllers\Admin\Delivery\AdminPolkurierValuationTestController;
use App\Http\Controllers\Admin\Orders\AdminOrderCancelController;
use App\Http\Controllers\Admin\Orders\AdminOrderIndexController;
use App\Http\Controllers\Admin\Orders\AdminOrderNoteController;
use App\Http\Controllers\Admin\Orders\AdminOrderShipmentController;
use App\Http\Controllers\Admin\Orders\AdminOrderShowController;
use App\Http\Controllers\Admin\Orders\AdminPolkurierPickupTimesController;
use App\Http\Controllers\Admin\Orders\AdminShipmentCancelController;
use App\Http\Controllers\Admin\Orders\AdminShipmentLabelController;
use App\Http\Controllers\Admin\Orders\AdminShipmentProtocolController;
use App\Http\Controllers\Admin\Orders\AdminShipmentStatusRefreshController;
use App\Http\Controllers\Admin\Orders\OrderFulfilmentController;
use App\Http\Controllers\Admin\Products\AdminProductEditController;
use App\Http\Controllers\Admin\Products\AdminProductIndexController;
use App\Http\Controllers\Admin\Products\AdminProductPackageDimensionsUpdateController;
use App\Http\Controllers\Admin\Products\AdminProductUpdateController;
use App\Http\Controllers\Admin\Products\AdminProductVariantPackageDimensionsUpdateController;
use App\Http\Controllers\Admin\Shop\AdminShopReadinessController;
use App\Http\Controllers\CartItemDestroyController;
use App\Http\Controllers\CartItemStoreController;
use App\Http\Controllers\CartItemUpdateController;
use App\Http\Controllers\CartShowController;
use App\Http\Controllers\CartSummaryController;
use App\Http\Controllers\Checkout\CheckoutShippingQuoteController;
use App\Http\Controllers\Checkout\InPostParcelLockerSearchController;
use App\Http\Controllers\CheckoutPlaceOrderController;
use App\Http\Controllers\CheckoutShowController;
use App\Http\Controllers\GuestOrderCancelController;
use App\Http\Controllers\GuestOrderShowController;
use App\Http\Controllers\GuestOrderTrackLookupController;
use App\Http\Controllers\GuestOrderTrackShowController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Payments\PaymentReturnController;
use App\Http\Controllers\Payments\PaynowNotificationController;
use App\Http\Controllers\ProductShowController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\RobotsTxtController;

Route::get('/', HomeController::class)->name('home');

Route::get('/products/{product:slug}', ProductShowController::class)
    ->name('products.show');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::get('/robots.txt', RobotsTxtController::class)->name('robots');

Route::get('/cart', CartShowController::class)->name('cart.show');
Route::post('/cart/items', CartItemStoreController::class)->name('cart.items.store');
Route::patch('/cart/items/{cartItem}', CartItemUpdateController::class)->name('cart.items.update');
Route::delete('/cart/items/{cartItem}', CartItemDestroyController::class)->name('cart.items.destroy');
Route::get('/cart/summary', CartSummaryController::class)->name('cart.summary');

Route::get('/checkout', CheckoutShowController::class)->name('checkout.show');
Route::post('/checkout', CheckoutPlaceOrderController::class)->name('checkout.place');
Route::get('/checkout/success', PaymentReturnController::class)
    ->name('checkout.success');
Route::get('/checkout/inpost-parcel-lockers', InPostParcelLockerSearchController::class)
    ->name('checkout.inpost-parcel-lockers');
Route::post('/checkout/shipping-quote', CheckoutShippingQuoteController::class)
    ->name('checkout.shipping-quote');

Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/orders', AdminOrderIndexController::class)
            ->name('orders.index');

        Route::get('/orders/{order}', AdminOrderShowController::class)
            ->name('orders.show');

        Route::get('/orders/{order}/polkurier-pickup-times', AdminPolkurierPickupTimesController::class)
            ->name('orders.polkurier-pickup-times');

        Route::patch('/orders/{order}/fulfilment/{action}', OrderFulfilmentController::class)
            ->name('orders.fulfilment.update');

        Route::patch('/orders/{order}/notes', AdminOrderNoteController::class)
            ->name('orders.notes.update');

        Route::patch('/orders/{order}/cancel', AdminOrderCancelController::class)
            ->name('orders.cancel');

        Route::post('/orders/{order}/shipments', AdminOrderShipmentController::class)
            ->name('orders.shipments.store');

        Route::get('/shipments/{shipment}/label', AdminShipmentLabelController::class)
            ->name('shipments.label');

        Route::get('/shipments/{shipment}/protocol', AdminShipmentProtocolController::class)
            ->name('shipments.protocol');

        Route::patch('/shipments/{shipment}/status', AdminShipmentStatusRefreshController::class)
            ->name('shipments.status.refresh');

        Route::patch('/shipments/{shipment}/cancel', AdminShipmentCancelController::class)
            ->name('shipments.cancel');

        Route::get('/products', AdminProductIndexController::class)
            ->name('products.index');

        Route::get('/products/{product}/edit', AdminProductEditController::class)
            ->name('products.edit');

        Route::patch('/products/{product}', AdminProductUpdateController::class)
            ->name('products.update');

        Route::patch('/products/{product}/package-dimensions', AdminProductPackageDimensionsUpdateController::class)
            ->name('products.package-dimensions.update');

        Route::patch('/products/{product}/variants/package-dimensions', AdminProductVariantPackageDimensionsUpdateController::class)
            ->name('products.variants.package-dimensions.update');

        Route::get('/polkurier', AdminPolkurierDiagnosticsController::class)
            ->name('polkurier.index');

        Route::post('/polkurier/available-carriers/refresh', AdminPolkurierAvailableCarriersRefreshController::class)
            ->name('polkurier.available-carriers.refresh');

        Route::post('/polkurier/valuation-test', AdminPolkurierValuationTestController::class)
            ->name('polkurier.valuation-test');

        Route::get(
            '/production-readiness',
            AdminShopReadinessController::class
        )->name('shop.readiness');
    });

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

Route::post('/payments/paynow/notifications', PaynowNotificationController::class)
    ->withoutMiddleware([
        PreventRequestForgery::class,
    ])
    ->name('payments.paynow.notifications');

Route::view('/terms-and-conditions', 'pages.legal.terms-and-conditions')
    ->name('legal.terms');

Route::view('/privacy-policy', 'pages.legal.privacy-policy')
    ->name('legal.privacy');

Route::view('/returns-and-withdrawal', 'pages.legal.returns-and-withdrawal')
    ->name('legal.returns');

Route::view('/complaints-and-warranty', 'pages.legal.complaints-and-warranty')
    ->name('legal.complaints');

Route::view('/delivery-and-payments', 'pages.legal.delivery-and-payments')
    ->name('legal.delivery-payments');

Route::view('/contact', 'pages.legal.contact')
    ->name('legal.contact');

Route::view('/cookie-policy', 'pages.legal.cookie-policy')
    ->name('legal.cookie-policy');

require __DIR__.'/settings.php';
