<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Http\Requests\AddCartItemRequest;
use App\Models\ProductVariant;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use App\Services\Products\VermeirenColorSelectionService;
use Illuminate\Http\RedirectResponse;

class CartItemStoreController extends Controller
{
    public function __invoke(
        AddCartItemRequest $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver,
        VermeirenColorSelectionService $colorSelectionService,
    ): RedirectResponse {
        $variant = ProductVariant::query()
            ->with([
                'attributeValues.productImage',
                'product.mainImage',
                'product.images',
                'product.attributeValueImages',
                'product.attributeValues.attribute',
            ])
            ->findOrFail($request->integer('product_variant_id'));

        if ($variant->status !== ProductVariantStatus::ACTIVE) {
            return back()
                ->withErrors([
                    'product_variant_id' => 'This product variant is not available.',
                ])
                ->withInput();
        }

        if (! $variant->product || $variant->product->status !== ProductStatus::ACTIVE) {
            return back()
                ->withErrors([
                    'product_variant_id' => 'This product is not available.',
                ])
                ->withInput();
        }

        if ($variant->stock_status === StockStatus::OUT_OF_STOCK) {
            return back()
                ->withErrors([
                    'product_variant_id' => 'Ten wariant produktu jest niedostępny.',
                ])
                ->withInput();
        }

        $selectedColors = $colorSelectionService->resolve(
            $variant->product,
            $request->input('informational_colors', []),
        );

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
            $request->integer('quantity'),
            $selectedColors,
        );

        $response = redirect()
            ->route('cart.show')
            ->with('success', 'Produkt dodany do koszyka.');

        if (! $request->user() && $guestToken !== null) {
            $response->withCookie(
                $guestTokenResolver->makeCookie($guestToken)
            );
        }

        return $response;
    }
}
