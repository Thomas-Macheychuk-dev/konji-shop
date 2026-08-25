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

it('passes an exact read-only ARmedical production preflight and probes UTF-8 images plus documents without storing them', function (): void {
    Storage::fake('public');
    Storage::fake('local');
    $relativePath = 'scrapers/armedical/production-preflight-test.json';
    $path = Storage::disk('local')->path($relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(armedicalProductionPreflightMap(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    Http::fake([
        'https://armedical.pl/wp-content/uploads/test/AR-TEST_be%C5%BCowy.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        'https://armedical.pl/wp-content/uploads/test/instrukcja.pdf' => Http::response('%PDF-document', 200, ['Content-Type' => 'application/pdf']),
    ]);

    try {
        $exit = Artisan::call('armedical:production-preflight', armedicalProductionPreflightOptions($relativePath, $mapSha) + [
            '--minimum-free-mib' => '0',
            '--probe-images' => '1',
            '--probe-documents' => '1',
            '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Database writes: NO')
            ->and($output)->toContain('Filesystem writes: NO')
            ->and($output)->toContain('Eligible products: 1')
            ->and($output)->toContain('Eligible variants: 2')
            ->and($output)->toContain('[PASS] mapping.sha256')
            ->and($output)->toContain('[PASS] mapping.product_data_sha256')
            ->and($output)->toContain('[PASS] pricing.supplier_xls_sha256')
            ->and($output)->toContain('[PASS] network.image_probe')
            ->and($output)->toContain('[PASS] network.document_probe')
            ->and($output)->toContain('PASS: ARmedical production preflight is ready')
            ->and(Product::query()->where('external_source', 'armedical')->exists())->toBeFalse();

        Http::assertSentCount(2);
        expect(Storage::disk('public')->allFiles('products/armedical'))->toBe([]);
    } finally {
        @unlink($path);
    }
});

it('fails ARmedical production preflight on non-ARmedical external ID SKU and external variant ID collisions while safely resolving the slug', function (): void {
    Storage::fake('public');
    $existing = Product::query()->create([
        'name' => 'Existing product',
        'slug' => 'stabilizator-testowy-armedical',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'other-source',
        'external_id' => 'armedical-test-brace',
    ]);
    $existing->variants()->create([
        'sku' => 'ARMEDICAL-AR-TEST-S',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 10000,
        'price_gross_amount' => 10800,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::OUT_OF_STOCK,
        'is_default' => true,
        'external_variant_id' => 'armedical-test-brace-s',
    ]);

    $relativePath = 'scrapers/armedical/production-preflight-collision-test.json';
    $path = Storage::disk('local')->path($relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(armedicalProductionPreflightMap(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    try {
        $exit = Artisan::call('armedical:production-preflight', armedicalProductionPreflightOptions($relativePath, $mapSha) + [
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--probe-documents' => '0',
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('[PASS] database.slug_collisions')
            ->and($output)->toContain('stabilizator-testowy-armedical -> stabilizator-testowy-armedical-armedical-57ef397b16')
            ->and($output)->toContain('[FAIL] database.external_id_collisions')
            ->and($output)->toContain('[FAIL] database.variant_sku_collisions')
            ->and($output)->toContain('[FAIL] database.variant_external_id_collisions')
            ->and($output)->toContain('Do not perform production ARmedical writes');
    } finally {
        @unlink($path);
    }
});


it('passes ARmedical production preflight when only a non-ARmedical base product slug collides', function (): void {
    Storage::fake('public');
    Product::query()->create([
        'name' => 'Existing supplier product',
        'slug' => 'stabilizator-testowy-armedical',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'other-source',
        'external_id' => 'other-source-product',
    ]);

    $relativePath = 'scrapers/armedical/production-preflight-slug-collision-test.json';
    $path = Storage::disk('local')->path($relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(armedicalProductionPreflightMap(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    try {
        $exit = Artisan::call('armedical:production-preflight', armedicalProductionPreflightOptions($relativePath, $mapSha) + [
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--probe-documents' => '0',
            '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('[PASS] database.slug_collisions')
            ->and($output)->toContain('stabilizator-testowy-armedical -> stabilizator-testowy-armedical-armedical-57ef397b16')
            ->and($output)->toContain('PASS: ARmedical production preflight is ready');
    } finally {
        @unlink($path);
    }
});

it('accepts an existing approved ARmedical draft cohort only when database media and storage counts match', function (): void {
    Storage::fake('public');
    $product = Product::query()->create([
        'name' => 'Stabilizator testowy ARmedical',
        'slug' => 'stabilizator-testowy-armedical',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'armedical',
        'external_id' => 'armedical-test-brace',
        'description' => '<a data-armedical-document-source="https://armedical.pl/wp-content/uploads/test/instrukcja.pdf" href="/storage/products/armedical/armedical-test-brace/documents/test.pdf">Instrukcja</a>',
    ]);
    foreach ([
        ['id' => 'armedical-test-brace-s', 'sku' => 'ARMEDICAL-AR-TEST-S', 'net' => 10000, 'gross' => 10800, 'vat' => VatRate::VAT_8, 'default' => true],
        ['id' => 'armedical-test-brace-m', 'sku' => 'ARMEDICAL-AR-TEST-M', 'net' => 12000, 'gross' => 14760, 'vat' => VatRate::VAT_23, 'default' => false],
    ] as $variant) {
        $product->variants()->create([
            'sku' => $variant['sku'], 'status' => ProductVariantStatus::DRAFT,
            'price_net_amount' => $variant['net'], 'price_gross_amount' => $variant['gross'],
            'currency' => Currency::PLN, 'vat_rate' => $variant['vat'],
            'stock_status' => StockStatus::OUT_OF_STOCK, 'is_default' => $variant['default'],
            'external_variant_id' => $variant['id'],
        ]);
    }
    $imagePath = 'products/armedical/armedical-test-brace/gallery/test.jpg';
    $documentPath = 'products/armedical/armedical-test-brace/documents/test.pdf';
    Storage::disk('public')->put($imagePath, 'image');
    Storage::disk('public')->put($documentPath, '%PDF-test');
    $product->images()->create([
        'disk' => 'public', 'path' => $imagePath,
        'source_url' => 'https://armedical.pl/wp-content/uploads/test/AR-TEST_beżowy.jpg',
        'mime_type' => 'image/jpeg', 'file_size' => 5, 'sha256' => hash('sha256', 'image'),
        'sort_order' => 0, 'is_main' => true,
    ]);

    $relativePath = 'scrapers/armedical/production-preflight-existing-test.json';
    $path = Storage::disk('local')->path($relativePath);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode(armedicalProductionPreflightMap(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $mapSha = hash_file('sha256', $path);

    try {
        $exit = Artisan::call('armedical:production-preflight', armedicalProductionPreflightOptions($relativePath, $mapSha) + [
            '--expected-existing-products' => '1',
            '--expected-existing-variants' => '2',
            '--expected-existing-images' => '1',
            '--expected-existing-document-links' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--probe-documents' => '0',
            '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('[PASS] database.existing_product_ids')
            ->and($output)->toContain('[PASS] database.draft_only_products')
            ->and($output)->toContain('[PASS] database.conservative_stock')
            ->and($output)->toContain('[PASS] storage.existing_image_files')
            ->and($output)->toContain('[PASS] storage.existing_document_files');
    } finally {
        @unlink($path);
    }
});

/** @return array<string,mixed> */
function armedicalProductionPreflightMap(): array
{
    $eligible = armedicalProductionPreflightProduct();
    $excluded = armedicalProductionPreflightProduct();
    $excluded['product']['external_id'] = 'armedical-unresolved';
    $excluded['product']['slug'] = 'armedical-unresolved';
    $excluded['product']['external_parent_sku'] = 'AR-UNRESOLVED';
    $excluded['variants'] = [[
        'external_variant_id' => 'armedical-unresolved-default', 'sku' => null,
        'status' => 'draft', 'is_default' => true, 'currency' => 'PLN',
        'price_net_minor' => null, 'price_gross_minor' => null, 'vat_rate' => null,
        'pricing_resolution' => ['status' => 'unmatched'],
    ]];
    $excluded['blocking_review_items'] = ['fixture source identity conflict'];
    $excluded['images'] = [];
    $excluded['documents'] = [];

    return [
        'source' => 'armedical',
        'mode' => 'pricing_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'input_fingerprint' => ['sha256' => 'product-data-test-sha'],
        'products' => [$eligible, $excluded],
        'supplier_price_list' => [
            'metadata' => [
                'source_sha256' => 'supplier-test-sha', 'effective_from' => '2026-03-04',
                'price_column' => 'Cena netto', 'vat_column' => 'VAT %',
                'ignored_price_column' => 'Pakiet 5+1 cena*',
            ],
            'summary' => ['rows' => 2, 'unique_codes' => 2],
        ],
        'pricing_summary' => [
            'planned_variants' => 3, 'matched_variants' => 2, 'unmatched_variants' => 1,
            'fully_priced_products' => 1, 'unpriced_products' => 1,
        ],
        'errors' => [],
        'review_items' => ['fixture review'],
        'blocking_review_items' => ['fixture source identity conflict'],
    ];
}

/** @return array<string,mixed> */
function armedicalProductionPreflightProduct(): array
{
    return [
        'source' => 'armedical',
        'product' => [
            'external_source' => 'armedical', 'external_id' => 'armedical-test-brace',
            'external_parent_sku' => 'AR-TEST', 'catalogue_number' => 'AR-TEST',
            'name' => 'Stabilizator testowy ARmedical', 'slug' => 'stabilizator-testowy-armedical',
            'status' => 'draft',
        ],
        'variants' => [
            [
                'external_variant_id' => 'armedical-test-brace-s', 'sku' => 'ARMEDICAL-AR-TEST-S',
                'status' => 'draft', 'is_default' => true, 'currency' => 'PLN',
                'price_net_minor' => 10000, 'price_gross_minor' => 10800, 'vat_rate' => 8,
                'pricing_resolution' => ['status' => 'matched'],
            ],
            [
                'external_variant_id' => 'armedical-test-brace-m', 'sku' => 'ARMEDICAL-AR-TEST-M',
                'status' => 'draft', 'is_default' => false, 'currency' => 'PLN',
                'price_net_minor' => 12000, 'price_gross_minor' => 14760, 'vat_rate' => 23,
                'pricing_resolution' => ['status' => 'matched'],
            ],
        ],
        'images' => [[
            'source_url' => 'https://armedical.pl/wp-content/uploads/test/AR-TEST_beżowy.jpg',
            'is_primary' => true,
        ]],
        'documents' => [[
            'source_url' => 'https://armedical.pl/wp-content/uploads/test/instrukcja.pdf',
            'label' => 'Instrukcja obsługi', 'type' => 'manual',
        ]],
        'blocking_review_items' => [],
    ];
}

/** @return array<string,mixed> */
function armedicalProductionPreflightOptions(string $relativePath, string $mapSha): array
{
    return [
        '--from' => $relativePath,
        '--expected-source-products' => '2', '--expected-planned-variants' => '3',
        '--expected-eligible-products' => '1', '--expected-eligible-variants' => '2',
        '--expected-excluded-products' => '1', '--expected-unmatched-variants' => '1',
        '--expected-images' => '1', '--expected-documents' => '1',
        '--expected-vat-8-variants' => '1', '--expected-vat-23-variants' => '1',
        '--expected-review-items' => '1', '--expected-blocking-review-items' => '1',
        '--expected-supplier-rows' => '2', '--expected-supplier-unique-codes' => '2',
        '--expected-sha256' => $mapSha,
        '--expected-product-data-sha256' => 'product-data-test-sha',
        '--expected-supplier-xls-sha256' => 'supplier-test-sha',
    ];
}
