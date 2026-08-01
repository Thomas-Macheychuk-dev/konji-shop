<?php

declare(strict_types=1);

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Vermeiren\VermeirenProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('dry-runs Vermeiren product data without database writes or asset downloads', function (): void {
    writeVermeirenImportFixture('scrapers/vermeiren/test-product-data.json', [vermeirenImporterPayload()]);

    $this->artisan('vermeiren:import', [
        '--from' => 'scrapers/vermeiren/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images or documents were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Distinct category paths: 1')
        ->expectsOutputToContain('Default variants to create/update: 1')
        ->expectsOutputToContain('Product images discovered: 2')
        ->expectsOutputToContain('Color swatches discovered: 2')
        ->expectsOutputToContain('Technical specifications discovered: 2')
        ->expectsOutputToContain('Options discovered: 2')
        ->expectsOutputToContain('Documents discovered: 4')
        ->expectsOutputToContain('Medical device products: 1')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports Vermeiren hierarchy specifications colors options documents images and a medical default variant', function (): void {
    Storage::fake('public');
    $image = vermeirenImporterTestImageContents();

    Http::fake([
        'https://www.vermeiren.pl/product/picture.nsf/O/MAIN/$FILE/navix.jpg' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        'https://domino03.vermeiren.be/product/picture.nsf/SECOND/$File/navix-side.jpg' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        'https://www.vermeiren.pl/product/colors.nsf/O/C29/$FILE/C29-grey.jpg' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        'https://www.vermeiren.pl/product/colors.nsf/O/BLACK/$FILE/black-nylon.jpg' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        'https://www.vermeiren.pl/product/manuals.nsf/O/MANUAL/$FILE/Navix-user-manual.pdf' => Http::response('%PDF-vermeiren-manual', 200, ['Content-Type' => 'application/pdf']),
        'https://www.vermeiren.pl/product/certificate.nsf/O/CE/$FILE/CE-Navix.pdf' => Http::response('%PDF-vermeiren-certificate', 200, ['Content-Type' => 'application/pdf']),
        'https://www.vermeiren.pl/product/spareparts.nsf/O/FRAME/$FILE/Navix-frame.pdf' => Http::response('%PDF-vermeiren-spare-part', 200, ['Content-Type' => 'application/pdf']),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenProductImporter::class)->import(
        scraped: vermeirenImporterPayload(),
        status: ProductStatus::DRAFT,
        importImages: true,
        imageLimit: 50,
        importDocuments: true,
        importColorImages: true,
        assetTimeoutSeconds: 5,
        assetAttempts: 1,
        assetRetryDelayMs: 0,
        assetRequestDelayMs: 0,
        verifyTls: false,
    );
    $product = $result['product'];

    expect($result['warnings'])->toBe([])
        ->and($product->external_source)->toBe('vermeiren')
        ->and($product->external_id)->toBe('vermeiren-navix-fwd')
        ->and($product->external_parent_sku)->toBe('NAVIX-FWD')
        ->and($product->name)->toBe('NAVIX FWD')
        ->and($product->slug)->toBe('navix-fwd')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->short_description)->toBe('<p>Z napędem na przednie koła.</p>')
        ->and($product->description)
        ->toContain('Zwrotność i doskonała manewrowalność')
        ->toContain('Parametry techniczne')
        ->toContain('Maksymalna waga użytkownika')
        ->toContain('Dostępne kolory')
        ->toContain('Kolor ramy')
        ->toContain('Opcje dodatkowe')
        ->toContain('SE42 winda siedziska')
        ->toContain('Dokumenty i materiały')
        ->toContain('/storage/products/vermeiren/vermeiren-navix-fwd/documents/manual/')
        ->toContain('https://www.youtube.com/watch?v=navix')
        ->toContain('Części zamienne')
        ->toContain('To jest wyrób medyczny')
        ->not->toContain('Źródło')
        ->not->toContain('Dane produktu zaimportowane')
        ->not->toContain('<script')
        ->not->toContain('<img');

    $topCategory = Category::query()->where('slug', 'wozki-elektryczne')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'terenowo-pokojowe')->firstOrFail();

    expect($leafCategory->parent_id)->toBe($topCategory->id)
        ->and($product->categories()->count())->toBe(2)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue();

    $attributePairs = $product->attributeValues()
        ->with('attribute')
        ->get()
        ->map(fn ($value): string => $value->attribute->name.'='.$value->value)
        ->all();

    expect($attributePairs)->toContain(
        'Producent=Vermeiren',
        'Wyrób medyczny=Tak',
        'Maksymalna waga użytkownika=130 kg',
        'Prędkość maksymalna=6 km/h',
        'Kolor ramy=C29 grey',
        'Kolor tapicerki=Black nylon',
    );

    $variant = $product->variants()->firstOrFail();

    expect($variant->external_variant_id)->toBe('vermeiren-vermeiren-navix-fwd-default')
        ->and($variant->sku)->toBe('NAVIX-FWD')
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->currency)->toBe(Currency::PLN)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->price_net_amount)->toBeNull()
        ->and($variant->is_default)->toBeTrue();

    expect(ProductImage::query()->where('product_id', $product->id)->count())->toBe(2)
        ->and(ProductAttributeValueImage::query()->where('product_id', $product->id)->count())->toBe(2)
        ->and(Storage::disk('public')->allFiles('products/vermeiren/vermeiren-navix-fwd/documents'))->toHaveCount(3);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://www.vermeiren.pl/product/picture.nsf/O/MAIN/$FILE/navix.jpg'
            && $request->hasHeader('Referer', 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupWozkiSelectedNAVIX')
            && $request->hasHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36');
    });
});

it('limits Vermeiren image metadata and ignores empty Domino image placeholders', function (): void {
    Storage::fake('public');
    $image = vermeirenImporterTestImageContents();
    $longAltText = str_repeat('Zażółć gęślą jaźń — ', 30);
    $longTitle = str_repeat('TRIGO S gallery title — ', 20);
    $validUrl = 'https://domino03.vermeiren.be/product/picture.nsf/LONG/$File/TRIGO S.jpg';
    $placeholderUrl = 'https://domino03.vermeiren.be/product/picture.nsf//$File/';

    Http::fake([
        'https://domino03.vermeiren.be/product/picture.nsf/LONG/$File/TRIGO%20S.jpg' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenProductImporter::class)->import(
        scraped: vermeirenImporterPayload([
            'external_product_id' => 'vermeiren-trigo-s-long-image-copy',
            'name' => 'TRIGO S',
            'sku' => 'TRIGO-S',
            'colors' => [],
            'documents' => [],
            'images' => [
                [
                    'url' => $validUrl,
                    'alt' => $longAltText,
                    'title' => $longTitle,
                    'is_primary' => true,
                ],
                [
                    'url' => $placeholderUrl,
                    'alt' => 'Empty Domino placeholder',
                    'is_primary' => false,
                ],
            ],
        ]),
        status: ProductStatus::DRAFT,
        importImages: true,
        imageLimit: 50,
        importDocuments: false,
        importColorImages: false,
        assetTimeoutSeconds: 5,
        assetAttempts: 1,
        assetRetryDelayMs: 0,
        assetRequestDelayMs: 0,
        verifyTls: false,
    );

    $product = $result['product'];
    $storedImage = $product->images()->sole();

    expect($result['warnings'])->toBe([])
        ->and($storedImage->source_url)->toBe($validUrl)
        ->and(mb_strlen((string) $storedImage->alt_text))->toBe(255)
        ->and($storedImage->alt_text)->toBe(mb_substr($longAltText, 0, 255))
        ->and(mb_strlen((string) $storedImage->title))->toBe(255)
        ->and($storedImage->title)->toBe(mb_substr($longTitle, 0, 255));

    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), 'picture.nsf//$File/'),
    );
});

it('updates Vermeiren products idempotently and replaces stale source-owned data', function (): void {
    $importer = app(VermeirenProductImporter::class);
    $first = $importer->import(
        scraped: vermeirenImporterPayload(),
        status: ProductStatus::DRAFT,
        importImages: false,
        importDocuments: false,
    )['product'];

    $updatedPayload = vermeirenImporterPayload([
        'name' => 'NAVIX FWD Updated',
        'sku' => 'NAVIX-FWD-UPD',
        'category_paths' => [['Wózki elektryczne', 'Pokojowe']],
        'colors' => [],
        'technical_specifications' => [[
            'key' => 'speed',
            'label' => 'Prędkość maksymalna',
            'value' => '10 km/h',
        ]],
    ]);
    $second = $importer->import(
        scraped: $updatedPayload,
        status: ProductStatus::ACTIVE,
        importImages: false,
        importDocuments: false,
    )['product'];

    expect($second->id)->toBe($first->id)
        ->and(Product::query()->where('external_source', 'vermeiren')->where('external_id', 'vermeiren-navix-fwd')->count())->toBe(1)
        ->and($second->name)->toBe('NAVIX FWD Updated')
        ->and($second->status)->toBe(ProductStatus::ACTIVE)
        ->and($second->variants()->count())->toBe(1)
        ->and($second->variants()->firstOrFail()->sku)->toBe('NAVIX-FWD-UPD')
        ->and($second->variants()->firstOrFail()->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($second->categories()->where('categories.name', 'Pokojowe')->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($second->categories()->where('categories.name', 'Terenowo-pokojowe')->exists())->toBeFalse()
        ->and($second->attributeValues()->whereHas('attribute', fn ($query) => $query->where('name', 'Kolor ramy'))->exists())->toBeFalse()
        ->and($second->attributeValues()->whereHas('attribute', fn ($query) => $query->where('name', 'Prędkość maksymalna'))->where('value', '10 km/h')->exists())->toBeTrue();
});

it('runs the Vermeiren import command with offset limit and source document links', function (): void {
    writeVermeirenImportFixture('scrapers/vermeiren/test-command-product-data.json', [
        vermeirenImporterPayload(),
        vermeirenImporterPayload([
            'external_product_id' => 'vermeiren-club-vario',
            'name' => 'CLUB VARIO',
            'selected_name' => 'Club Vario',
            'sku' => 'CLUB-VARIO',
            'category_paths' => [['Łóżka']],
            'product_group' => 'Łóżka',
            'sub_group' => '',
            'category' => 'Łóżka',
            'images' => [],
            'colors' => [],
            'options' => [],
            'documents' => [],
            'canonical_url' => 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupLozkaSelectedClubVario',
            'source_url' => 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupLozkaSelectedClubVario',
        ]),
    ]);

    $this->artisan('vermeiren:import', [
        '--from' => 'scrapers/vermeiren/test-command-product-data.json',
        '--offset' => '1',
        '--limit' => '1',
        '--status' => 'draft',
        '--no-images' => true,
        '--no-documents' => true,
        '--show-failures' => true,
    ])
        ->expectsOutputToContain('Available products: 2')
        ->expectsOutputToContain('Offset: 1')
        ->expectsOutputToContain('Selected products: 1')
        ->expectsOutputToContain('Product images: skipped')
        ->expectsOutputToContain('Documents: source links only')
        ->expectsOutputToContain('Imported products: 1')
        ->expectsOutputToContain('Failures: 0')
        ->assertSuccessful();

    expect(Product::query()->where('external_source', 'vermeiren')->where('external_id', 'vermeiren-navix-fwd')->exists())->toBeFalse()
        ->and(Product::query()->where('external_source', 'vermeiren')->where('external_id', 'vermeiren-club-vario')->exists())->toBeTrue();
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeVermeirenImportFixture(string $relativePath, array $products): void
{
    Storage::disk('local')->put($relativePath, json_encode([
        'source' => 'vermeiren',
        'product_count' => count($products),
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vermeirenImporterPayload(array $overrides = []): array
{
    $payload = [
        'source' => 'vermeiren',
        'source_url' => 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupWozkiSelectedNAVIX',
        'canonical_url' => 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupWozkiSelectedNAVIX',
        'external_product_id' => 'vermeiren-navix-fwd',
        'name' => 'NAVIX FWD',
        'selected_name' => 'NAVIX FWD',
        'brand' => 'Vermeiren',
        'product_group' => 'Wózki elektryczne',
        'sub_group' => 'Terenowo-pokojowe',
        'sub_sub_group' => '',
        'category' => 'Terenowo-pokojowe',
        'category_paths' => [['Wózki elektryczne', 'Terenowo-pokojowe']],
        'seo_title' => 'NAVIX FWD | Vermeiren Polska',
        'seo_description' => 'Vermeiren Wózki elektryczne Terenowo-pokojowe NAVIX FWD',
        'short_description' => 'Z napędem na przednie koła.',
        'description' => 'Zwrotność i doskonała manewrowalność.',
        'description_html' => '<p>Zwrotność i doskonała manewrowalność.</p><script>alert(1)</script><img src="https://www.vermeiren.pl/hotlink.jpg">',
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'availability_label' => null,
        'stock_quantity' => null,
        'sku' => 'NAVIX-FWD',
        'ean' => null,
        'technical_specifications' => [
            [
                'key' => 'users_weight',
                'label' => 'Maksymalna waga użytkownika',
                'source_label' => 'users weight',
                'value' => '130 kg',
            ],
            [
                'key' => 'speed',
                'label' => 'Prędkość maksymalna',
                'source_label' => 'speed',
                'value' => '6 km/h',
            ],
        ],
        'attributes' => [
            'Maksymalna waga użytkownika' => '130 kg',
            'Prędkość maksymalna' => '6 km/h',
        ],
        'colors' => [
            [
                'type' => 'frame',
                'name' => 'C29 grey',
                'image_url' => 'https://www.vermeiren.pl/product/colors.nsf/O/C29/$FILE/C29-grey.jpg',
            ],
            [
                'type' => 'upholstery',
                'name' => 'Black nylon',
                'image_url' => 'https://www.vermeiren.pl/product/colors.nsf/O/BLACK/$FILE/black-nylon.jpg',
            ],
        ],
        'options' => [
            [
                'name' => 'SE42 winda siedziska',
                'image_url' => 'https://www.vermeiren.pl/product/picture.nsf/O/SE42/$FILE/SE42.jpg',
                'thumbnail_url' => 'https://www.vermeiren.pl/product/picture.nsf/O/SE42/$FILE/th_SE42.jpg',
            ],
            [
                'name' => 'SE19 oświetlenie LED',
                'image_url' => 'https://www.vermeiren.pl/product/picture.nsf/O/SE19/$FILE/SE19.jpg',
                'thumbnail_url' => 'https://www.vermeiren.pl/product/picture.nsf/O/SE19/$FILE/th_SE19.jpg',
            ],
        ],
        'documents' => [
            [
                'type' => 'manual',
                'name' => 'User manual',
                'url' => 'https://www.vermeiren.pl/product/manuals.nsf/O/MANUAL/$FILE/Navix-user-manual.pdf',
            ],
            [
                'type' => 'manual',
                'name' => 'Watch',
                'url' => 'https://www.youtube.com/watch?v=navix',
            ],
            [
                'type' => 'certificate',
                'name' => 'CE NAVIX',
                'url' => 'https://www.vermeiren.pl/product/certificate.nsf/O/CE/$FILE/CE-Navix.pdf',
            ],
            [
                'type' => 'spare_part',
                'name' => 'NAVIX frame',
                'url' => 'https://www.vermeiren.pl/product/spareparts.nsf/O/FRAME/$FILE/Navix-frame.pdf',
            ],
        ],
        'images' => [
            [
                'url' => 'https://www.vermeiren.pl/product/picture.nsf/O/MAIN/$FILE/navix.jpg',
                'alt' => 'NAVIX FWD',
                'is_primary' => true,
            ],
            [
                'url' => 'https://domino03.vermeiren.be/product/picture.nsf/SECOND/$File/navix-side.jpg',
                'alt' => 'NAVIX FWD widok z boku',
                'is_primary' => false,
            ],
        ],
        'variant_candidates' => [],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ];

    return array_replace($payload, $overrides);
}

function vermeirenImporterTestImageContents(): string
{
    $contents = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );

    if (! is_string($contents)) {
        throw new RuntimeException('Unable to decode Vermeiren importer test image.');
    }

    return $contents;
}
