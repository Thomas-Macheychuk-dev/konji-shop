<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AddCartItemRequest;
use App\Models\ProductVariant;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use Illuminate\Http\RedirectResponse;

class CartItemStoreController extends Controller
{
    public function __invoke(
        AddCartItemRequest $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver
    ): RedirectResponse {
        $variant = ProductVariant::query()
            ->with('product.mainImage')
            ->findOrFail($request->integer('product_variant_id'));

        $guestToken = $request->user()
            ? null
            : $guestTokenResolver->resolve($request);

        $cart = $cartService->getOrCreateCart(
            $request->user(),
            $guestToken,
            $variant->currency?->value ?? 'PLN'
        );

        $cartService->addItem(
            $cart,
            $variant,
            $request->integer('quantity')
        );

        $response = redirect()
            ->route('cart.show')
            ->with('success', 'Product added to cart.');

        if (! $request->user() && $guestToken !== null) {
            $response->withCookie(
                $guestTokenResolver->makeCookie($guestToken)
            );
        }

        return $response;
    }
}
