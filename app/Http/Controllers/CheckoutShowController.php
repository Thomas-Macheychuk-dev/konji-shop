<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutShowController extends Controller
{
    public function __invoke(
        Request $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver,
        CartPricingService $cartPricingService
    ): View|RedirectResponse {
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

        $cart->load([
            'items.product.mainImage',
            'items.variant.attributeValues.attribute',
        ]);

        $totals = $cartPricingService->calculate($cart);

        return view('pages.checkout.show', [
            'cart' => $cart,
            'subtotal' => $totals->subtotal,
            'shipping' => $totals->shipping,
            'discount' => $totals->discount,
            'total' => $totals->total,
        ]);
    }
}
