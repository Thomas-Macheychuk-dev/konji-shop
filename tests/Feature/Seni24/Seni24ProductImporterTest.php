<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
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

it('imports each resolved Seni24 variant with its own stock state', function (): void {
    $fixture = seni24PricedFixture();

    $fixture['variant_candidates'] = [
        [
            'external_variant_id' => '18630',
            'label' => 'Rozmiar: 43-46, Kolor: Czarny',
            'attributes' => [
                ['label' => 'Rozmiar', 'value' => '43-46'],
                ['label' => 'Kolor', 'value' => 'Czarny'],
            ],
            'price_gross_amount' => 23.46,
            'currency' => 'PLN',
            'vat_rate' => 8,
            'availability' => 'out_of_stock',
            'availability_label' => 'Produkt niedostępny',
        ],
        [
            'external_variant_id' => '18632',
            'label' => 'Rozmiar: 43-46, Kolor: Ciemny szary',
            'attributes' => [
                ['label' => 'Rozmiar', 'value' => '43-46'],
                ['label' => 'Kolor', 'value' => 'Ciemny szary'],
            ],
            'price_gross_amount' => 23.46,
            'currency' => 'PLN',
            'vat_rate' => 8,
            'availability' => 'in_stock',
            'availability_label' => 'Produkt dostępny',
        ],
    ];

    $fixture['availability'] = 'out_of_stock';
    $fixture['availability_label'] = 'Produkt niedostępny';
    $fixture['variants_unresolved'] = false;

    app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    );

    $variants = ProductVariant::query()
        ->whereIn('external_variant_id', [
            'seni24-338-18630',
            'seni24-338-18632',
        ])
        ->get()
        ->keyBy('external_variant_id');

    expect($variants)->toHaveCount(2)
        ->and($variants['seni24-338-18630']->stock_status)
        ->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variants['seni24-338-18632']->stock_status)
        ->toBe(StockStatus::IN_STOCK)
        ->and($variants['seni24-338-18630']->is_default)
        ->toBeFalse()
        ->and($variants['seni24-338-18632']->is_default)
        ->toBeTrue();
});

it('canonicalizes the Seni24 size-table alias without duplicating variant size values', function (): void {
    $fixture = seni24PricedFixture();

    $fixture['variant_candidates'][0]['attributes'] = [
        ['label' => 'Rozmiar', 'value' => 'S'],
        [
            'label' => 'Rozmiar (Tabela rozmiarów)',
            'value' => 'S',
        ],
        [
            'label' => 'Ilość sztuk',
            'value' => '10 szt.',
        ],
    ];

    app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    );

    $variant = ProductVariant::query()
        ->with('attributeValues.attribute')
        ->firstOrFail();

    $attributes = $variant->attributeValues
        ->map(
            fn ($value): string =>
                $value->attribute->name.'='.$value->value
        )
        ->sort()
        ->values()
        ->all();

    expect($attributes)
        ->toContain('Rozmiar=S')
        ->toContain('Ilość sztuk=10 szt.')
        ->not->toContain(
            'Rozmiar (Tabela rozmiarów)=S',
        )
        ->toHaveCount(2);
});
