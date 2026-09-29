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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function seni24ImporterFixture(): array
{
    return [
        'source' => 'seni24',
        'source_url' => 'https://www.seni24.pl/seni-super-test_24170-26340',
        'canonical_url' => 'https://www.seni24.pl/seni-super-test_24170-26340',
        'external_product_id' => '24170',
        'slug' => 'seni-super-test',
        'name' => 'Seni Super test',
        'brand' => 'Seni',
        'price_gross_amount' => 19.25,
        'currency' => 'PLN',
        'vat_rate' => 5,
        'availability' => 'in_stock',
        'availability_label' => 'Produkt dostępny',
        'source_category_path' => [
            'Nietrzymanie moczu',
            'Pieluchomajtki',
        ],
        'categories' => [
            'Nietrzymanie moczu',
            'Pieluchomajtki',
        ],
        'description_html' => '<p>Opis produktu Seni Super.</p>',
        'description_plain' => 'Opis produktu Seni Super.',
        'seo_title' => 'Seni Super test',
        'seo_description' => 'Testowy produkt Seni Super.',
        'images' => [],
        'attributes' => [
            ['label' => 'Producent', 'value' => 'TZMO S.A.'],
            ['label' => 'Chłonność', 'value' => '7/9'],
        ],
        'variant_resolution_complete' => true,
        'variant_candidates' => [
            [
                'external_variant_id' => '26340',
                'label' => 'Rozmiar: XS, Ilość sztuk: 10 szt.',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'XS'],
                    ['label' => 'Ilość sztuk', 'value' => '10 szt.'],
                    ['label' => 'Indeks', 'value' => 'SE-094-XS10-G01'],
                    ['label' => 'EAN', 'value' => '5900516803704'],
                ],
                'supplier_sku' => 'SE-094-XS10-G01',
                'ean13' => '5900516803704',
                'price_gross_amount' => 19.25,
                'currency' => 'PLN',
                'vat_rate' => 5,
                'availability' => 'in_stock',
            ],
            [
                'external_variant_id' => '26341',
                'label' => 'Rozmiar: S, Ilość sztuk: 10 szt.',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => 'S'],
                    ['label' => 'Ilość sztuk', 'value' => '10 szt.'],
                    ['label' => 'Indeks', 'value' => 'SE-094-S10-G01'],
                    ['label' => 'EAN', 'value' => '5900516803711'],
                ],
                'supplier_sku' => 'SE-094-S10-G01',
                'ean13' => '5900516803711',
                'price_gross_amount' => 20.75,
                'currency' => 'PLN',
                'vat_rate' => 5,
                'availability' => 'out_of_stock',
            ],
        ],
        'is_medical_device' => true,
        'medical_device_class' => null,
        'is_refundable' => true,
        'raw_context' => [
            'listing_roots' => [
                'https://www.seni24.pl/nietrzymanie-moczu/',
            ],
            'listing_pages' => [
                'https://www.seni24.pl/nietrzymanie-moczu/pieluchomajtki/',
            ],
        ],
        'warnings' => [],
        'failed_urls' => [],
    ];
}

it('imports a fully resolved Seni24 product idempotently as draft with deterministic identity', function (): void {
    $importer = app(Seni24ProductImporter::class);
    $fixture = seni24ImporterFixture();

    $first = $importer->import($fixture, null, false);
    $second = $importer->import($fixture, null, false);

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(2);

    $product = $second['product']->fresh([
        'categories.parent',
        'attributeValues.attribute',
        'variants.attributeValues.attribute',
    ]);

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_id)->toBe('24170')
        ->and($product->external_parent_sku)->toBe('S24-24170')
        ->and($product->variants)->toHaveCount(2)
        ->and($product->variants->pluck('sku')->all())->toBe([
            'S24-24170-26340',
            'S24-24170-26341',
        ])
        ->and($product->variants->every(
            fn (ProductVariant $variant): bool =>
                $variant->status === ProductVariantStatus::DRAFT
        ))->toBeTrue();

    $firstVariant = $product->variants->firstWhere(
        'external_variant_id',
        'seni24-24170-26340',
    );
    $secondVariant = $product->variants->firstWhere(
        'external_variant_id',
        'seni24-24170-26341',
    );

    expect($firstVariant)->not->toBeNull()
        ->and($firstVariant->price_gross_amount)->toBe(1925)
        ->and($firstVariant->vat_rate)->toBe(VatRate::VAT_5)
        ->and($firstVariant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($secondVariant)->not->toBeNull()
        ->and($secondVariant->price_gross_amount)->toBe(2075)
        ->and($secondVariant->vat_rate)->toBe(VatRate::VAT_5)
        ->and($secondVariant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);

    expect(Category::query()->whereNull('parent_id')->pluck('name')->all())
        ->toContain('Nietrzymanie moczu')
        ->and($product->categories->where('pivot.is_primary', true))->toHaveCount(1);

    $productAttributes = $product->attributeValues
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($productAttributes)
        ->toContain(
            'Marka=Seni',
            'Wyrób medyczny=Tak',
            'Refundowany=Tak',
            'Producent=TZMO S.A.',
            'Chłonność=7/9',
        );

    expect($first['warnings'])->toBe([])
        ->and($second['warnings'])->toBe([]);
});

it('uses explicit per-variant Seni24 VAT before product or fallback VAT', function (): void {
    $fixture = seni24ImporterFixture();
    $fixture['vat_rate'] = 23;
    $fixture['variant_candidates'][0]['vat_rate'] = 5;
    $fixture['variant_candidates'][1]['vat_rate'] = 8;

    $product = app(Seni24ProductImporter::class)
        ->import($fixture, VatRate::VAT_23, false)['product']
        ->fresh('variants');

    $rates = $product->variants
        ->sortBy('external_variant_id')
        ->pluck('vat_rate')
        ->map(fn (VatRate $rate): int => $rate->value)
        ->values()
        ->all();

    expect($rates)->toBe([5, 8]);
});

it('rejects incomplete Seni24 variant resolution before any database write', function (): void {
    $fixture = seni24ImporterFixture();
    $fixture['variant_resolution_complete'] = false;

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'does not have a complete resolved variant matrix',
    );

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('rejects a Seni24 variant without explicit VAT before any database write', function (): void {
    $fixture = seni24ImporterFixture();
    $fixture['vat_rate'] = null;
    $fixture['variant_candidates'][0]['vat_rate'] = null;

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'VAT rate is not explicit',
    );

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('requires approved Seni24 root provenance before category writes', function (): void {
    $fixture = seni24ImporterFixture();
    $fixture['raw_context']['listing_roots'] = [];

    expect(fn () => app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'missing approved root provenance',
    );

    expect(Product::query()->where('external_source', 'seni24')->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0);
});

it('imports Seni24 images from Seni24 subdomains only', function (): void {
    Storage::fake('public');

    $fixture = seni24ImporterFixture();
    $fixture['images'] = [[
        'url' => 'https://aws-test-seni24.seni24.pl/100-large_default/seni-super-test.jpg',
        'alt' => 'Seni Super test',
    ]];

    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
        true,
    );

    expect($png)->not->toBeFalse();

    Http::fake([
        'https://aws-test-seni24.seni24.pl/*' => Http::response(
            $png,
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $result = app(Seni24ProductImporter::class)->import(
        $fixture,
        null,
        true,
        10,
    );

    $image = $result['product']->images->first();

    expect($result['warnings'])->toBe([])
        ->and($image)->not->toBeNull()
        ->and($image->source_url)->toBe(
            'https://aws-test-seni24.seni24.pl/100-large_default/seni-super-test.jpg',
        )
        ->and($image->alt_text)->toBe('Seni Super test');

    Storage::disk('public')->assertExists($image->path);
});
