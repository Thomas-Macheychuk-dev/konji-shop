<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\RemoteImageImporter;
use App\Services\Medi\MediProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('rejects Medi placeholder images below the configured quality requirements', function (): void {
    Storage::fake('public');

    $placeholder = mediRepairImageContents(80, 80, 30);

    Http::fake([
        'https://s7e5a.scene7.com/is/image/medi/placeholder*' => Http::response($placeholder, 200, [
            'Content-Type' => 'image/jpeg',
        ]),
    ]);

    app(RemoteImageImporter::class)->import(
        'https://s7e5a.scene7.com/is/image/medi/placeholder',
        'products/medi/test/gallery',
        'public',
        ['s7e5a.scene7.com'],
        [
            'minimum_file_size_bytes' => 5 * 1024,
            'minimum_dimension_px' => 300,
        ],
    );
})->throws(RuntimeException::class);

it('removes a placeholder and repairs a Medi image through a normalized Scene7 fallback', function (): void {
    Storage::fake('public');

    $product = mediRepairProduct('1170531', 'Pilnik do paznokci');
    $placeholder = mediRepairImageContents(80, 80, 30);
    $validImage = mediRepairImageContents(600, 900, 900);
    $placeholderPath = 'products/medi/1170531/gallery/placeholder.jpg';
    $sourceUrl = 'https://s7e5a.scene7.com/is/image/medi/nail-file_0:2-to-3';
    $fallbackUrl = 'https://s7e5a.scene7.com/is/image/medi/nail-file_0?$Product-medical-2to3$';

    Storage::disk('public')->put($placeholderPath, $placeholder);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => $placeholderPath,
        'source_url' => $sourceUrl,
        'mime_type' => 'image/jpeg',
        'file_size' => strlen($placeholder),
        'sha256' => hash('sha256', $placeholder),
        'alt_text' => $product->name,
        'title' => $product->name,
        'sort_order' => 0,
        'is_main' => true,
    ]);

    writeMediImageRepairRuntime('repair-placeholder', [
        mediRepairPayload('1170531', 'Pilnik do paznokci', [$sourceUrl]),
    ]);

    Http::fake(function (Request $request) use ($sourceUrl, $fallbackUrl, $validImage) {
        return match ($request->url()) {
            $sourceUrl => Http::response('Unable to find image', 403),
            $fallbackUrl => Http::response($validImage, 200, ['Content-Type' => 'image/jpeg']),
            default => Http::response('Unable to find image', 403),
        };
    });

    $this->artisan('medi:repair-images', [
        '--runtime-dir' => 'scrapers/medi/repair-placeholder',
        '--external-id' => ['1170531'],
        '--report' => 'scrapers/medi/repair-placeholder-report.json',
        '--show-failures' => true,
    ])->assertSuccessful();

    $product->refresh()->load('images');
    $report = mediImageRepairReport('scrapers/medi/repair-placeholder-report.json');

    expect($product->images)->toHaveCount(1)
        ->and($product->images->first()->source_url)->toBe($fallbackUrl)
        ->and($product->images->first()->is_main)->toBeTrue()
        ->and(Storage::disk('public')->exists($placeholderPath))->toBeFalse()
        ->and($report['totals']['invalid_detected'])->toBe(1)
        ->and($report['totals']['invalid_removed'])->toBe(1)
        ->and($report['totals']['images_imported'])->toBe(1)
        ->and($report['totals']['products_without_usable_images'])->toBe(0)
        ->and($report['failures'])->toBe([]);
});

it('retains usable Medi images when additional Scene7 sources remain unresolved', function (): void {
    Storage::fake('public');

    $product = mediRepairProduct('927395', 'medi travel dla mężczyzn');
    $validImage = mediRepairImageContents(600, 900, 900);
    $validPath = 'products/medi/927395/gallery/valid.jpg';
    $validUrl = 'https://s7e5a.scene7.com/is/image/medi/medi-travel-valid?$Product-medical-2to3$';
    $failedUrl = 'https://s7e5a.scene7.com/is/image/medi/medi-travel-missing_0:2-to-3';

    Storage::disk('public')->put($validPath, $validImage);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => $validPath,
        'source_url' => $validUrl,
        'mime_type' => 'image/jpeg',
        'file_size' => strlen($validImage),
        'sha256' => hash('sha256', $validImage),
        'alt_text' => $product->name,
        'title' => $product->name,
        'sort_order' => 0,
        'is_main' => true,
    ]);

    writeMediImageRepairRuntime('repair-partial', [
        mediRepairPayload('927395', 'medi travel dla mężczyzn', [$validUrl, $failedUrl]),
    ]);

    Http::fake([
        '*' => Http::response('Unable to find image', 403),
    ]);

    $this->artisan('medi:repair-images', [
        '--runtime-dir' => 'scrapers/medi/repair-partial',
        '--external-id' => ['927395'],
        '--report' => 'scrapers/medi/repair-partial-report.json',
    ])->assertSuccessful();

    $product->refresh()->load('images');
    $report = mediImageRepairReport('scrapers/medi/repair-partial-report.json');

    expect($product->images)->toHaveCount(1)
        ->and($product->images->first()->source_url)->toBe($validUrl)
        ->and($report['totals']['unresolved_sources'])->toBe(1)
        ->and($report['totals']['products_with_usable_images'])->toBe(1)
        ->and($report['totals']['products_without_usable_images'])->toBe(0)
        ->and($report['failures'])->toBe([]);
});

it('fails image repair when a Medi product still has no usable image', function (): void {
    Storage::fake('public');

    mediRepairProduct('1170531', 'Pilnik do paznokci');
    $failedUrl = 'https://s7e5a.scene7.com/is/image/medi/nail-file-missing?$Product-medical-2to3$';

    writeMediImageRepairRuntime('repair-unresolved', [
        mediRepairPayload('1170531', 'Pilnik do paznokci', [$failedUrl]),
    ]);

    Http::fake([
        '*' => Http::response('Unable to find image', 403),
    ]);

    $this->artisan('medi:repair-images', [
        '--runtime-dir' => 'scrapers/medi/repair-unresolved',
        '--external-id' => ['1170531'],
        '--report' => 'scrapers/medi/repair-unresolved-report.json',
    ])->assertFailed();

    $report = mediImageRepairReport('scrapers/medi/repair-unresolved-report.json');

    expect($report['totals']['products_without_usable_images'])->toBe(1)
        ->and($report['totals']['unresolved_sources'])->toBe(1)
        ->and($report['failures'])->toHaveCount(1)
        ->and($report['failures'][0]['external_id'])->toBe('1170531');
});

it('preserves existing usable images when a normal Medi import has partial image failures', function (): void {
    Storage::fake('public');

    $product = mediRepairProduct('partial-import', 'Partial image import');
    $validImage = mediRepairImageContents(600, 900, 900);
    $validUrl = 'https://s7e5a.scene7.com/is/image/medi/partial-valid?$Product-medical-2to3$';
    $failedUrl = 'https://s7e5a.scene7.com/is/image/medi/partial-missing?$Product-medical-2to3$';
    $validSha = hash('sha256', $validImage);
    $validPath = 'products/medi/partial-import/gallery/'.$validSha.'.jpg';

    Storage::disk('public')->put($validPath, $validImage);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => $validPath,
        'source_url' => $validUrl,
        'mime_type' => 'image/jpeg',
        'file_size' => strlen($validImage),
        'sha256' => $validSha,
        'alt_text' => $product->name,
        'title' => $product->name,
        'sort_order' => 0,
        'is_main' => true,
    ]);

    Http::fake(function (Request $request) use ($validUrl, $validImage) {
        if ($request->url() === $validUrl) {
            return Http::response($validImage, 200, ['Content-Type' => 'image/jpeg']);
        }

        return Http::response('Unable to find image', 403);
    });

    $result = app(MediProductImporter::class)->import([
        'external_product_id' => 'partial-import',
        'name' => 'Partial image import',
        'slug' => 'partial-image-import',
        'sku' => 'MEDI-PARTIAL',
        'price_gross_amount' => 100.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'images' => [
            ['url' => $validUrl, 'alt' => 'Valid image'],
            ['url' => $failedUrl, 'alt' => 'Missing image'],
        ],
        'variant_candidates' => [],
        'is_medical_device' => false,
    ], ProductStatus::DRAFT, true, 5);

    $product->refresh()->load('images');

    expect($product->images)->toHaveCount(1)
        ->and($product->images->first()->path)->toBe($validPath)
        ->and($product->images->first()->is_main)->toBeTrue()
        ->and($result['warnings'])->toHaveCount(1)
        ->and($result['warnings'][0])->toContain($failedUrl);
});

it('can dry-run a Medi image audit without deleting invalid files or rows', function (): void {
    Storage::fake('public');

    $product = mediRepairProduct('1067880', 'Medi placeholder test');
    $placeholder = mediRepairImageContents(80, 80, 30);
    $placeholderPath = 'products/medi/1067880/gallery/placeholder.jpg';
    $sourceUrl = 'https://s7e5a.scene7.com/is/image/medi/placeholder-source';

    Storage::disk('public')->put($placeholderPath, $placeholder);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => $placeholderPath,
        'source_url' => $sourceUrl,
        'mime_type' => 'image/jpeg',
        'file_size' => strlen($placeholder),
        'sha256' => hash('sha256', $placeholder),
        'alt_text' => $product->name,
        'title' => $product->name,
        'sort_order' => 0,
        'is_main' => true,
    ]);

    writeMediImageRepairRuntime('repair-dry-run', [
        mediRepairPayload('1067880', 'Medi placeholder test', [$sourceUrl]),
    ]);

    $this->artisan('medi:repair-images', [
        '--runtime-dir' => 'scrapers/medi/repair-dry-run',
        '--external-id' => ['1067880'],
        '--dry-run' => true,
        '--report' => 'scrapers/medi/repair-dry-run-report.json',
    ])->assertSuccessful();

    $report = mediImageRepairReport('scrapers/medi/repair-dry-run-report.json');

    expect(ProductImage::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and(Storage::disk('public')->exists($placeholderPath))->toBeTrue()
        ->and($report['totals']['invalid_detected'])->toBe(1)
        ->and($report['totals']['invalid_removed'])->toBe(0)
        ->and($report['dry_run'])->toBeTrue();
});

function mediRepairProduct(string $externalId, string $name): Product
{
    return Product::query()->create([
        'name' => $name,
        'slug' => 'medi-repair-'.strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $externalId) ?: $externalId),
        'status' => ProductStatus::DRAFT,
        'external_source' => 'medi',
        'external_id' => $externalId,
        'external_parent_sku' => 'MEDI-'.$externalId,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeMediImageRepairRuntime(string $runtimeDirectory, array $products): void
{
    $directory = storage_path('app/scrapers/medi/'.$runtimeDirectory.'/product-data');

    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents(
        $directory.'/batch-000000-000000.json',
        json_encode([
            'source' => 'medi',
            'products' => $products,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );
}

/**
 * @param  list<string>  $urls
 * @return array<string, mixed>
 */
function mediRepairPayload(string $externalId, string $name, array $urls): array
{
    return [
        'external_product_id' => $externalId,
        'name' => $name,
        'images' => array_map(
            static fn (string $url): array => [
                'url' => $url,
                'alt' => $name,
            ],
            $urls,
        ),
    ];
}

/**
 * @return array<string, mixed>
 */
function mediImageRepairReport(string $relativePath): array
{
    return json_decode(
        (string) file_get_contents(storage_path('app/'.$relativePath)),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
}

function mediRepairImageContents(int $width, int $height, int $detailCount): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('Unable to create Medi repair test image.');
    }

    try {
        $background = imagecolorallocate($image, 245, 245, 245);
        imagefill($image, 0, 0, $background);

        for ($i = 0; $i < $detailCount; $i++) {
            $color = imagecolorallocate(
                $image,
                ($i * 31) % 255,
                ($i * 57) % 255,
                ($i * 83) % 255,
            );
            $x1 = ($i * 37) % max(1, $width);
            $y1 = ($i * 53) % max(1, $height);
            $x2 = ($i * 71 + 17) % max(1, $width);
            $y2 = ($i * 89 + 29) % max(1, $height);
            imageline($image, $x1, $y1, $x2, $y2, $color);
        }

        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to encode Medi repair test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
