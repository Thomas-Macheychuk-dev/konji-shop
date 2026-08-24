<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Sigvaris\SigvarisProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('imports a mapped Sigvaris product as a draft using concrete combination IDs and source VAT', function (): void {
    $mapped = sigvarisImporterMappedProduct();
    $product = app(SigvarisProductImporter::class)->import($mapped, false)['product'];

    expect($product->external_source)->toBe('sigvaris')
        ->and($product->external_id)->toBe('7908')
        ->and($product->external_parent_sku)->toBe('SIGVARIS-7908')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->published_at)->toBeNull()
        ->and($product->description)
        ->toContain('Opis produktu Sigvaris')
        ->toContain('Materiały produktu')
        ->toContain('prestadogpsrmanager/download')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $categoryNames = $product->categories()->pluck('categories.name')->all();
    $primary = $product->categories()->wherePivot('is_primary', true)->first();

    expect($categoryNames)->toContain(
        'Wyroby kompresyjne',
        'Sigvaris Medical',
        'Pończochy uciskowe Sigvaris',
    )->and($primary?->name)->toBe('Pończochy uciskowe Sigvaris');

    $producer = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->first(fn ($value): bool => $value->attribute->name === 'Producent');

    expect($producer?->value)->toBe('SIGVARIS S.A.');

    $variants = $product->variants()->orderBy('external_variant_id')->get();

    expect($variants)->toHaveCount(2)
        ->and($variants->pluck('external_variant_id')->all())->toBe([
            'sigvaris-7908-1001',
            'sigvaris-7908-1002',
        ])
        ->and($variants->pluck('sku')->all())->toBe([
            'SIGVARIS-7908-1001',
            'SIGVARIS-7908-1002',
        ])
        ->and($variants->every(fn ($variant): bool => $variant->status === ProductVariantStatus::DRAFT))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->vat_rate === VatRate::VAT_8))->toBeTrue()
        ->and($variants->every(fn ($variant): bool => $variant->price_gross_amount === 25000))->toBeTrue()
        ->and($variants->where('is_default', true))->toHaveCount(1);
});

it('keeps mapped Sigvaris imports idempotent and archives variants removed from a later map', function (): void {
    $importer = app(SigvarisProductImporter::class);
    $first = $importer->import(sigvarisImporterMappedProduct(), false)['product'];
    $firstId = $first->id;

    $updated = sigvarisImporterMappedProduct();
    $updated['variants'] = [$updated['variants'][0]];
    $updated['attributes'][1]['values'] = [$updated['attributes'][1]['values'][0]];

    $second = $importer->import($updated, false)['product'];
    $variants = $second->variants()->orderBy('external_variant_id')->get();

    expect($second->id)->toBe($firstId)
        ->and(Product::query()->where('external_source', 'sigvaris')->where('external_id', '7908')->count())->toBe(1)
        ->and($variants)->toHaveCount(2)
        ->and($variants->where('status', ProductVariantStatus::DRAFT))->toHaveCount(1)
        ->and($variants->where('status', ProductVariantStatus::ARCHIVED))->toHaveCount(1)
        ->and($variants->where('is_default', true))->toHaveCount(1);
});

it('does not invent Producent when the Sigvaris import map has no manufacturer', function (): void {
    $mapped = sigvarisImporterMappedProduct();
    $mapped['product']['external_id'] = '106544';
    $mapped['product']['external_parent_sku'] = 'SIGVARIS-106544';
    $mapped['product']['name'] = 'Śliska Stopka od Pani TERESA® MEDICA';
    $mapped['product']['slug'] = 'sliska-stopka-pani-teresa-medica';
    $mapped['product']['manufacturer'] = null;
    $mapped['tax']['is_medical_device'] = false;
    $mapped['tax']['vat_rate'] = 23.0;
    $mapped['tax']['gross_minor'] = 4900;
    $mapped['tax']['net_minor'] = 3984;
    $mapped['attributes'] = [];
    $mapped['variants'] = [[
        'source_external_variant_id' => null,
        'external_variant_id' => 'sigvaris-106544-default',
        'sku' => 'SIGVARIS-106544',
        'source_reference' => null,
        'status' => 'draft',
        'is_default' => true,
        'price_gross_minor' => 4900,
        'price_net_minor' => 3984,
        'currency' => 'PLN',
        'vat_rate' => 23.0,
        'stock_status' => 'in_stock',
        'stock_quantity' => 20,
        'attributes' => [],
        'source_active' => true,
        'source_visible' => true,
        'source_purchasable' => true,
    ]];

    $product = app(SigvarisProductImporter::class)->import($mapped, false)['product'];
    $producer = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->first(fn ($value): bool => $value->attribute->name === 'Producent');

    expect($producer)->toBeNull()
        ->and($product->variants()->count())->toBe(1)
        ->and($product->variants()->first()?->vat_rate)->toBe(VatRate::VAT_23);
});

it('downloads mapped Sigvaris images once and reuses the local file on an idempotent rerun', function (): void {
    Storage::fake('public');
    $mapped = sigvarisImporterMappedProduct();
    $mapped['images'] = [[
        'source_url' => 'https://www.sklep-sigvaris.com/img/p/7908.jpg',
        'alt' => 'MAGIC Pończochy',
        'role' => 'product',
        'is_main' => true,
    ]];
    $contents = sigvarisImporterTestImageContents();

    Http::fake([
        'https://www.sklep-sigvaris.com/img/p/7908.jpg' => Http::response($contents, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    $importer = app(SigvarisProductImporter::class);
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

it('removes stale PrestaShop rendition rows without deleting a shared retained image file', function (): void {
    Storage::fake('public');
    $mapped = sigvarisImporterMappedProduct();
    $largeUrl = 'https://www.sklep-sigvaris.com/3378-large_default/magic.jpg';
    $mediumUrl = 'https://www.sklep-sigvaris.com/3378-medium_default/magic.jpg';
    $mapped['images'] = [[
        'source_url' => $largeUrl,
        'alt' => 'MAGIC Pończochy',
        'role' => 'product',
        'is_main' => true,
    ]];
    $contents = sigvarisImporterTestImageContents();

    Http::fake([
        $largeUrl => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $importer = app(SigvarisProductImporter::class);
    $first = $importer->import(
        mapped: $mapped,
        importImages: true,
        imageLimit: null,
        refreshImages: false,
        imageTimeoutSeconds: 5,
        imageAttempts: 1,
        imageRetryDelayMs: 0,
        imageRequestDelayMs: 0,
    );
    $retained = $first['product']->images()->sole();
    $sharedPath = $retained->path;

    ProductImage::query()->create([
        'product_id' => $first['product']->id,
        'disk' => $retained->disk,
        'path' => $sharedPath,
        'source_url' => $mediumUrl,
        'mime_type' => $retained->mime_type,
        'file_size' => $retained->file_size,
        'sha256' => $retained->sha256,
        'alt_text' => $retained->alt_text,
        'title' => $retained->title,
        'sort_order' => 1,
        'is_main' => false,
    ]);

    expect($first['product']->images()->count())->toBe(2)
        ->and(Storage::disk('public')->exists($sharedPath))->toBeTrue();

    $second = $importer->import(
        mapped: $mapped,
        importImages: true,
        imageLimit: null,
        refreshImages: false,
        imageTimeoutSeconds: 5,
        imageAttempts: 1,
        imageRetryDelayMs: 0,
        imageRequestDelayMs: 0,
    );

    expect($second['product']->images()->count())->toBe(1)
        ->and($second['product']->images()->sole()->source_url)->toBe($largeUrl)
        ->and($second['stats']['images_reused'])->toBe(1)
        ->and($second['stats']['images_deleted'])->toBe(1)
        ->and(Storage::disk('public')->exists($sharedPath))->toBeTrue();

    Http::assertSentCount(1);
});

it('runs Sigvaris import-products read-only by default and pins writes to the approved map SHA', function (): void {
    $relativePath = 'scrapers/sigvaris/import-products-test.json';
    $path = storage_path('app/'.$relativePath);
    @mkdir(dirname($path), 0755, true);
    @unlink($path);

    file_put_contents($path, json_encode(sigvarisImporterCatalogue(), JSON_THROW_ON_ERROR));
    $sha = hash_file('sha256', $path);

    try {
        $dryExit = Artisan::call('sigvaris:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
        ]);
        $dryOutput = Artisan::output();

        expect($dryExit)->toBe(0)
            ->and($dryOutput)->toContain('Database writes: NO')
            ->and($dryOutput)->toContain('Planned variants: 2')
            ->and($dryOutput)->toContain('Import-map SHA-256: '.$sha)
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();

        $blockedExit = Artisan::call('sigvaris:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
            '--write' => true,
            '--no-images' => true,
        ]);
        $blockedOutput = Artisan::output();

        expect($blockedExit)->toBe(1)
            ->and($blockedOutput)->toContain('BLOCKED: --write requires --expected-sha256')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();

        $mismatchExit = Artisan::call('sigvaris:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
            '--write' => true,
            '--expected-sha256' => str_repeat('a', 64),
            '--no-images' => true,
        ]);

        expect($mismatchExit)->toBe(1)
            ->and(Artisan::output())->toContain('does not match the approved fingerprint')
            ->and(Product::query()->where('external_source', 'sigvaris')->exists())->toBeFalse();

        $writeExit = Artisan::call('sigvaris:import-products', [
            '--from' => $relativePath,
            '--limit' => '1',
            '--write' => true,
            '--expected-sha256' => $sha,
            '--no-images' => true,
        ]);
        $writeOutput = Artisan::output();

        expect($writeExit)->toBe(0)
            ->and($writeOutput)->toContain('Products created: 1')
            ->and($writeOutput)->toContain('PASS: selected Sigvaris products were imported locally as drafts')
            ->and(Product::query()->where('external_source', 'sigvaris')->where('external_id', '7908')->exists())->toBeTrue();
    } finally {
        @unlink($path);
    }
});


it('repairs a plain Sigvaris TABELA ROZMIARÓW string to a local linked image and preserves it on importer reruns', function (): void {
    Storage::fake('public');
    $mapped = sigvarisImporterMappedProduct();
    $mapped['product']['description_html'] = '<p>Opis produktu Sigvaris</p><p>TABELA ROZMIARÓW</p>';
    $product = app(SigvarisProductImporter::class)->import($mapped, false)['product'];
    $relativeMap = 'scrapers/sigvaris/size-chart-repair-test.json';
    $mapPath = storage_path('app/'.$relativeMap);
    @mkdir(dirname($mapPath), 0755, true);
    file_put_contents($mapPath, json_encode(['products' => [$mapped]], JSON_THROW_ON_ERROR));
    $sha = hash_file('sha256', $mapPath);
    $sourceUrl = $mapped['source_url'];
    $chartUrl = 'https://www.sklep-sigvaris.com/img/cms/tabela-rozmiarow.png';
    $contents = sigvarisImporterTestImageContents();

    Http::fake([
        $sourceUrl => Http::response(<<<'HTML'
<!doctype html><html><body>
<h1>MAGIC Pończochy uciskowe</h1>
<a href="/img/cms/tabela-rozmiarow.png">TABELA ROZMIARÓW</a>
</body></html>
HTML),
        $chartUrl => Http::response($contents, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    try {
        $exit = Artisan::call('sigvaris:repair-size-charts', [
            '--from' => $relativeMap,
            '--expected-sha256' => $sha,
            '--write' => true,
            '--request-delay-ms' => '0',
            '--attempts' => '1',
            '--retry-delay-ms' => '0',
            '--asset-attempts' => '1',
            '--asset-retry-delay-ms' => '0',
        ]);
        $output = Artisan::output();
        $product->refresh();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Linked size-chart images discovered: 1')
            ->and($output)->toContain('Database descriptions updated: 1')
            ->and($product->description)->toContain('data-sigvaris-size-chart="1"')
            ->toContain('/storage/products/sigvaris/7908/size-chart/')
            ->not->toMatch('/>TABELA ROZMIARÓW<\/p>/u');

        preg_match('#href="/storage/([^"]+)"#', (string) $product->description, $matches);
        expect($matches[1] ?? null)->not->toBeNull()
            ->and(Storage::disk('public')->exists($matches[1]))->toBeTrue();

        app(SigvarisProductImporter::class)->import($mapped, false);
        $product->refresh();

        expect($product->description)
            ->toContain('data-sigvaris-size-chart="1"')
            ->toContain('/storage/products/sigvaris/7908/size-chart/');
    } finally {
        @unlink($mapPath);
    }
});

function sigvarisImporterCatalogue(): array
{
    return [
        'source' => 'sigvaris',
        'mode' => 'import_mapping_dry_run',
        'database_writes' => false,
        'images_downloaded' => false,
        'source_product_count' => 1,
        'mapped_product_count' => 1,
        'products' => [sigvarisImporterMappedProduct()],
        'summary' => [
            'source_concrete_combinations' => 2,
            'planned_variants' => 2,
        ],
        'errors' => [],
        'review_items' => [],
        'ready_for_local_import_implementation' => true,
    ];
}

function sigvarisImporterMappedProduct(): array
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
            'description_html' => '<p>Opis produktu Sigvaris.</p><script>alert(1)</script><img src="https://www.sklep-sigvaris.com/test.jpg">',
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

function sigvarisImporterTestImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create Sigvaris importer test image.');
    }

    try {
        $background = imagecolorallocate($image, 230, 230, 230);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render Sigvaris importer test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
