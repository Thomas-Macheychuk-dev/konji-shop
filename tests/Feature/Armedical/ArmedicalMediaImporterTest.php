<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Armedical\ArmedicalMediaImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('localizes ARmedical images and documents without rewriting catalogue pricing or product status', function (): void {
    Storage::fake('public');

    $product = Product::query()->create([
        'name' => 'Testowy produkt ARmedical',
        'slug' => 'testowy-produkt-armedical',
        'short_description' => '<p>Wyrób medyczny.</p>',
        'description' => '<section class="armedical-description"><p>Opis produktu.</p></section>\n<section class="armedical-resources"><h2>Materiały producenta</h2><ul><li><a href="https://armedical.pl/wp-content/uploads/2026/01/old.pdf">Instrukcja obsługi</a></li></ul></section>',
        'status' => ProductStatus::DRAFT,
        'seo_title' => 'Testowy produkt ARmedical',
        'seo_description' => 'Opis SEO',
        'external_source' => 'armedical',
        'external_id' => 'armedical-test-media',
        'external_parent_sku' => 'AR-MEDIA',
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'ARMEDICAL-AR-MEDIA',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 13500,
        'price_gross_amount' => 14580,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::OUT_OF_STOCK,
        'is_default' => true,
        'external_variant_id' => 'armedical-test-media-default',
    ]);

    $imageOne = 'https://armedical.pl/wp-content/uploads/2026/01/test-main.jpg';
    $imageTwo = 'https://armedical.pl/wp-content/uploads/2026/01/test-secondary.jpg';
    $manual = 'https://armedical.pl/wp-content/uploads/2026/01/test-instrukcja.pdf';
    $registration = 'https://armedical.pl/wp-content/uploads/2026/01/test-rejestracja.pdf';
    $imageContents = armedicalMediaTestImageContents();

    Http::fake([
        $imageOne => Http::response($imageContents, 200, ['Content-Type' => 'image/jpeg']),
        $imageTwo => Http::response($imageContents, 200, ['Content-Type' => 'image/jpeg']),
        $manual => Http::response("%PDF-1.4\nmanual\n%%EOF", 200, ['Content-Type' => 'application/pdf']),
        $registration => Http::response("%PDF-1.4\nregistration\n%%EOF", 200, ['Content-Type' => 'application/pdf']),
    ]);

    $mapped = armedicalMediaMappedProduct($imageOne, $imageTwo, $manual, $registration);
    $result = app(ArmedicalMediaImporter::class)->import(
        mapped: $mapped,
        importImages: true,
        importDocuments: true,
        imageLimit: null,
        refreshImages: false,
        refreshDocuments: false,
        timeoutSeconds: 5,
        attempts: 1,
        retryDelayMs: 0,
        requestDelayMs: 0,
    );

    $reloaded = $result['product'];
    $images = $reloaded->images()->get();
    $variant->refresh();

    expect($result['warnings'])->toBe([])
        ->and($result['stats']['images_created'])->toBe(2)
        ->and($result['stats']['documents_created'])->toBe(2)
        ->and($result['stats']['images_failed'])->toBe(0)
        ->and($result['stats']['documents_failed'])->toBe(0)
        ->and($images)->toHaveCount(2)
        ->and($images->firstWhere('source_url', $imageOne)?->is_main)->toBeTrue()
        ->and($images->firstWhere('source_url', $imageTwo)?->is_main)->toBeFalse()
        ->and($reloaded->status)->toBe(ProductStatus::DRAFT)
        ->and($reloaded->name)->toBe('Testowy produkt ARmedical')
        ->and($variant->price_net_amount)->toBe(13500)
        ->and($variant->price_gross_amount)->toBe(14580)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($reloaded->description)->toContain('data-armedical-document-source=')
        ->toContain('/storage/products/armedical/armedical-test-media/documents/')
        ->toContain('Instrukcja obsługi')
        ->toContain('Dokumenty rejestrowe')
        ->not->toContain('href="https://armedical.pl/wp-content/uploads/2026/01/old.pdf"');

    expect(Storage::disk('public')->allFiles('products/armedical/armedical-test-media/gallery'))->toHaveCount(1)
        ->and(Storage::disk('public')->allFiles('products/armedical/armedical-test-media/documents'))->toHaveCount(2);

    Http::assertSent(function (Request $request): bool {
        return $request->hasHeader('Referer');
    });

    Http::preventStrayRequests();

    $second = app(ArmedicalMediaImporter::class)->import(
        mapped: $mapped,
        importImages: true,
        importDocuments: true,
        imageLimit: null,
        refreshImages: false,
        refreshDocuments: false,
        timeoutSeconds: 5,
        attempts: 1,
        retryDelayMs: 0,
        requestDelayMs: 0,
    );

    expect($second['warnings'])->toBe([])
        ->and($second['stats']['images_created'])->toBe(0)
        ->and($second['stats']['images_reused'])->toBe(2)
        ->and($second['stats']['documents_created'])->toBe(0)
        ->and($second['stats']['documents_reused'])->toBe(2)
        ->and($second['product']->images()->count())->toBe(2)
        ->and(substr_count((string) $second['product']->description, 'data-armedical-document-source='))->toBe(2);
});

it('accepts UTF-8 ARmedical upload filenames and lets the remote importer percent-encode the request path', function (): void {
    Storage::fake('public');

    $product = Product::query()->create([
        'name' => 'Pas ARmedical beżowy',
        'slug' => 'pas-armedical-bezowy',
        'description' => '<p>Opis.</p>',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'armedical',
        'external_id' => 'armedical-test-media-unicode',
        'external_parent_sku' => 'AR-2260',
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'ARMEDICAL-AR-2260',
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 10000,
        'price_gross_amount' => 10800,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::OUT_OF_STOCK,
        'is_default' => true,
        'external_variant_id' => 'armedical-test-media-unicode-default',
    ]);

    $sourceUrl = 'https://armedical.pl/wp-content/uploads/2019/06/AR-2260_beżowy_www_1.jpg';
    $imageContents = armedicalMediaTestImageContents();

    Http::fake(fn (Request $request) => Http::response($imageContents, 200, ['Content-Type' => 'image/jpeg']));

    $result = app(ArmedicalMediaImporter::class)->import(
        mapped: [
            'source' => 'armedical',
            'source_url' => 'https://armedical.pl/oferta/pas-brzuszny-zapinany/',
            'canonical_url' => 'https://armedical.pl/oferta/pas-brzuszny-zapinany/',
            'product' => [
                'external_source' => 'armedical',
                'external_id' => 'armedical-test-media-unicode',
                'name' => 'Pas ARmedical beżowy',
            ],
            'images' => [[
                'source_url' => $sourceUrl,
                'alt' => 'Beżowy pas',
                'is_primary' => true,
            ]],
            'documents' => [],
        ],
        importImages: true,
        importDocuments: false,
        imageLimit: null,
        refreshImages: false,
        refreshDocuments: false,
        timeoutSeconds: 5,
        attempts: 1,
        retryDelayMs: 0,
        requestDelayMs: 0,
    );

    expect($result['warnings'])->toBe([])
        ->and($result['stats']['images_created'])->toBe(1)
        ->and($result['stats']['images_failed'])->toBe(0)
        ->and($result['product']->images()->where('source_url', $sourceUrl)->count())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), 'AR-2260_be%C5%BCowy_www_1.jpg');
    });
});

it('keeps ARmedical media command read-only for arbitrary map fixtures and blocks non-frozen writes', function (): void {
    $relative = 'scrapers/armedical/media-command-test.json';
    $path = storage_path('app/'.$relative);
    @mkdir(dirname($path), 0755, true);

    $mapped = armedicalMediaMappedProduct(
        'https://armedical.pl/wp-content/uploads/2026/01/test-main.jpg',
        'https://armedical.pl/wp-content/uploads/2026/01/test-secondary.jpg',
        'https://armedical.pl/wp-content/uploads/2026/01/test-instrukcja.pdf',
        'https://armedical.pl/wp-content/uploads/2026/01/test-rejestracja.pdf',
    );
    $mapped['errors'] = [];
    $mapped['blocking_review_items'] = [];
    $mapped['variants'] = [[
        'external_variant_id' => 'armedical-test-media-default',
        'sku' => 'ARMEDICAL-AR-MEDIA',
        'price_net_minor' => 10000,
        'price_gross_minor' => 10800,
        'vat_rate' => 8,
        'currency' => 'PLN',
        'pricing_resolution' => ['status' => 'matched'],
    ]];

    file_put_contents($path, json_encode([
        'source' => 'armedical',
        'products' => [$mapped],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('armedical:import-media', ['--from' => $relative])
            ->expectsOutputToContain('Database/media writes: NO')
            ->expectsOutputToContain('Products ready for selected media stage: 1')
            ->assertSuccessful();

        $this->artisan('armedical:import-media', ['--from' => $relative, '--write' => true])
            ->expectsOutputToContain('BLOCKED: ARmedical priced-map SHA-256 does not match the frozen approved fingerprint.')
            ->assertFailed();
    } finally {
        @unlink($path);
    }
});

/** @return array<string, mixed> */
function armedicalMediaMappedProduct(string $imageOne, string $imageTwo, string $manual, string $registration): array
{
    return [
        'source' => 'armedical',
        'source_url' => 'https://armedical.pl/oferta/test-media/',
        'canonical_url' => 'https://armedical.pl/oferta/test-media/',
        'product' => [
            'external_source' => 'armedical',
            'external_id' => 'armedical-test-media',
            'external_parent_sku' => 'AR-MEDIA',
            'catalogue_number' => 'AR-MEDIA',
            'name' => 'Testowy produkt ARmedical',
            'slug' => 'testowy-produkt-armedical',
            'status' => 'draft',
        ],
        'images' => [
            ['source_url' => $imageOne, 'alt' => 'Główne zdjęcie', 'is_primary' => true],
            ['source_url' => $imageTwo, 'alt' => 'Drugie zdjęcie', 'is_primary' => false],
        ],
        'documents' => [
            ['source_url' => $manual, 'label' => 'Instrukcja obsługi', 'type' => 'manual'],
            ['source_url' => $registration, 'label' => 'Dokumenty rejestrowe', 'type' => 'registration'],
        ],
    ];
}

function armedicalMediaTestImageContents(): string
{
    $image = imagecreatetruecolor(32, 32);

    if ($image === false) {
        throw new RuntimeException('Unable to create ARmedical media test image.');
    }

    try {
        $background = imagecolorallocate($image, 235, 235, 235);
        imagefill($image, 0, 0, $background);
        ob_start();
        imagejpeg($image, null, 90);
        $contents = ob_get_clean();

        if (! is_string($contents)) {
            throw new RuntimeException('Unable to render ARmedical media test image.');
        }

        return $contents;
    } finally {
        imagedestroy($image);
    }
}
