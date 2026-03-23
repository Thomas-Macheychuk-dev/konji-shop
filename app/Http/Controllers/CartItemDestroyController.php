<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Services\Cart\CartOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartItemDestroyController extends Controller
{
    public function __invoke(
        Request $request,
        CartItem $cartItem,
        CartOwnershipService $ownershipService
    ): RedirectResponse {
        abort_unless($ownershipService->userCanAccessCartItem($request, $cartItem), 403);

        $cartItem->delete();

        return redirect()
            ->route('cart.show')
            ->with('success', 'Item removed from cart.');
    }
}
