<?php

declare(strict_types=1);

namespace App\Services\Delivery\Polkurier;

use App\Models\Cart;
use App\Models\Order;
use App\Models\ProductVariant;
use RuntimeException;

final class PolkurierPackBuilder
{
    /**
     * Build Polkurier packs from cart items.
     *
     * First version:
     * - one cart line = one pack definition
     * - pack amount = cart item quantity
     * - variant package dimensions are used when complete
     * - config default pack is used as fallback
     *
     * @return array<int, array<string, int|float|string>>
     */
    public function fromCart(Cart $cart): array
    {
        $cart->loadMissing('items.variant');

        $packs = [];

        foreach ($cart->items as $item) {
            $quantity = max(1, (int) $item->quantity);
            $variant = $item->variant;

            $packs[] = [
                ...$this->packForVariant($variant),
                'amount' => $quantity,
            ];
        }

        return $packs === [] ? [$this->defaultPack()] : $packs;
    }

    /**
     * Build Polkurier packs from order items.
     *
     * First version:
     * - one order line = one pack definition
     * - pack amount = order item quantity
     * - variant package dimensions are used when complete
     * - config default pack is used as fallback
     *
     * @return array<int, array<string, int|float|string>>
     */
    public function fromOrder(Order $order): array
    {
        $order->loadMissing('items.variant');

        $packs = [];

        foreach ($order->items as $item) {
            $quantity = max(1, (int) $item->quantity);
            $variant = $item->variant;

            $packs[] = [
                ...$this->packForVariant($variant),
                'amount' => $quantity,
            ];
        }

        return $packs === [] ? [$this->defaultPack()] : $packs;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function packForVariant(?ProductVariant $variant): array
    {
        if ($variant === null || ! $this->variantHasCompletePackageDimensions($variant)) {
            return $this->defaultPack();
        }

        return [
            'length' => $this->millimetresToCentimetres((int) $variant->package_length_mm),
            'width' => $this->millimetresToCentimetres((int) $variant->package_width_mm),
            'height' => $this->millimetresToCentimetres((int) $variant->package_height_mm),
            'weight' => $this->gramsToKilograms((int) $variant->package_weight_grams),
            'amount' => 1,
            'type' => (string) config('delivery.providers.polkurier.default_pack.type', 'ST'),
        ];
    }

    private function variantHasCompletePackageDimensions(ProductVariant $variant): bool
    {
        return (int) ($variant->package_weight_grams ?? 0) > 0
            && (int) ($variant->package_length_mm ?? 0) > 0
            && (int) ($variant->package_width_mm ?? 0) > 0
            && (int) ($variant->package_height_mm ?? 0) > 0;
    }

    /**
     * @return array<string, int|float|string>
     */
    private function defaultPack(): array
    {
        $pack = config('delivery.providers.polkurier.default_pack');

        if (! is_array($pack)) {
            throw new RuntimeException('Polkurier default pack configuration is missing.');
        }

        return [
            'length' => (int) ($pack['length'] ?? 30),
            'width' => (int) ($pack['width'] ?? 20),
            'height' => (int) ($pack['height'] ?? 10),
            'weight' => (float) ($pack['weight'] ?? 1),
            'amount' => (int) ($pack['amount'] ?? 1),
            'type' => (string) ($pack['type'] ?? 'ST'),
        ];
    }

    private function millimetresToCentimetres(int $millimetres): int
    {
        return max(1, (int) ceil($millimetres / 10));
    }

    private function gramsToKilograms(int $grams): float
    {
        return max(0.001, round($grams / 1000, 3));
    }
}
