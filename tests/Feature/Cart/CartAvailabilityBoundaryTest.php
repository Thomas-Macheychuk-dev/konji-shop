<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartService;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class CartAvailabilityBoundaryTest extends TestCase
{
    /** @return array{Cart, ProductVariant} */
    private function purchaseCandidate(): array
    {
        $product = new Product([
            'name' => 'Test', 'status' => ProductStatus::ACTIVE,
            'published_at' => Carbon::now(),
        ]);
        $variant = new ProductVariant([
            'status' => ProductVariantStatus::ACTIVE,
            'stock_status' => StockStatus::IN_STOCK,
            'price_gross_amount' => 1299,
            'price_net_amount' => 1203,
            'vat_rate' => VatRate::VAT_8,
            'currency' => Currency::PLN,
        ]);
        $variant->setRelation('product', $product);
        $cart = new Cart;
        $cart->currency = 'PLN';
        return [$cart, $variant];
    }

    public function test_rejects_draft_product(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $variant->product->status = ProductStatus::DRAFT;
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }

    public function test_rejects_unpublished_product(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $variant->product->published_at = null;
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }

    public function test_rejects_draft_variant(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $variant->status = ProductVariantStatus::DRAFT;
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }

    public function test_rejects_out_of_stock_variant(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $variant->stock_status = StockStatus::OUT_OF_STOCK;
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }

    public function test_rejects_missing_tax(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $variant->vat_rate = null;
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }

    public function test_rejects_currency_mismatch(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $cart->currency = 'EUR';
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }

    public function test_rejects_missing_price(): void
    {
        [$cart, $variant] = $this->purchaseCandidate();
        $variant->price_gross_amount = null;
        $variant->price_net_amount = null;
        $this->expectException(RuntimeException::class);
        app(CartService::class)->addItem($cart, $variant);
    }
}
