<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Neoxmed\NeoxmedImportDatabaseAudit;
use App\Services\Neoxmed\NeoxmedImportMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('maps a NeoxMed product to one safe draft placeholder variant without inventing price VAT availability or size variants', function (): void {
    $mapped = app(NeoxmedImportMapper::class)->mapProduct(neoxmedImportProduct());

    expect($mapped['errors'])->toBe([])
        ->and($mapped['product'])->toMatchArray([
            'external_source' => 'neoxmed',
            'external_id' => 'B-01',
            'external_parent_sku' => 'NEOX-B-01',
            'source_code' => 'B-01',
            'source_sku' => 'B-01',
            'name' => 'Kamizelka stawu barkowego',
            'slug' => 'neox-b-01-kamizelka-stawu-barkowego',
            'status' => 'draft',
            'brand' => 'Neox',
            'manufacturer' => 'Neox s.c.',
        ])
        ->and($mapped['pricing'])->toMatchArray([
            'gross_minor' => null,
            'net_minor' => null,
            'vat_rate' => null,
            'currency' => 'PLN',
            'requires_review' => true,
        ])
        ->and($mapped['availability'])->toMatchArray([
            'source_status' => null,
            'planned_stock_status' => 'out_of_stock',
            'requires_review' => true,
        ])
        ->and($mapped['variants'])->toHaveCount(1)
        ->and($mapped['variants'][0])->toMatchArray([
            'external_variant_id' => 'neoxmed-B-01-default',
            'sku' => 'NEOX-B-01',
            'status' => 'draft',
            'is_default' => true,
            'price_gross_minor' => null,
            'vat_rate' => null,
            'stock_status' => 'out_of_stock',
        ])
        ->and($mapped['sizing']['variant_generation_allowed'])->toBeFalse()
        ->and($mapped['sizing']['size_chart_images'])->toHaveCount(1)
        ->and($mapped['nfz']['codes'])->toBe(['J.06.01.00', 'J.06.01.01'])
        ->and($mapped['categories'][0]['path'])->toBe(['Ortezy barku'])
        ->and($mapped['images'])->toHaveCount(1)
        ->and($mapped['images'][0]['source_url'])->toBe('https://neoxmed.com/wp-content/uploads/B-01_resize-300x225.jpg')
        ->and($mapped['blocking_review_items'])->toBe([]);
});

it('preserves qualified NeoxMed source identities as globally prefixed planned SKUs', function (): void {
    $product = neoxmedImportProduct();
    $product['external_product_id'] = 'P-30-21';
    $product['sku'] = 'P-30-21';
    $product['source_code'] = 'P-30';
    $product['source_qualifier'] = '21';
    $product['slug'] = 'p-30-21-pas-brzuszny-21cm';
    $product['name'] = '(21) Pas brzuszny 21cm';

    $mapped = app(NeoxmedImportMapper::class)->mapProduct($product);

    expect($mapped['errors'])->toBe([])
        ->and($mapped['product']['external_id'])->toBe('P-30-21')
        ->and($mapped['product']['source_code'])->toBe('P-30')
        ->and($mapped['product']['source_qualifier'])->toBe('21')
        ->and($mapped['variants'][0]['sku'])->toBe('NEOX-P-30-21')
        ->and($mapped['variants'][0]['external_variant_id'])->toBe('neoxmed-P-30-21-default');
});

it('produces a saved frozen NeoxMed import map with zero database writes and expected source invariants', function (): void {
    $sourceRelativePath = 'scrapers/neoxmed/product-data-import-map-test.json';
    $saveRelativePath = 'scrapers/neoxmed/import-map-test.json';
    $sourcePath = storage_path('app/'.$sourceRelativePath);
    $savePath = storage_path('app/'.$saveRelativePath);
    @unlink($sourcePath);
    @unlink($savePath);
    if (! is_dir(dirname($sourcePath))) {
        mkdir(dirname($sourcePath), 0775, true);
    }

    file_put_contents($sourcePath, json_encode([
        'source' => 'neoxmed',
        'products' => [neoxmedImportProduct()],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $exit = Artisan::call('neoxmed:import-map', [
        '--from' => $sourceRelativePath,
        '--expected-products' => 1,
        '--expected-product-images' => 1,
        '--expected-size-charts' => 1,
        '--save' => $saveRelativePath,
        '--skip-database-audit' => true,
        '--show-review' => true,
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Database writes: NO')
        ->and($output)->toContain('Planned safe placeholder variants: 1')
        ->and($output)->toContain('Source product images: 1')
        ->and($output)->toContain('Mapped product images: 1')
        ->and($output)->toContain('Source size-chart images: 1')
        ->and($output)->toContain('Mapped size-chart images: 1')
        ->and($output)->toContain('Products without source price: 1')
        ->and($output)->toContain('Products without source VAT: 1')
        ->and($output)->toContain('Ready for database write: NO')
        ->and($output)->toContain('PASS WITH REVIEW')
        ->and(is_file($savePath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($savePath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['images_downloaded'])->toBeFalse()
        ->and($saved['mapping_structurally_valid'])->toBeTrue()
        ->and($saved['ready_for_database_write'])->toBeFalse()
        ->and($saved['source_invariants'])->toBe([
            'products' => 1,
            'product_images' => 1,
            'size_chart_images' => 1,
        ]);

    @unlink($sourcePath);
    @unlink($savePath);
});

it('audits current database collisions and category matches without modifying existing records', function (): void {
    $existing = Product::query()->create([
        'name' => 'Existing other supplier product',
        'slug' => 'neox-b-01-kamizelka-stawu-barkowego',
        'status' => 'draft',
        'external_source' => 'other-supplier',
        'external_id' => 'B-01',
    ]);

    ProductVariant::query()->create([
        'product_id' => $existing->id,
        'sku' => 'NEOX-B-01',
        'status' => 'draft',
        'currency' => 'PLN',
        'stock_status' => 'out_of_stock',
        'is_default' => true,
    ]);

    Category::query()->create([
        'name' => 'Ortezy barku',
        'slug' => 'ortezy-barku',
        'status' => 'active',
    ]);

    $map = app(NeoxmedImportMapper::class)->mapCatalogue([
        'source' => 'neoxmed',
        'products' => [neoxmedImportProduct()],
    ]);

    $before = [
        Product::withTrashed()->count(),
        ProductVariant::withTrashed()->count(),
        Category::withTrashed()->count(),
    ];

    $audit = app(NeoxmedImportDatabaseAudit::class)->audit($map);

    expect($audit['database_writes'])->toBeFalse()
        ->and($audit['summary'])->toMatchArray([
            'external_id_overlaps_other_sources' => 1,
            'slug_collisions' => 1,
            'variant_sku_collisions' => 1,
            'matched_category_slugs' => 1,
            'unmatched_category_slugs' => 0,
        ])
        ->and($audit['safe_for_future_import_implementation'])->toBeFalse()
        ->and([
            Product::withTrashed()->count(),
            ProductVariant::withTrashed()->count(),
            Category::withTrashed()->count(),
        ])->toBe($before);
});

function neoxmedImportProduct(): array
{
    return [
        'source' => 'neoxmed',
        'source_url' => 'https://neoxmed.com/ortezy-barku/',
        'source_locator' => 'https://neoxmed.com/ortezy-barku/#b-01',
        'canonical_url' => null,
        'source_code' => 'B-01',
        'source_qualifier' => null,
        'external_product_id' => 'B-01',
        'sku' => 'B-01',
        'slug' => 'b-01-kamizelka-stawu-barkowego',
        'name' => 'Kamizelka stawu barkowego',
        'brand' => ['name' => 'Neox', 'slug' => 'neox'],
        'category' => 'Ortezy barku',
        'categories' => ['Ortezy barku'],
        'source_category_name' => 'Ortezy barku',
        'source_category_path' => ['Ortezy barku'],
        'source_category_paths' => [['Ortezy barku']],
        'description_text' => "Kamizelka wykonana z pianki poliuretanowej.\nStabilizuje staw barkowy.",
        'description_html' => '<p>Kamizelka wykonana z pianki poliuretanowej.</p><p>Stabilizuje staw barkowy.</p>',
        'nfz_codes' => ['J.06.01.00', 'J.06.01.01'],
        'size_note' => 'Rozmiary: S, M, L.',
        'images' => [[
            'url' => 'http://www.neoxmed.com/wp-content/uploads/B-01_resize-300x225.jpg',
            'alt' => 'B-01',
        ]],
        'size_chart_images' => [[
            'url' => 'http://neoxmed.com/wp-content/uploads/B-01_rozmiary.jpg',
            'alt' => 'Tabela rozmiarów B-01',
        ]],
        'variant_candidates' => [],
        'price_gross_amount' => null,
        'currency' => null,
        'availability' => null,
        'is_medical_device' => true,
        'warnings' => [],
    ];
}
