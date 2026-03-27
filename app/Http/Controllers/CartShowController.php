<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CartShowController extends Controller
{
    public function __invoke(
        Request $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver,
        CartPricingService $cartPricingService
    ): View {
        $guestToken = $request->user() ? null : $request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        $cart = $cartService->findActiveCart(
            $request->user(),
            $guestToken
        );

        if ($cart) {
            $cart->load([
                'items.product',
                'items.variant.attributeValues.attribute',
                'items.variant.attributeValues.productImage',
            ]);
        }

        $totals = $cartPricingService->calculate($cart);

        return view('pages.cart.show', [
            'cart' => $cart,
            'subtotal' => $totals->subtotal,
            'shipping' => $totals->shipping,
            'discount' => $totals->discount,
            'total' => $totals->total,
        ]);
    }
}
