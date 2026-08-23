<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('runs the SHA-pinned production import command as a read-only inline-preflight dry run by default', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = zamstProductionExecutionFixtureFile();

    try {
        $exit = Artisan::call('zamst:production-import', [
            '--from' => $relativePath,
            '--expected-sha256' => $sha,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '1',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--show-checks' => true,
        ]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Database writes: NO')
            ->and($output)->toContain('Inline preflight errors: 0')
            ->and($output)->toContain('[PASS] mapping.sha256')
            ->and($output)->toContain('[PASS] database.existing_product_ids')
            ->and($output)->toContain('Selected planned variants: 2')
            ->and($output)->toContain('Selected mapped images: 1')
            ->and($output)->toContain('PASS: SHA-pinned production dry-run passed')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('blocks a production write unless explicit counts confirmation and VAT acknowledgement are supplied', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = zamstProductionExecutionFixtureFile();

    try {
        $missingCounts = Artisan::call('zamst:production-import', [
            '--from' => $relativePath,
            '--expected-sha256' => $sha,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '1',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--write' => true,
        ]);
        $missingCountsOutput = Artisan::output();

        expect($missingCounts)->toBe(1)
            ->and($missingCountsOutput)->toContain('production writes require explicit --expected-existing-products/variants/images')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();

        $missingConfirmation = Artisan::call('zamst:production-import', [
            '--from' => $relativePath,
            '--expected-sha256' => $sha,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '1',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
            '--allow-unverified-vat' => true,
        ]);
        $missingConfirmationOutput = Artisan::output();

        expect($missingConfirmation)->toBe(1)
            ->and($missingConfirmationOutput)->toContain('BLOCKED: --confirm-production-write=IMPORT-ZAMST-DRAFTS')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('writes one complete Zamst draft after inline preflight and passes the exact post-write audit', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = zamstProductionExecutionFixtureFile();
    $contents = zamstProductionExecutionImageContents();

    Http::fake([
        'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    try {
        $exit = Artisan::call('zamst:production-import', [
            '--from' => $relativePath,
            '--expected-sha256' => $sha,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '1',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
            '--confirm-production-write' => 'IMPORT-ZAMST-DRAFTS',
            '--allow-unverified-vat' => true,
            '--image-attempts' => '1',
            '--image-retry-delay-ms' => '0',
            '--image-request-delay-ms' => '0',
            '--show-failures' => true,
        ]);
        $output = Artisan::output();
        $product = Product::query()->where('external_source', 'zamst')->where('external_id', '2164')->first();

        expect($exit)->toBe(0)
            ->and($output)->toContain('WRITE GATE PASSED')
            ->and($output)->toContain('Products created: 1')
            ->and($output)->toContain('Selected products found: 1/1')
            ->and($output)->toContain('Selected variants: 2/2')
            ->and($output)->toContain('Selected images: 1/1')
            ->and($output)->toContain('Global Zamst products: 1')
            ->and($output)->toContain('Audit errors: 0')
            ->and($output)->toContain('PASS: selected Zamst products were written to production as drafts')
            ->and($product)->not->toBeNull()
            ->and($product?->status)->toBe(ProductStatus::DRAFT)
            ->and($product?->published_at)->toBeNull()
            ->and($product?->variants()->count())->toBe(2)
            ->and($product?->images()->count())->toBe(1);
    } finally {
        @unlink($path);
    }
});

it('keeps a repeated production execution idempotent with exact pre and post counts', function (): void {
    Storage::fake('public');
    [$relativePath, $path, $sha] = zamstProductionExecutionFixtureFile();
    $contents = zamstProductionExecutionImageContents();

    Http::fake([
        'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp' => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    try {
        $common = [
            '--from' => $relativePath,
            '--expected-sha256' => $sha,
            '--expected-products' => '1',
            '--expected-variants' => '2',
            '--expected-images' => '1',
            '--expected-category-paths' => '2',
            '--expected-downloads' => '1',
            '--expected-videos' => '1',
            '--expected-vat-review' => '1',
            '--expected-post-products' => '1',
            '--expected-post-variants' => '2',
            '--expected-post-images' => '1',
            '--minimum-free-mib' => '0',
            '--probe-images' => '0',
            '--write' => true,
            '--confirm-production-write' => 'IMPORT-ZAMST-DRAFTS',
            '--allow-unverified-vat' => true,
            '--image-attempts' => '1',
            '--image-retry-delay-ms' => '0',
            '--image-request-delay-ms' => '0',
        ];

        $first = Artisan::call('zamst:production-import', $common + [
            '--expected-existing-products' => '0',
            '--expected-existing-variants' => '0',
            '--expected-existing-images' => '0',
        ]);

        expect($first)->toBe(0);

        $second = Artisan::call('zamst:production-import', $common + [
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
            ->and($output)->toContain('Audit errors: 0')
            ->and(Product::query()->where('external_source', 'zamst')->count())->toBe(1);

        Http::assertSentCount(1);
    } finally {
        @unlink($path);
    }
});

/** @return array{0:string,1:string,2:string} */
function zamstProductionExecutionFixtureFile(): array
{
    $relativePath = 'scrapers/zamst/production-execution-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    @unlink($path);
    file_put_contents($path, json_encode(zamstProductionExecutionCatalogue(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $sha = hash_file('sha256', $path);

    if (! is_string($sha) || $sha === '') {
        throw new RuntimeException('Unable to create production execution fixture fingerprint.');
    }

    return [$relativePath, $path, $sha];
}

function zamstProductionExecutionCatalogue(): array
{
    return [
        'source' => 'zamst',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'products' => [zamstProductionExecutionMappedProduct()],
        'errors' => [],
        'review_items' => [
            'Stabilizator rzepki Zamst JK-2: source does not state whether this is a medical device; mapping currently falls back to 23% VAT.',
        ],
        'ready_for_local_import_implementation' => true,
    ];
}

function zamstProductionExecutionMappedProduct(): array
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
            'short_description_html' => '<p>Stabilizator kolana dla sportowców.</p>',
            'description_html' => '<p>Stabilizator przeznaczony dla aktywnych sportowców.</p>',
            'seo_title' => 'Stabilizator rzepki Zamst JK-2',
            'seo_description' => 'Zamst JK-2 – stabilizator kolana.',
            'manufacturer' => 'Zamst',
        ],
        'tax' => [
            'is_medical_device' => null,
            'vat_rate' => 23,
            'requires_review' => true,
            'gross_minor' => 28900,
            'net_minor' => 23496,
            'currency' => 'PLN',
        ],
        'categories' => [
            ['path' => ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'], 'is_primary' => true],
            ['path' => ['Ortezy dla siatkarzy'], 'is_primary' => false],
        ],
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'values' => [['value' => 'zamst', 'value_label' => 'Zamst']],
                'source' => 'fixed',
            ],
            [
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'values' => [
                    ['value' => 's', 'value_label' => 'S'],
                    ['value' => 'm', 'value_label' => 'M'],
                ],
                'source' => 'concrete_variants',
            ],
        ],
        'variants' => [
            [
                'external_variant_id' => 'zamst-2164-2169',
                'sku' => 'ZAMST-2164-2169',
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => 28900,
                'price_net_minor' => 23496,
                'currency' => 'PLN',
                'vat_rate' => 23,
                'stock_status' => 'in_stock',
                'attributes' => [['code' => 'rozmiar', 'label' => 'Rozmiar', 'value' => 's', 'value_label' => 'S']],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ],
            [
                'external_variant_id' => 'zamst-2164-2168',
                'sku' => 'ZAMST-2164-2168',
                'status' => 'draft',
                'is_default' => false,
                'price_gross_minor' => 28900,
                'price_net_minor' => 23496,
                'currency' => 'PLN',
                'vat_rate' => 23,
                'stock_status' => 'in_stock',
                'attributes' => [['code' => 'rozmiar', 'label' => 'Rozmiar', 'value' => 'm', 'value_label' => 'M']],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ],
        ],
        'images' => [[
            'source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp',
            'alt' => 'Stabilizator rzepki Zamst JK-2',
            'title' => null,
            'role' => 'gallery',
            'is_main' => true,
        ]],
        'downloads' => [[
            'source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/JK-2_PL.pdf',
            'label' => 'Instrukcja JK-2',
            'extension' => 'pdf',
            'planned_handling' => 'preserve_as_product_content_link',
        ]],
        'videos' => [[
            'source_url' => 'https://youtube.com/watch?v=jk2-demo',
            'label' => 'Film JK-2',
            'planned_handling' => 'preserve_as_product_content_link',
        ]],
        'errors' => [],
        'review_items' => [
            'source does not state whether this is a medical device; mapping currently falls back to 23% VAT.',
        ],
    ];
}

function zamstProductionExecutionImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create production execution test image.');
    }

    try {
        $background = imagecolorallocate($image, 220, 220, 220);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render production execution test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
