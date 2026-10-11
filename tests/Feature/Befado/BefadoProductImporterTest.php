<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Befado\BefadoProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function befadoProductFixture(): array
{
    return [
        'source' => 'befado',
        'source_url' => 'https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664',
        'canonical_url' => 'https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664',
        'external_product_id' => '9664',
        'slug' => 'BUTY-DZIECIECE-HONEY-BEFADO-',
        'name' => 'BUTY DZIECIĘCE HONEY BEFADO',
        'brand' => ['name' => 'Befado', 'slug' => 'befado'],
        'price_gross_amount' => 49.90,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'W magazynie',
        'shipping_time' => '48 godzin',
        'sku' => '102X018',
        'categories' => ['Obuwie dziecięce'],
        'category' => 'Obuwie dziecięce',
        'description_html' => '<p>Wygodne dziecięce obuwie Befado.</p>',
        'description' => 'Wygodne dziecięce obuwie Befado.',
        'images' => [],
        'attributes' => [
            ['label' => 'Typ obuwia', 'value' => 'Slip-On'],
            ['label' => 'Kolor', 'value' => 'Różowy'],
            ['label' => 'Forma', 'value' => 'Honey'],
        ],
        'requires_variants' => true,
        'variants_unresolved' => false,
        'variant_candidates' => [
            [
                'external_variant_id' => 'option-502',
                'label' => 'Rozmiar: 24',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => '24'],
                    ['label' => 'Długość wkładki', 'value' => '15.5 cm'],
                ],
                'price_gross_amount' => 49.90,
                'currency' => 'PLN',
                'availability' => 'out_of_stock',
                'availability_label' => 'Niedostępny',
            ],
            [
                'external_variant_id' => 'option-501',
                'label' => 'Rozmiar: 23',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => '23'],
                    ['label' => 'Długość wkładki', 'value' => '15.0 cm'],
                ],
                'price_gross_amount' => 49.90,
                'currency' => 'PLN',
                'availability' => 'in_stock',
                'availability_label' => 'Dostępny',
            ],
        ],
        'is_medical_device' => false,
    ];
}

it('imports Befado products idempotently as draft with per-size stock', function (): void {
    $importer = app(BefadoProductImporter::class);
    $fixture = befadoProductFixture();

    $importer->import($fixture, ProductStatus::DRAFT, false);
    $result = $importer->import($fixture, ProductStatus::DRAFT, false);

    expect(Product::query()->where('external_source', 'befado')->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(2)
        ->and(Category::query()->whereNull('parent_id')->pluck('name')->all())
        ->toContain('Befado');

    $product = $result['product']->fresh(['variants.attributeValues.attribute', 'categories']);

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_id)->toBe('9664')
        ->and($product->external_parent_sku)->toBe('102X018')
        ->and($product->variants)->toHaveCount(2);

    $variants = $product->variants->keyBy('external_variant_id');

    expect($variants['befado-9664-option-502']->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variants['befado-9664-option-502']->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variants['befado-9664-option-502']->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variants['befado-9664-option-502']->price_gross_amount)->toBe(4990)
        ->and($variants['befado-9664-option-502']->is_default)->toBeFalse()
        ->and($variants['befado-9664-option-501']->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($variants['befado-9664-option-501']->is_default)->toBeTrue();
});

it('rejects Befado imports when source variants are unresolved', function (): void {
    $fixture = befadoProductFixture();
    $fixture['variants_unresolved'] = true;
    $fixture['variant_candidates'] = [];

    expect(fn () => app(BefadoProductImporter::class)->import(
        $fixture,
        ProductStatus::DRAFT,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'has unresolved source variants',
    );

    expect(Product::query()->where('external_source', 'befado')->count())->toBe(0);
});
