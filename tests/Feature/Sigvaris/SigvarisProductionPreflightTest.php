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

it('passes an exact read-only Sigvaris production preflight with frozen fingerprints and an image probe', function (): void {
    Storage::fake('public');
    $relativePath = 'scrapers/sigvaris/production-preflight-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(sigvarisProductionPreflightCatalogue(), JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    Http::fake([
        'https://www.sklep-sigvaris.com/3378-large_default/compreflex-standard-wrap-udo.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    try {
        $exit = Artisan::call('sigvaris:production-preflight', [
            '--from' => $relativePath,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '1',
            '--expected-category-paths' => '1',
            '--expected-downloads' => '1',
            '--expected-stable-default-variants' => '0',
            '--expected-vat-8-products' => '1',
            '--expected-vat-23-products' => '0',
            '--expected-review-items' => '0',
            '--expected-sha256' => $mapSha,
            '--expected-product-data-sha256' => 'product-data-test-sha',
            '--expected-combinations-sha256' => 'combinations-test-sha',
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
            ->and($output)->toContain('[PASS] mapping.product_data_sha256')
            ->and($output)->toContain('[PASS] mapping.combinations_sha256')
            ->and($output)->toContain('[PASS] network.image_probe')
            ->and($output)->toContain('PASS: Sigvaris production preflight is ready')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();

        Http::assertSentCount(1);
    } finally {
        @unlink($path);
    }
});

it('fails Sigvaris production preflight on a non-Sigvaris SKU collision', function (): void {
    Storage::fake('public');
    $existing = Product::query()->create([
        'name' => 'Existing product',
        'slug' => 'existing-product',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'other-source',
        'external_id' => 'existing-1',
    ]);
    $existing->variants()->create([
        'sku' => 'SIGVARIS-97625-99269',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 34630,
        'price_gross_amount' => 37400,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
        'external_variant_id' => 'other-variant',
    ]);

    $relativePath = 'scrapers/sigvaris/production-preflight-collision-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(sigvarisProductionPreflightCatalogue(), JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    try {
        $exit = Artisan::call('sigvaris:production-preflight', [
            '--from' => $relativePath,
            '--expected-products' => '1', '--expected-variants' => '2', '--expected-images' => '1',
            '--expected-category-paths' => '1', '--expected-downloads' => '1', '--expected-stable-default-variants' => '0',
            '--expected-vat-8-products' => '1', '--expected-vat-23-products' => '0', '--expected-review-items' => '0',
            '--expected-sha256' => $mapSha, '--expected-product-data-sha256' => 'product-data-test-sha', '--expected-combinations-sha256' => 'combinations-test-sha',
            '--minimum-free-mib' => '0', '--probe-images' => '0',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('[FAIL] database.variant_sku_collisions')
            ->and($output)->toContain('SIGVARIS-97625-99269')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('fails when existing Sigvaris row counts match but an external product ID is outside the approved map', function (): void {
    Storage::fake('public');
    Product::query()->create([
        'name' => 'Rogue Sigvaris product',
        'slug' => 'rogue-sigvaris-product',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'sigvaris',
        'external_id' => '999999',
    ]);

    $relativePath = 'scrapers/sigvaris/production-preflight-rogue-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(sigvarisProductionPreflightCatalogue(), JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    try {
        $exit = Artisan::call('sigvaris:production-preflight', [
            '--from' => $relativePath,
            '--expected-products' => '1', '--expected-variants' => '2', '--expected-images' => '1',
            '--expected-category-paths' => '1', '--expected-downloads' => '1', '--expected-stable-default-variants' => '0',
            '--expected-vat-8-products' => '1', '--expected-vat-23-products' => '0', '--expected-review-items' => '0',
            '--expected-existing-products' => '1',
            '--expected-sha256' => $mapSha, '--expected-product-data-sha256' => 'product-data-test-sha', '--expected-combinations-sha256' => 'combinations-test-sha',
            '--minimum-free-mib' => '0', '--probe-images' => '0', '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('[PASS] database.existing_products')
            ->and($output)->toContain('[FAIL] database.existing_product_ids')
            ->and($output)->toContain('999999');
    } finally {
        @unlink($path);
    }
});

function sigvarisProductionPreflightCatalogue(): array
{
    return [
        'source' => 'sigvaris',
        'platform' => 'prestashop',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'input_fingerprints' => [
            'product_data_sha256' => 'product-data-test-sha',
            'combinations_sha256' => 'combinations-test-sha',
        ],
        'products' => [sigvarisProductionPreflightMappedProduct()],
        'errors' => [],
        'review_items' => [],
        'ready_for_local_import_implementation' => true,
    ];
}

function sigvarisProductionPreflightMappedProduct(): array
{
    return [
        'source' => 'sigvaris',
        'source_url' => 'https://www.sklep-sigvaris.com/97625-99269-compreflex-standard-wrap-udo.html',
        'canonical_url' => 'https://www.sklep-sigvaris.com/97625-compreflex-standard-wrap-udo.html',
        'product' => [
            'external_source' => 'sigvaris',
            'external_id' => '97625',
            'external_parent_sku' => 'SIGVARIS-97625',
            'name' => 'Compreflex Standard – Wrap Udo',
            'slug' => 'compreflex-standard-wrap-udo',
            'status' => 'draft',
            'manufacturer' => 'SIGVARIS S.A.',
        ],
        'tax' => [
            'vat_rate' => 8.0,
            'requires_review' => false,
            'currency' => 'PLN',
        ],
        'categories' => [[
            'path' => ['Wyroby kompresyjne', 'Sigvaris Wraps Leczenie Obrzęków', 'Wrap udo'],
            'is_primary' => true,
        ]],
        'variants' => [
            [
                'source_external_variant_id' => '99269',
                'external_variant_id' => 'sigvaris-97625-99269',
                'sku' => 'SIGVARIS-97625-99269',
                'status' => 'draft',
                'currency' => 'PLN',
            ],
            [
                'source_external_variant_id' => '99270',
                'external_variant_id' => 'sigvaris-97625-99270',
                'sku' => 'SIGVARIS-97625-99270',
                'status' => 'draft',
                'currency' => 'PLN',
            ],
        ],
        'images' => [[
            'source_url' => 'https://www.sklep-sigvaris.com/3378-large_default/compreflex-standard-wrap-udo.jpg',
            'is_main' => true,
        ]],
        'downloads' => [[
            'source_url' => 'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id_attachment=10&id_product=97625',
            'label' => 'ŚRODKI OSTROŻNOŚCI',
        ]],
        'videos' => [],
    ];
}
