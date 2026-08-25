<?php

declare(strict_types=1);

use App\Console\Commands\ImportArmedicalProductsCommand;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Armedical\ArmedicalProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('imports a fully priced ARmedical product as a draft with exact per-variant price VAT categories and no media downloads', function (): void {
    $mapped = armedicalImporterPricedProduct();
    $product = app(ArmedicalProductImporter::class)->import($mapped)['product'];

    expect($product->external_source)->toBe('armedical')
        ->and($product->external_id)->toBe('armedical-test-brace')
        ->and($product->external_parent_sku)->toBe('AR-TEST')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->published_at)->toBeNull()
        ->and($product->images()->count())->toBe(0)
        ->and($product->short_description)->toContain('wyrób medyczny')
        ->and($product->description)
        ->toContain('Opis właściwy produktu')
        ->toContain('Materiały producenta')
        ->toContain('instrukcja.pdf')
        ->not->toContain('breadcrumbs')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $categoryNames = $product->categories()->pluck('categories.name')->all();
    $primary = $product->categories()->wherePivot('is_primary', true)->first();

    expect($categoryNames)->toContain('Produkty ortopedyczne', 'Stabilizatory palców')
        ->and($primary?->name)->toBe('Stabilizatory palców');

    $productAttributes = $product->attributeValues()->with('attribute')->get();
    $producer = $productAttributes->first(fn ($value): bool => $value->attribute->name === 'Producent');
    $brand = $productAttributes->first(fn ($value): bool => $value->attribute->name === 'Marka');

    expect($producer?->value)->toBe('ARMEDICAL Sp. z o.o.')
        ->and($brand?->value)->toBe('ARmedical');

    $variants = $product->variants()->get()->keyBy('external_variant_id');
    $small = $variants->get('armedical-test-brace-s');
    $medium = $variants->get('armedical-test-brace-m');

    expect($variants)->toHaveCount(2)
        ->and($variants->every(fn ($variant): bool => $variant->status === ProductVariantStatus::DRAFT))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->stock_status === StockStatus::OUT_OF_STOCK))->toBeTrue()
        ->and($variants->where('is_default', true))->toHaveCount(1)
        ->and($small?->price_net_amount)->toBe(10000)
        ->and($small?->price_gross_amount)->toBe(10800)
        ->and($small?->vat_rate)->toBe(VatRate::VAT_8)
        ->and($medium?->price_net_amount)->toBe(12000)
        ->and($medium?->price_gross_amount)->toBe(14760)
        ->and($medium?->vat_rate)->toBe(VatRate::VAT_23);

    expect($small?->attributeValues()->with('attribute')->first()?->attribute->name)->toBe('Wariant')
        ->and($small?->attributeValues()->first()?->value)->toBe('S');
});


it('preserves another supplier product and deterministically resolves an ARmedical product slug collision', function (): void {
    $existing = Product::query()->create([
        'name' => 'Existing supplier product',
        'slug' => 'stabilizator-testowy-armedical',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'other-source',
        'external_id' => 'other-source-product',
    ]);

    $product = app(ArmedicalProductImporter::class)->import(armedicalImporterPricedProduct())['product'];

    expect($existing->fresh()?->slug)->toBe('stabilizator-testowy-armedical')
        ->and($product->slug)->toBe('stabilizator-testowy-armedical-armedical-57ef397b16')
        ->and($product->external_source)->toBe('armedical')
        ->and($product->external_id)->toBe('armedical-test-brace');
});

it('keeps ARmedical local imports idempotent and archives variants removed from a later fully priced map', function (): void {
    $importer = app(ArmedicalProductImporter::class);
    $first = $importer->import(armedicalImporterPricedProduct())['product'];
    $firstId = $first->id;

    $updated = armedicalImporterPricedProduct();
    $updated['variants'] = [$updated['variants'][0]];
    $updated['pricing']['variant_pricing_complete'] = true;

    $second = $importer->import($updated)['product'];
    $variants = $second->variants()->orderBy('external_variant_id')->get();

    expect($second->id)->toBe($firstId)
        ->and(Product::query()->where('external_source', 'armedical')->where('external_id', 'armedical-test-brace')->count())->toBe(1)
        ->and($variants)->toHaveCount(2)
        ->and($variants->where('status', ProductVariantStatus::DRAFT))->toHaveCount(1)
        ->and($variants->where('status', ProductVariantStatus::ARCHIVED))->toHaveCount(1)
        ->and($variants->where('is_default', true))->toHaveCount(1);
});

it('rejects ARmedical rows with unresolved supplier pricing or blocking source review items', function (): void {
    $importer = app(ArmedicalProductImporter::class);
    $unpriced = armedicalImporterPricedProduct();
    $unpriced['variants'][1]['price_net_minor'] = null;
    $unpriced['variants'][1]['price_gross_minor'] = null;
    $unpriced['variants'][1]['vat_rate'] = null;
    $unpriced['variants'][1]['pricing_resolution'] = [
        'status' => 'unmatched',
        'reason' => 'No deterministic supplier price-list code match.',
    ];

    expect(fn () => $importer->import($unpriced))
        ->toThrow(InvalidArgumentException::class, 'not fully resolved');

    $blocked = armedicalImporterPricedProduct();
    $blocked['blocking_review_items'] = ['source option label 82104 is ambiguous'];

    expect(fn () => $importer->import($blocked))
        ->toThrow(InvalidArgumentException::class, 'blocking review items');
});

it('runs ARmedical import-products read-only by default and blocks writes from any priced map other than the frozen approved SHA', function (): void {
    $relativePath = 'scrapers/armedical/import-products-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    @unlink($path);

    $eligible = armedicalImporterPricedProduct();
    $unresolved = armedicalImporterPricedProduct();
    $unresolved['product']['external_id'] = 'armedical-unresolved';
    $unresolved['product']['catalogue_number'] = 'AR-UNRESOLVED';
    $unresolved['product']['external_parent_sku'] = 'AR-UNRESOLVED';
    $unresolved['variants'][0]['pricing_resolution']['status'] = 'unmatched';
    $unresolved['variants'][0]['price_net_minor'] = null;
    $unresolved['variants'][0]['price_gross_minor'] = null;
    $unresolved['variants'][0]['vat_rate'] = null;

    file_put_contents($path, json_encode([
        'source' => 'armedical',
        'products' => [$eligible, $unresolved],
        'pricing_summary' => [
            'planned_variants' => 4,
            'matched_variants' => 3,
            'unmatched_variants' => 1,
            'fully_priced_products' => 1,
            'unpriced_products' => 1,
        ],
        'supplier_price_list' => [
            'metadata' => [
                'source_sha256' => 'fixture',
            ],
        ],
        'review_items' => ['Fixture review item'],
    ], JSON_THROW_ON_ERROR));

    try {
        $dryExit = Artisan::call('armedical:import-products', [
            '--from' => $relativePath,
            '--show-excluded' => true,
        ]);
        $dryOutput = Artisan::output();

        expect($dryExit)->toBe(0)
            ->and($dryOutput)->toContain('Database writes: NO')
            ->and($dryOutput)->toContain('Fully priced eligible products: 1')
            ->and($dryOutput)->toContain('Excluded unresolved products: 1')
            ->and($dryOutput)->toContain('Products to create/update: 1')
            ->and(Product::query()->where('external_source', 'armedical')->exists())->toBeFalse();

        $writeExit = Artisan::call('armedical:import-products', [
            '--from' => $relativePath,
            '--write' => true,
        ]);
        $writeOutput = Artisan::output();

        expect($writeExit)->toBe(1)
            ->and($writeOutput)->toContain('BLOCKED: ARmedical priced-map SHA-256 does not match the frozen approved fingerprint')
            ->and($writeOutput)->toContain(ImportArmedicalProductsCommand::APPROVED_PRICED_MAP_SHA256)
            ->and(Product::query()->where('external_source', 'armedical')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

/** @return array<string, mixed> */
function armedicalImporterPricedProduct(): array
{
    return [
        'source' => 'armedical',
        'source_url' => 'https://armedical.pl/oferta/test-brace/',
        'canonical_url' => 'https://armedical.pl/oferta/test-brace/',
        'product' => [
            'external_source' => 'armedical',
            'external_id' => 'armedical-test-brace',
            'external_parent_sku' => 'AR-TEST',
            'catalogue_number' => 'AR-TEST',
            'source_sku' => 'AR-TEST',
            'name' => 'Stabilizator testowy ARmedical',
            'slug' => 'stabilizator-testowy-armedical',
            'status' => 'draft',
            'short_description_html' => '<p>To jest wyrób medyczny.</p>',
            'description_html' => <<<'HTML'
<section id="breadcrumbs">home > Oferta</section>
<div class="post-content"><p>Opis właściwy produktu.</p><script>alert(1)</script><img src="https://armedical.pl/test.jpg"></div>
<section class="additional-informations"><div class="product-info"><p><strong>Materiał:</strong> aluminium</p></div></section>
HTML,
            'seo_title' => 'Stabilizator testowy ARmedical',
            'seo_description' => 'Testowy stabilizator medyczny.',
            'brand' => 'ARmedical',
            'manufacturer' => 'ARMEDICAL Sp. z o.o.',
        ],
        'medical_device' => [
            'is_medical_device' => true,
            'source_statement' => 'To jest wyrób medyczny.',
        ],
        'pricing' => [
            'currency' => 'PLN',
            'variant_pricing_complete' => true,
            'mixed_variant_pricing' => true,
            'requires_review' => false,
            'source' => 'armedical_supplier_price_list_2026_03_04',
        ],
        'availability' => [
            'status' => 'unknown',
            'label' => null,
            'stock_quantity' => null,
        ],
        'categories' => [[
            'path' => ['Produkty ortopedyczne', 'Stabilizatory palców'],
            'source_categories' => ['Stabilizatory palców', 'Produkty ortopedyczne'],
        ]],
        'technical_specifications' => [
            ['label' => 'Materiał', 'value' => 'aluminium'],
        ],
        'attributes' => [
            'Materiał' => 'aluminium',
        ],
        'source_option_count' => 2,
        'variants' => [
            [
                'source_external_variant_id' => null,
                'external_variant_id' => 'armedical-test-brace-s',
                'sku' => 'ARMEDICAL-AR-TEST-S',
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => 10800,
                'price_net_minor' => 10000,
                'currency' => 'PLN',
                'vat_rate' => 8,
                'stock_status' => 'unknown',
                'attributes' => [[
                    'code' => 'wariant',
                    'label' => 'Wariant',
                    'value' => 's',
                    'value_label' => 'S',
                ]],
                'source_option_label' => 'S',
                'source_option_value' => '24,1 ÷ 26,7 cm',
                'pricing_resolution' => [
                    'status' => 'matched',
                    'price_code' => 'AR-TEST',
                    'match_strategy' => 'parent_code',
                ],
            ],
            [
                'source_external_variant_id' => null,
                'external_variant_id' => 'armedical-test-brace-m',
                'sku' => 'ARMEDICAL-AR-TEST-M',
                'status' => 'draft',
                'is_default' => false,
                'price_gross_minor' => 14760,
                'price_net_minor' => 12000,
                'currency' => 'PLN',
                'vat_rate' => 23,
                'stock_status' => 'unknown',
                'attributes' => [[
                    'code' => 'wariant',
                    'label' => 'Wariant',
                    'value' => 'm',
                    'value_label' => 'M',
                ]],
                'source_option_label' => 'M',
                'source_option_value' => '26,8 ÷ 29,4 cm',
                'pricing_resolution' => [
                    'status' => 'matched',
                    'price_code' => 'AR-TEST-M',
                    'match_strategy' => 'variant_code',
                ],
            ],
        ],
        'images' => [[
            'source_url' => 'https://armedical.pl/wp-content/uploads/test.jpg',
            'alt' => null,
            'is_primary' => true,
            'download' => false,
        ]],
        'documents' => [[
            'source_url' => 'https://armedical.pl/wp-content/uploads/instrukcja.pdf',
            'label' => 'Instrukcja obsługi',
            'type' => 'manual',
            'download' => false,
        ]],
        'errors' => [],
        'review_items' => [],
        'blocking_review_items' => [],
    ];
}
