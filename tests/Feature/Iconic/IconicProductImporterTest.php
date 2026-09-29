<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Iconic\IconicProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function iconicPricedFixture(): array
{
    return [
        'source' => 'iconic',
        'source_url' => 'https://sklep.iconic.pl/produkty/relief-dual.html',
        'canonical_url' => 'https://sklep.iconic.pl/produkty/relief-dual.html',
        'external_product_id' => 'relief-dual',
        'slug' => 'relief-dual',
        'name' => 'But Pooperacyjny DARCO - Relief Dual',
        'price_gross_amount' => 176.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'is_on_order' => false,
        'shipping_time' => '24 h - 2 dni',
        'unit' => 'sztuka',
        'catalogue_number' => 'RD-M1',
        'source_category_path' => [
            'Zaopatrzenie Stopy Cukrzycowej',
            'DARCO - Obuwie odciążające i pooperacyjne',
        ],
        'categories' => [
            'Zaopatrzenie Stopy Cukrzycowej',
            'DARCO - Obuwie odciążające i pooperacyjne',
        ],
        'description_html' => '<h2>Relief Dual</h2><p>Opis produktu.</p>',
        'description_plain' => 'Relief Dual Opis produktu.',
        'seo_title' => 'Relief Dual',
        'seo_description' => 'But pooperacyjny.',
        'images' => [],
        'attributes' => [
            ['label' => 'Jednostka miary', 'value' => 'sztuka'],
            ['label' => 'Numer katalogowy', 'value' => 'RD-M1'],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => '1195',
                'label' => 'Rozmiar: MS',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'MS'],
                ],
                'price_gross_amount' => 176.0,
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => '1196',
                'label' => 'Rozmiar: MM',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'MM'],
                ],
                'price_gross_amount' => 176.0,
                'currency' => 'PLN',
            ],
        ],
        'is_medical_device' => true,
        'medical_device_class' => 'I',
        'raw_context' => [
            'listing_roots' => [
                'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej',
                'https://sklep.iconic.pl/produkty/zaopatrzenie-ortopedyczne-stopy',
            ],
        ],
    ];
}

it('imports an Iconic product idempotently with approved multi-root taxonomy and deterministic internal SKUs', function (): void {
    $importer = app(IconicProductImporter::class);
    $fixture = iconicPricedFixture();

    $first = $importer->import($fixture, VatRate::VAT_8, false);
    $second = $importer->import($fixture, VatRate::VAT_8, false);

    expect(Product::query()->where('external_source', 'iconic')->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(2);

    $product = $second['product']->fresh([
        'categories.parent',
        'attributeValues.attribute',
        'variants.attributeValues.attribute',
    ]);

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_id)->toBe('relief-dual')
        ->and($product->external_parent_sku)->toBe('ICO-RELIEF-DUAL')
        ->and($product->variants)->toHaveCount(2)
        ->and($product->variants->pluck('sku')->all())->toBe([
            'ICO-RELIEF-DUAL-1195',
            'ICO-RELIEF-DUAL-1196',
        ])
        ->and($product->variants->every(
            fn (ProductVariant $variant): bool =>
                $variant->status === ProductVariantStatus::DRAFT
                && $variant->vat_rate === VatRate::VAT_8
                && $variant->price_gross_amount === 17600
        ))->toBeTrue();

    expect(Category::query()->whereNull('parent_id')->pluck('name')->all())
        ->toContain(
            'Zaopatrzenie Stopy Cukrzycowej',
            'Zaopatrzenie Ortopedyczne Stopy',
        );

    $leafCategories = $product->categories
        ->filter(fn (Category $category): bool => $category->name === 'DARCO - Obuwie odciążające i pooperacyjne');

    expect($leafCategories)->toHaveCount(2)
        ->and($product->categories->where('pivot.is_primary', true))->toHaveCount(1);

    $attributePairs = $product->attributeValues
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($attributePairs)
        ->toContain(
            'Numer katalogowy=RD-M1',
            'Jednostka miary=sztuka',
            'Wyrób medyczny=Tak',
            'Klasa wyrobu medycznego=I',
        )
        ->not->toContain('Producent=ICONIC');

    expect($first['warnings'])->toBe([])
        ->and($second['warnings'])->toBe([]);
});

it('rejects Iconic on-order or unpriced products from commerce import', function (): void {
    $fixture = iconicPricedFixture();
    $fixture['external_product_id'] = 'regeneracja-narzedzi';
    $fixture['slug'] = 'regeneracja-narzedzi';
    $fixture['name'] = 'Regeneracja narzędzi';
    $fixture['price_gross_amount'] = null;
    $fixture['is_on_order'] = true;
    $fixture['availability'] = 'on_order';

    expect(fn () => app(IconicProductImporter::class)->import(
        $fixture,
        VatRate::VAT_23,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'is on-order or has no authoritative gross price',
    );

    expect(Product::query()->where('external_source', 'iconic')->count())->toBe(0);
});

it('requires explicit VAT for an eligible Iconic commerce product', function (): void {
    expect(fn () => app(IconicProductImporter::class)->import(
        iconicPricedFixture(),
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'Iconic VAT rate is not explicit',
    );

    expect(Product::query()->where('external_source', 'iconic')->count())->toBe(0);
});
