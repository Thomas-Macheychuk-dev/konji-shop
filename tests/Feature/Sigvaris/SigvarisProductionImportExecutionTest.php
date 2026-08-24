<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('runs the frozen-fingerprint Sigvaris production import as a read-only inline-preflight dry run by default', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = sigvarisProductionExecutionFixtureFile();

    try {
        $exit = Artisan::call('sigvaris:production-import', sigvarisProductionExecutionExpectedOptions($relativePath, $sha) + [
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Database writes: NO')
            ->and($output)->toContain('Inline preflight errors: 0')
            ->and($output)->toContain('[PASS] mapping.sha256')
            ->and($output)->toContain('[PASS] mapping.product_data_sha256')
            ->and($output)->toContain('[PASS] mapping.combinations_sha256')
            ->and($output)->toContain('Selected planned variants: 2')
            ->and($output)->toContain('Selected mapped images: 1')
            ->and($output)->toContain('PASS: SHA-pinned production dry-run passed')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('blocks a Sigvaris production write unless exact pre/post counts and confirmation are supplied', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = sigvarisProductionExecutionFixtureFile();

    try {
        $missingCounts = Artisan::call('sigvaris:production-import', sigvarisProductionExecutionExpectedOptions($relativePath, $sha) + [
            '--write' => true,
        ]);
        $missingCountsOutput = Artisan::output();

        expect($missingCounts)->toBe(1)
            ->and($missingCountsOutput)->toContain('production writes require explicit --expected-existing-products/variants/images')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();

        $missingConfirmation = Artisan::call('sigvaris:production-import', sigvarisProductionExecutionExpectedOptions($relativePath, $sha) + [
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
        ]);
        $missingConfirmationOutput = Artisan::output();

        expect($missingConfirmation)->toBe(1)
            ->and($missingConfirmationOutput)->toContain('BLOCKED: --confirm-production-write=IMPORT-SIGVARIS-DRAFTS')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('writes one complete Sigvaris draft after inline preflight and passes the exact post-write audit', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = sigvarisProductionExecutionFixtureFile();
    $contents = sigvarisProductionExecutionImageContents();

    Http::fake([
        'https://www.sklep-sigvaris.com/img/p/7908.jpg' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
        'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id_attachment=10&id_product=7908' => Http::response(sigvarisProductionExecutionGpsrPdfContents(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    try {
        $exit = Artisan::call('sigvaris:production-import', sigvarisProductionExecutionExpectedOptions($relativePath, $sha) + [
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
            '--confirm-production-write' => 'IMPORT-SIGVARIS-DRAFTS',
            '--image-attempts' => '1',
            '--image-retry-delay-ms' => '0',
            '--image-request-delay-ms' => '0',
            '--show-failures' => true,
        ]);
        $output = Artisan::output();
        $product = Product::query()->where('external_source', 'sigvaris')->where('external_id', '7908')->first();

        expect($exit)->toBe(0)
            ->and($output)->toContain('WRITE GATE PASSED')
            ->and($output)->toContain('Products created: 1')
            ->and($output)->toContain('Selected products found: 1/1')
            ->and($output)->toContain('Selected variants: 2/2')
            ->and($output)->toContain('Selected images: 1/1')
            ->and($output)->toContain('Selected local documents: 1/1')
            ->and($output)->toContain('Documents created: 1')
            ->and($output)->toContain('Global Sigvaris products: 1')
            ->and($output)->toContain('Audit errors: 0')
            ->and($output)->toContain('PASS: selected Sigvaris products were written to production as drafts')
            ->and($product)->not->toBeNull()
            ->and($product?->status)->toBe(ProductStatus::DRAFT)
            ->and($product?->published_at)->toBeNull()
            ->and($product?->variants()->count())->toBe(2)
            ->and($product?->images()->count())->toBe(1)
            ->and($product?->description)->toContain('/storage/products/sigvaris/7908/documents/')
            ->not->toContain('www.sklep-sigvaris.com/module/prestadogpsrmanager/download');
    } finally {
        @unlink($path);
    }
});

it('keeps repeated Sigvaris production execution idempotent with exact pre and post counts', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = sigvarisProductionExecutionFixtureFile();
    $contents = sigvarisProductionExecutionImageContents();

    Http::fake([
        'https://www.sklep-sigvaris.com/img/p/7908.jpg' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
        'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id_attachment=10&id_product=7908' => Http::response(sigvarisProductionExecutionGpsrPdfContents(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    try {
        $common = sigvarisProductionExecutionExpectedOptions($relativePath, $sha) + [
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
            '--confirm-production-write' => 'IMPORT-SIGVARIS-DRAFTS',
            '--image-attempts' => '1',
            '--image-retry-delay-ms' => '0',
            '--image-request-delay-ms' => '0',
        ];

        $first = Artisan::call('sigvaris:production-import', $common + [
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
        ]);

        expect($first)->toBe(0);

        $second = Artisan::call('sigvaris:production-import', $common + [
            '--expected-existing-products' => '1',
            '--expected-existing-variants' => '2',
            '--expected-existing-images' => '1',
        ]);
        $output = Artisan::output();

        expect($second)->toBe(0)
            ->and($output)->toContain('Products created: 0')
            ->and($output)->toContain('Products updated: 1')
            ->and($output)->toContain('Images created: 0')
            ->and($output)->toContain('Images reused without download: 1')
            ->and($output)->toContain('Documents created: 0')
            ->and($output)->toContain('Documents reused without download: 1')
            ->and($output)->toContain('Selected local documents: 1/1')
            ->and($output)->toContain('Audit errors: 0')
            ->and(Product::query()->where('external_source', 'sigvaris')->count())->toBe(1);

        Http::assertSentCount(2);
    } finally {
        @unlink($path);
    }
});

it('preserves official local prices across a later full production import rerun', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = sigvarisProductionExecutionFixtureFile();
    $contents = sigvarisProductionExecutionImageContents();

    Http::fake([
        'https://www.sklep-sigvaris.com/img/p/7908.jpg' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
        'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id_attachment=10&id_product=7908' => Http::response(sigvarisProductionExecutionGpsrPdfContents(), 200, ['Content-Type' => 'application/pdf']),
    ]);

    try {
        $common = sigvarisProductionExecutionExpectedOptions($relativePath, $sha) + [
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
            '--confirm-production-write' => 'IMPORT-SIGVARIS-DRAFTS',
            '--image-attempts' => '1',
            '--image-retry-delay-ms' => '0',
            '--image-request-delay-ms' => '0',
        ];

        $first = Artisan::call('sigvaris:production-import', $common + [
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
        ]);

        expect($first)->toBe(0);

        $product = Product::query()->where('external_source', 'sigvaris')->where('external_id', '7908')->firstOrFail();
        foreach ($product->variants as $variant) {
            $variant->forceFill([
                'price_net_amount' => 33240,
                'price_gross_amount' => 35899,
            ])->save();
        }

        $second = Artisan::call('sigvaris:production-import', $common + [
            '--expected-existing-products' => '1',
            '--expected-existing-variants' => '2',
            '--expected-existing-images' => '1',
        ]);
        $output = Artisan::output();
        $product->refresh();

        expect($second)->toBe(0)
            ->and($output)->toContain('Audit errors: 0')
            ->and($product->variants()->get()->every(fn ($variant): bool => $variant->price_net_amount === 33240))->toBeTrue()
            ->and($product->variants()->get()->every(fn ($variant): bool => $variant->price_gross_amount === 35899))->toBeTrue();
    } finally {
        @unlink($path);
    }
});

/** @return array<string, string> */
function sigvarisProductionExecutionExpectedOptions(string $relativePath, string $sha): array
{
    return [
        '--from' => $relativePath,
        '--expected-sha256' => $sha,
        '--expected-product-data-sha256' => 'fixture-product-data-sha256',
        '--expected-combinations-sha256' => 'fixture-combinations-sha256',
        '--expected-products' => '1',
        '--expected-variants' => '2',
        '--expected-images' => '1',
        '--expected-category-paths' => '1',
        '--expected-downloads' => '1',
        '--expected-stable-default-variants' => '0',
        '--expected-vat-8-products' => '1',
        '--expected-vat-23-products' => '0',
        '--expected-review-items' => '0',
    ];
}

/** @return array{0:string,1:string,2:string} */
function sigvarisProductionExecutionFixtureFile(): array
{
    $relativePath = 'scrapers/sigvaris/production-execution-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    @unlink($path);
    file_put_contents($path, json_encode(sigvarisProductionExecutionCatalogue(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $sha = hash_file('sha256', $path);

    if (! is_string($sha) || $sha === '') {
        throw new RuntimeException('Unable to create Sigvaris production execution fixture fingerprint.');
    }

    return [$relativePath, $path, $sha];
}

function sigvarisProductionExecutionCatalogue(): array
{
    return [
        'source' => 'sigvaris',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'input_fingerprints' => [
            'product_data_sha256' => 'fixture-product-data-sha256',
            'combinations_sha256' => 'fixture-combinations-sha256',
        ],
        'products' => [sigvarisProductionExecutionMappedProduct()],
        'errors' => [],
        'review_items' => [],
        'ready_for_local_import_implementation' => true,
    ];
}

function sigvarisProductionExecutionMappedProduct(): array
{
    return [
        'source' => 'sigvaris',
        'source_url' => 'https://www.sklep-sigvaris.com/7908-1001-magic.html',
        'canonical_url' => 'https://www.sklep-sigvaris.com/7908-1001-magic.html',
        'product' => [
            'external_source' => 'sigvaris',
            'external_id' => '7908',
            'external_parent_sku' => 'SIGVARIS-7908',
            'name' => 'MAGIC Pończochy',
            'slug' => 'magic-ponczochy',
            'status' => 'draft',
            'short_description_html' => null,
            'description_html' => '<p>Opis produktu Sigvaris.</p>',
            'seo_title' => 'MAGIC Pończochy',
            'seo_description' => 'MAGIC Pończochy uciskowe.',
            'manufacturer' => 'SIGVARIS S.A.',
            'features' => [],
        ],
        'tax' => [
            'is_medical_device' => true,
            'vat_rate' => 8.0,
            'requires_review' => false,
            'gross_minor' => 25000,
            'net_minor' => 23148,
            'currency' => 'PLN',
            'source' => 'sigvaris_price_history',
        ],
        'categories' => [[
            'path' => ['Wyroby kompresyjne', 'Sigvaris Medical', 'Pończochy uciskowe Sigvaris'],
            'path_label' => 'Wyroby kompresyjne > Sigvaris Medical > Pończochy uciskowe Sigvaris',
            'is_primary' => true,
        ]],
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'values' => [[
                    'value' => 'sigvaris-s-a',
                    'value_label' => 'SIGVARIS S.A.',
                ]],
                'source' => 'source_manufacturer',
            ],
            [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'values' => [
                    ['value' => 'sigvaris-51', 'value_label' => 'S', 'source_attribute_id' => '51'],
                    ['value' => 'sigvaris-54', 'value_label' => 'M', 'source_attribute_id' => '54'],
                ],
                'source' => 'prestashop_combinations',
            ],
        ],
        'source_combination_count' => 2,
        'variants' => [
            [
                'source_external_variant_id' => '1001',
                'external_variant_id' => 'sigvaris-7908-1001',
                'sku' => 'SIGVARIS-7908-1001',
                'source_reference' => 'MAGIC-ThighwGripTop-Women',
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => 25000,
                'price_net_minor' => 23148,
                'currency' => 'PLN',
                'vat_rate' => 8.0,
                'stock_status' => 'in_stock',
                'stock_quantity' => 5,
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'sigvaris-51',
                    'value_label' => 'S',
                    'source_group_id' => '3',
                    'source_attribute_id' => '51',
                ]],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ],
            [
                'source_external_variant_id' => '1002',
                'external_variant_id' => 'sigvaris-7908-1002',
                'sku' => 'SIGVARIS-7908-1002',
                'source_reference' => 'MAGIC-ThighwGripTop-Women',
                'status' => 'draft',
                'is_default' => false,
                'price_gross_minor' => 25000,
                'price_net_minor' => 23148,
                'currency' => 'PLN',
                'vat_rate' => 8.0,
                'stock_status' => 'in_stock',
                'stock_quantity' => 7,
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'sigvaris-54',
                    'value_label' => 'M',
                    'source_group_id' => '3',
                    'source_attribute_id' => '54',
                ]],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ],
        ],
        'images' => [[
            'source_url' => 'https://www.sklep-sigvaris.com/img/p/7908.jpg',
            'alt' => 'MAGIC Pończochy',
            'role' => 'product',
            'is_main' => true,
        ]],
        'downloads' => [[
            'source_url' => 'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id_attachment=10&id_product=7908',
            'label' => 'ŚRODKI OSTROŻNOŚCI',
        ]],
        'videos' => [],
        'errors' => [],
        'review_items' => [],
    ];
}

function sigvarisProductionExecutionGpsrPdfContents(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
}

function sigvarisProductionExecutionImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create Sigvaris production execution test image.');
    }

    try {
        $background = imagecolorallocate($image, 220, 220, 220);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render Sigvaris production execution test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
