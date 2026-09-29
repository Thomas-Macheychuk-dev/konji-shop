<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Seni24\Seni24ProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seni24PricedFixture(): array
{
    return [
        'source' => 'seni24',
        'source_url' => 'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
        'canonical_url' => 'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
        'external_product_id' => '338',
        'slug' => 'kubek-pojnik-z-ustnikiem-200-ml',
        'name' => 'Kubek pojnik z ustnikiem 200 ml',
        'price_gross_amount' => 9.39,
        'currency' => 'PLN',
        'vat_rate' => 8,
        'availability' => 'in_stock',
        'availability_label' => 'Produkt dostępny',
        'is_on_order' => false,
        'shipping_time' => '24-48h',
        'unit' => 'szt.',
        'catalogue_number' => 'NN-SRW-AHK1-001',
        'ean' => '5905279578104',
        'source_category_path' => ['Pomoce codzienne', 'Akcesoria kuchenne'],
        'categories' => ['Pomoce codzienne', 'Akcesoria kuchenne'],
        'category' => 'Akcesoria kuchenne',
        'description_html' => '<p>Opis produktu.</p>',
        'description_plain' => 'Opis produktu.',
        'images' => [],
        'attributes' => [
            ['label' => 'Indeks', 'value' => 'NN-SRW-AHK1-001'],
            ['label' => 'ean13', 'value' => '5905279578104'],
        ],
        'variant_candidates' => [[
            'external_variant_id' => '16501',
            'label' => 'Pojemność: 200 ml',
            'attributes' => [['label' => 'Pojemność', 'value' => '200 ml']],
            'price_gross_amount' => 9.39,
            'currency' => 'PLN',
            'vat_rate' => 8,
        ]],
        'variants_unresolved' => false,
        'is_medical_device' => true,
        'medical_device_class' => null,
        'raw_context' => [
            'listing_roots' => ['https://www.seni24.pl/strona-glowna/'],
        ],
    ];
}

it('imports a resolved Seni24 product idempotently as draft using source VAT', function (): void {
    $importer = app(Seni24ProductImporter::class);
    $fixture = seni24PricedFixture();

    $first = $importer->import($fixture, null, false);
    $second = $importer->import($fixture, null, false);

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(1);

    $product = $second['product']->fresh(['categories.parent', 'variants.attributeValues.attribute']);

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_id)->toBe('338')
        ->and($product->external_parent_sku)->toBe('S24-338')
        ->and($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($product->variants->first()->vat_rate)->toBe(VatRate::VAT_8)
        ->and($product->variants->first()->price_gross_amount)->toBe(939)
        ->and($product->variants->first()->sku)->toBe('S24-338-16501');

    expect(Category::query()->whereNull('parent_id')->pluck('name')->all())
        ->toContain('Seni24');

    expect($first['warnings'])->toBe([])
        ->and($second['warnings'])->toBe([]);
});

it('rejects Seni24 products with unresolved source variants', function (): void {
    $fixture = seni24PricedFixture();
    $fixture['variants_unresolved'] = true;

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'has unresolved source variants',
    );

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(0);
});

it('requires explicit Seni24 VAT when source VAT is absent', function (): void {
    $fixture = seni24PricedFixture();
    unset($fixture['vat_rate']);

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'Seni24 VAT rate is not explicit',
    );
});
