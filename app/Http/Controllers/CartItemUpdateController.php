<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Services\Cart\CartOwnershipService;
use Illuminate\Http\RedirectResponse;

class CartItemUpdateController extends Controller
{
    public function __invoke(
        UpdateCartItemRequest $request,
        CartItem $cartItem,
        CartOwnershipService $ownershipService
    ): RedirectResponse {
        abort_unless($ownershipService->userCanAccessCartItem($request, $cartItem), 403);

        $cartItem->update([
            'quantity' => $request->integer('quantity'),
        ]);

        return redirect()
            ->route('cart.show')
            ->with('success', 'Cart updated successfully.');
    }
}
