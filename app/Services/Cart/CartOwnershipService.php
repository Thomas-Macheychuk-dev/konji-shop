<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Models\CartItem;
use Illuminate\Http\Request;

class CartOwnershipService
{
    public function userCanAccessCartItem(Request $request, CartItem $cartItem): bool
    {
        $cart = $cartItem->cart;

        if ($request->user()) {
            return (int) $cart->user_id === (int) $request->user()->id;
        }

        $guestToken = $request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        return $cart->guest_token !== null
            && hash_equals($cart->guest_token, (string) $guestToken);
    }
}
