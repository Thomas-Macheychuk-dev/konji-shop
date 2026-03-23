<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Enums\CartStatus;
use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CartService
{
    public function getOrCreateCart(?User $user, ?string $guestToken, string $currency = 'PLN'): Cart
    {
        if ($user) {
            return Cart::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'status' => CartStatus::Active->value,
                ],
                [
                    'currency' => $currency,
                ]
            );
        }

        if (! $guestToken) {
            throw new \InvalidArgumentException('Guest token is required for guest carts.');
        }

        return Cart::query()->firstOrCreate(
            [
                'guest_token' => $guestToken,
                'status' => CartStatus::Active->value,
            ],
            [
                'currency' => $currency,
            ]
        );
    }

    public function addItem(Cart $cart, ProductVariant $variant, int $quantity = 1): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        DB::transaction(function () use ($cart, $variant, $quantity) {
            $existingItem = $cart->items()
                ->where('product_variant_id', $variant->id)
                ->lockForUpdate()
                ->first();

            if ($existingItem) {
                $existingItem->increment('quantity', $quantity);

                return;
            }

            $product = $variant->product;

            $cart->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => $quantity,
                'unit_price' => $variant->grossPriceAmount(),
                'currency' => $variant->currency?->value ?? $cart->currency,
                'meta' => [
                    'product_name' => $product->name,
                    'variant_sku' => $variant->sku,
                    'image_url' => $variant->images[0]['url'] ?? null,
                ],
            ]);
        });
    }

    public function findActiveCart(?User $user, ?string $guestToken): ?Cart
    {
        if ($user) {
            return Cart::query()
                ->where('user_id', $user->id)
                ->where('status', CartStatus::Active->value)
                ->first();
        }

        if (! $guestToken) {
            return null;
        }

        return Cart::query()
            ->where('guest_token', $guestToken)
            ->where('status', CartStatus::Active->value)
            ->first();
    }

    public function mergeGuestCartIntoUserCart(string $guestToken, User $user): ?Cart
    {
        return DB::transaction(function () use ($guestToken, $user) {
            $guestCart = Cart::query()
                ->with('items')
                ->where('guest_token', $guestToken)
                ->where('status', CartStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if (! $guestCart) {
                return null;
            }

            $userCart = Cart::query()
                ->with('items')
                ->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'status' => CartStatus::Active->value,
                    ],
                    [
                        'currency' => $guestCart->currency,
                    ]
                );

            foreach ($guestCart->items as $guestItem) {
                $existingUserItem = $userCart->items()
                    ->where('product_variant_id', $guestItem->product_variant_id)
                    ->lockForUpdate()
                    ->first();

                if ($existingUserItem) {
                    $existingUserItem->increment('quantity', $guestItem->quantity);
                    $guestItem->delete();

                    continue;
                }

                $guestItem->update([
                    'cart_id' => $userCart->id,
                ]);
            }

            $guestCart->update([
                'status' => CartStatus::Converted->value,
            ]);

            return $userCart->fresh(['items']);
        });
    }
}
