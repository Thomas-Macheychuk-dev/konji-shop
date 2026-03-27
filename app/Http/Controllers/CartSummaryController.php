<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartPricingService;
use App\Services\Cart\CartService;
use App\Support\Cart\CartSummaryData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartSummaryController extends Controller
{
    public function __invoke(
        Request $request,
        CartGuestTokenResolver $guestTokenResolver,
        CartService $cartService,
        CartPricingService $cartPricingService,
    ): JsonResponse {
        $guestToken = $guestTokenResolver->resolve($request);

        $cart = $cartService->findActiveCart(
            $request->user(),
            $guestToken,
        );

        if ($cart) {
            $cart->loadMissing([
                'items.product.attributeValueImages.attributeValue',
                'items.variant.attributeValues.attribute',
            ]);
        }

        $totals = $cartPricingService->calculate($cart);

        return response()->json(
            CartSummaryData::fromCart($cart, $totals)
        );
    }
}
