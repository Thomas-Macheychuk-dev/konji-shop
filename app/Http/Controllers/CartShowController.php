<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CartShowController extends Controller
{
    public function __invoke(
        Request $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver
    ): View {
        $guestToken = $request->user() ? null : $request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        $cart = $cartService->findActiveCart(
            $request->user(),
            $guestToken
        );

        if ($cart) {
            $cart->load([
                'items.product.mainImage',
                'items.variant',
            ]);
        }

        $subtotal = 0;

        foreach ($cart?->items ?? [] as $item) {
            $subtotal += $item->unit_price * $item->quantity;
        }

        return view('pages.cart.show', [
            'cart' => $cart,
            'subtotal' => $subtotal,
        ]);
    }
}
