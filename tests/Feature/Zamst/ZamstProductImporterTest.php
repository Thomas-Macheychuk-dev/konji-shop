<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Zamst\ZamstProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('imports a mapped Zamst product as a draft with stable variants categories producer and resources', function (): void {
    $mapped = zamstImporterMappedProduct();
    $product = app(ZamstProductImporter::class)->import($mapped, false)['product'];

    expect($product->external_source)->toBe('zamst')
        ->and($product->external_id)->toBe('2164')
        ->and($product->external_parent_sku)->toBe('ZAMST-2164')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->published_at)->toBeNull()
        ->and($product->description)
        ->toContain('Stabilizator przeznaczony')
        ->toContain('Materiały producenta')
        ->toContain('JK-2_PL.pdf')
        ->toContain('youtube.com/watch?v=jk2-demo')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $categoryNames = $product->categories()->pluck('categories.name')->all();
    $primary = $product->categories()->wherePivot('is_primary', true)->first();

    expect($categoryNames)->toContain(
        'Stabilizatory stawu kolanowego',
        'Stabilizator na rzepkę',
        'Ortezy dla siatkarzy',
    )->and($primary?->name)->toBe('Stabilizator na rzepkę');

    $producer = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->first(fn ($value): bool => $value->attribute->name === 'Producent');

    expect($producer?->value)->toBe('Zamst');

    $variants = $product->variants()->orderBy('external_variant_id')->get();

    expect($variants)->toHaveCount(2)
        ->and($variants->pluck('external_variant_id')->all())->toBe([
            'zamst-2164-2168',
            'zamst-2164-2169',
        ])
        ->and($variants->every(fn ($variant): bool => $variant->status === ProductVariantStatus::DRAFT))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_23))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->price_gross_amount === 28900))->toBeTrue()
        ->and($variants->where('is_default', true))->toHaveCount(1);
});

it('keeps mapped Zamst imports idempotent and archives variants removed from a later map', function (): void {
    $importer = app(ZamstProductImporter::class);
    $first = $importer->import(zamstImporterMappedProduct(), false)['product'];
    $firstId = $first->id;

    $updated = zamstImporterMappedProduct();
    $updated['variants'] = [$updated['variants'][0]];
    $updated['attributes'][1]['values'] = [$updated['attributes'][1]['values'][0]];

    $second = $importer->import($updated, false)['product'];
    $variants = $second->variants()->orderBy('external_variant_id')->get();

    expect($second->id)->toBe($firstId)
        ->and(Product::query()->where('external_source', 'zamst')->where('external_id', '2164')->count())->toBe(1)
        ->and($variants)->toHaveCount(2)
        ->and($variants->where('status', ProductVariantStatus::DRAFT))->toHaveCount(1)
        ->and($variants->where('status', ProductVariantStatus::ARCHIVED))->toHaveCount(1)
        ->and($variants->where('is_default', true))->toHaveCount(1);
});

it('downloads mapped Zamst images once and reuses the local file on an idempotent rerun', function (): void {
    Storage::fake('public');
    $mapped = zamstImporterMappedProduct();
    $mapped['images'] = [[
        'source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp',
        'alt' => 'Stabilizator rzepki Zamst JK-2',
        'title' => null,
        'role' => 'gallery',
        'is_main' => true,
    ]];
    $contents = zamstImporterTestImageContents();

    Http::fake([
        'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp' => Http::response($contents, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $importer = app(ZamstProductImporter::class);
    $first = $importer->import(
        mapped: $mapped,
        importImages: true,
        imageLimit: 50,
        refreshImages: false,
        imageTimeoutSeconds: 5,
        imageAttempts: 1,
        imageRetryDelayMs: 0,
        imageRequestDelayMs: 0,
    );
    $second = $importer->import(
        mapped: $mapped,
        importImages: true,
        imageLimit: 50,
        refreshImages: false,
        imageTimeoutSeconds: 5,
        imageAttempts: 1,
        imageRetryDelayMs: 0,
        imageRequestDelayMs: 0,
    );

    expect($first['product']->images()->count())->toBe(1)
        ->and($second['product']->images()->count())->toBe(1)
        ->and($second['stats']['images_reused'])->toBe(1);

    Http::assertSentCount(1);
});

it('runs Zamst import-products read-only by default and requires VAT acknowledgement for writes', function (): void {
    $relativePath = 'scrapers/zamst/import-products-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    @unlink($path);

    file_put_contents($path, json_encode(zamstImporterCatalogue(), JSON_THROW_ON_ERROR));

    try {
        $dryExit = Artisan::call('zamst:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
        ]);
        $dryOutput = Artisan::output();

        expect($dryExit)->toBe(0)
            ->and($dryOutput)->toContain('Database writes: NO')
            ->and($dryOutput)->toContain('Planned variants: 2')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();

        $blockedExit = Artisan::call('zamst:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
            '--write' => true,
            '--no-images' => true,
        ]);
        $blockedOutput = Artisan::output();

        expect($blockedExit)->toBe(1)
            ->and($blockedOutput)->toContain('BLOCKED: selected Zamst products contain unverified VAT fallbacks')
            ->and(Product::query()->where('external_source', 'zamst')->exists())->toBeFalse();

        $writeExit = Artisan::call('zamst:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
            '--write' => true,
            '--allow-unverified-vat' => true,
            '--no-images' => true,
        ]);
        $writeOutput = Artisan::output();

        expect($writeExit)->toBe(0)
            ->and($writeOutput)->toContain('Products created: 1')
            ->and($writeOutput)->toContain('PASS: selected Zamst products were imported locally as drafts')
            ->and(Product::query()->where('external_source', 'zamst')->where('external_id', '2164')->exists())->toBeTrue();
    } finally {
        @unlink($path);
    }
});

function zamstImporterCatalogue(): array
{
    return [
        'source' => 'zamst',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'source_product_count' => 1,
        'selected_product_count' => 1,
        'mapped_product_count' => 1,
        'products' => [zamstImporterMappedProduct()],
        'summary' => [
            'planned_variants' => 2,
            'vat_review_products' => 1,
        ],
        'errors' => [],
        'review_items' => [
            'Stabilizator rzepki Zamst JK-2: source does not state whether this is a medical device; mapping currently falls back to 23% VAT.',
        ],
        'ready_for_local_import_implementation' => true,
    ];
}

function zamstImporterMappedProduct(): array
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
            'description_html' => '<p>Stabilizator przeznaczony dla aktywnych sportowców.</p><script>alert(1)</script><img src="https://zamst.com.pl/test.jpg">',
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
            [
                'path' => ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'],
                'path_label' => 'Stabilizatory stawu kolanowego > Stabilizator na rzepkę',
                'is_primary' => true,
            ],
            [
                'path' => ['Ortezy dla siatkarzy'],
                'path_label' => 'Ortezy dla siatkarzy',
                'is_primary' => false,
            ],
        ],
        'attributes' => [
            [
                'code' => 'producent',
                'label' => 'Producent',
                'values' => [[
                    'value' => 'zamst',
                    'value_label' => 'Zamst',
                ]],
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
        'source_variant_count' => 2,
        'variants' => [
            [
                'source_external_variant_id' => '2169',
                'external_variant_id' => 'zamst-2164-2169',
                'sku' => 'ZAMST-2164-2169',
                'status' => 'draft',
                'is_default' => true,
                'price_gross_minor' => 28900,
                'price_net_minor' => 23496,
                'currency' => 'PLN',
                'vat_rate' => 23,
                'stock_status' => 'in_stock',
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 's',
                    'value_label' => 'S',
                ]],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ],
            [
                'source_external_variant_id' => '2168',
                'external_variant_id' => 'zamst-2164-2168',
                'sku' => 'ZAMST-2164-2168',
                'status' => 'draft',
                'is_default' => false,
                'price_gross_minor' => 28900,
                'price_net_minor' => 23496,
                'currency' => 'PLN',
                'vat_rate' => 23,
                'stock_status' => 'in_stock',
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'm',
                    'value_label' => 'M',
                ]],
                'source_active' => true,
                'source_visible' => true,
                'source_purchasable' => true,
            ],
        ],
        'images' => [
            [
                'source_url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp',
                'alt' => 'Stabilizator rzepki Zamst JK-2',
                'title' => null,
                'role' => 'gallery',
                'is_main' => true,
            ],
        ],
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
        'filtered_non_product_video_count' => 1,
        'errors' => [],
        'review_items' => [
            'source does not state whether this is a medical device; mapping currently falls back to 23% VAT.',
        ],
    ];
}

function zamstImporterTestImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create Zamst importer test image.');
    }

    try {
        $background = imagecolorallocate($image, 230, 230, 230);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render Zamst importer test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
