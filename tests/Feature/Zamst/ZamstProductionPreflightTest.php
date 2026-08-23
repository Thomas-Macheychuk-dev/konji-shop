<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('passes an exact read-only Zamst production preflight and can probe mapped images without storing them', function (): void {
    Storage::fake('public');
    $relativePath = 'scrapers/zamst/production-preflight-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(zamstProductionPreflightCatalogue(), JSON_THROW_ON_ERROR));

    Http::fake([
        'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp' => Http::response('image-bytes', 200, ['Content-Type' => 'image/webp']),
    ]);

    try {
        $exit = Artisan::call('zamst:production-preflight', [
            '--from' => $relativePath,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '2',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '1',
            '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Database writes: NO')
            ->and($output)->toContain('Product image writes: NO')
            ->and($output)->toContain('Products: 1')
            ->and($output)->toContain('Variants: 2')
            ->and($output)->toContain('[PASS] mapping.ready | Import map is structurally ready.')
            ->and($output)->toContain('[PASS] network.image_probe')
            ->and($output)->toContain('PASS: Zamst production preflight is ready')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();

        Http::assertSentCount(1);
    } finally {
        @unlink($path);
    }
});

it('fails production preflight on a global variant SKU collision before any Zamst write', function (): void {
    Storage::fake('public');
    $existing = Product::query()->create([
        'name' => 'Existing product',
        'slug' => 'existing-product',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'other-source',
        'external_id' => 'existing-1',
    ]);

    $existing->variants()->create([
        'sku' => 'ZAMST-2164-2169',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 10000,
        'price_gross_amount' => 12300,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
        'external_variant_id' => 'other-variant',
    ]);

    $relativePath = 'scrapers/zamst/production-preflight-collision-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(zamstProductionPreflightCatalogue(), JSON_THROW_ON_ERROR));

    try {
        $exit = Artisan::call('zamst:production-preflight', [
            '--from' => $relativePath,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '2',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('[FAIL] database.variant_sku_collisions')
            ->and($output)->toContain('ZAMST-2164-2169')
            ->and($output)->toContain('Do not enable production catalogue writes')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('fails production preflight when unexpected Zamst rows already exist', function (): void {
    Storage::fake('public');
    Product::query()->create([
        'name' => 'Unexpected Zamst product',
        'slug' => 'unexpected-zamst-product',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'zamst',
        'external_id' => '999999',
    ]);

    $relativePath = 'scrapers/zamst/production-preflight-existing-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(zamstProductionPreflightCatalogue(), JSON_THROW_ON_ERROR));

    try {
        $exit = Artisan::call('zamst:production-preflight', [
            '--from' => $relativePath,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '2',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('[FAIL] database.existing_products')
            ->and($output)->toContain('Expected 0; actual 1')
            ->and(Product::query()->where('external_source', 'zamst')->count())->toBe(1);
    } finally {
        @unlink($path);
    }
});

function zamstProductionPreflightCatalogue(): array
{
    return [
        'source' => 'zamst',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'products' => [zamstProductionPreflightMappedProduct()],
        'errors' => [],
        'review_items' => [
            'Stabilizator rzepki Zamst JK-2: source does not state whether this is a medical device; mapping currently falls back to 23% VAT.',
        ],
        'ready_for_local_import_implementation' => true,
    ];
}

function zamstProductionPreflightMappedProduct(): array
{
    return [
        'source' => 'zamst',
        'source_url' => 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/',
        'canonical_url' => 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/',
        'product' => [
            'external_source' => 'zamst',
            'external_id' => '2164',
            'external_parent_sku' => 'ZAMST-2164',
            'name' => 'Stabilizator rzepki Zamst JK-2',
            'slug' => 'stabilizator-kolana-jk-2',
            'status' => 'draft',
            'manufacturer' => 'Zamst',
        ],
        'tax' => [
            'requires_review' => true,
            'currency' => 'PLN',
        ],
        'categories' => [
            ['path' => ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'], 'is_primary' => true],
            ['path' => ['Ortezy dla siatkarzy'], 'is_primary' => false],
        ],
        'variants' => [
            [
                'external_variant_id' => 'zamst-2164-2169',
                'sku' => 'ZAMST-2164-2169',
                'status' => 'draft',
                'currency' => 'PLN',
            ],
            [
                'external_variant_id' => 'zamst-2164-2168',
                'sku' => 'ZAMST-2164-2168',
                'status' => 'draft',
                'currency' => 'PLN',
            ],
        ],
        'images' => [
            ['source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp'],
            ['source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/02-jk-2.webp'],
        ],
        'downloads' => [[
            'source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/JK-2_PL.pdf',
        ]],
        'videos' => [[
            'source_url' => 'https://youtube.com/watch?v=jk2-demo',
        ]],
    ];
}
