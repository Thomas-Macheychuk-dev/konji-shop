<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Services\Cart\CartOwnershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartItemUpdateController extends Controller
{
    public function __invoke(
        Request $request,
        CartItem $cartItem,
        CartOwnershipService $ownershipService
    ): RedirectResponse {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        abort_unless($ownershipService->userCanAccessCartItem($request, $cartItem), 403);

        $cartItem->update([
            'quantity' => $validated['quantity'],
        ]);

        return redirect()
            ->route('cart.show')
            ->with('success', 'Cart updated successfully.');
    }
}
