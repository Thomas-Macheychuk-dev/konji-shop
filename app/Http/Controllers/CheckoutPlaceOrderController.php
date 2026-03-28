<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Http\Requests\PlaceOrderRequest;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;
use Throwable;

class CheckoutPlaceOrderController extends Controller
{
    public function __invoke(
        PlaceOrderRequest $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver,
        CheckoutService $checkoutService
    ): RedirectResponse {
        $guestToken = $request->user() ? null : $request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        $cart = $cartService->findActiveCart(
            $request->user(),
            $guestToken
        );

        if (! $cart || $cart->items()->count() === 0) {
            return redirect()
                ->route('cart.show')
                ->with('error', 'Your cart is empty.');
        }

        try {
            $order = $checkoutService->placeOrder(
                $cart,
                $request->validated(),
                $request->user()
            );
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('cart.show')
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('checkout.show')
                ->withInput()
                ->with('error', 'We could not place your order. Please try again.');
        }

        OrderPlaced::dispatch($order);

        $request->session()->put('checkout.last_order_id', $order->id);

        return redirect()->route('checkout.success', $order);
    }
}
