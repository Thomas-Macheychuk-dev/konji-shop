<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('can dry-run a Timago product-data file without database writes', function (): void {
    writeTimagoImportFixture('scrapers/timago/test-product-data.json', [timagoImportProductPayload()]);

    $this->artisan('timago:import', [
        '--from' => 'scrapers/timago/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 2')
        ->expectsOutputToContain('Product images discovered: 2')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports Timago product-data as draft products with category hierarchy, safe description, attributes, and default variants', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://www.timago.com/_pliki_/produkty/1306/description.jpg' => Http::response(timagoTinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        '*' => Http::response('', 404),
    ]);

    writeTimagoImportFixture('scrapers/timago/test-product-data.json', [timagoImportProductPayload()]);

    $this->artisan('timago:import', [
        '--from' => 'scrapers/timago/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'timago')
        ->where('external_id', '1306')
        ->firstOrFail();

    expect($product->name)->toBe('Wózek inwalidzki elektryczny - Maya')
        ->and($product->slug)->toBe('wozek-inwalidzki-elektryczny-maya')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('MAYA')
        ->and($product->seo_title)->toBe('Wózek inwalidzki elektryczny - Maya | Timago')
        ->and($product->short_description)->toBe('<p>Lekki elektryczny wózek inwalidzki Maya.</p>')
        ->and($product->description)->toContain('Bardzo lekki elektryczny wózek')
        ->and($product->description)->toContain('Dane produktu')
        ->and($product->description)->toContain('Kod produktu')
        ->and($product->description)->toContain('Wyrób medyczny')
        ->and($product->description)->not->toContain('href=')
        ->and($product->description)->not->toContain('timago.com/pl/rehabilitacja')
        ->and($product->description)->not->toContain('<th>Dostępność</th>')
        ->and($product->description)->not->toContain('<th>Wysyłka</th>')
        ->and($product->description)->not->toContain('48 godzin');

    $rootCategory = Category::query()->where('slug', 'rehabilitacja')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'wozki-elektryczne')->firstOrFail();

    expect($leafCategory->parent_id)->toBe($rootCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($rootCategory->id)->exists())->toBeTrue();

    expect($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'producent'))->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wyrob-medyczny'))->exists())->toBeTrue()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'dostepnosc'))->exists())->toBeFalse()
        ->and($product->attributeValues()->whereHas('attribute', fn ($query) => $query->where('slug', 'wysylka'))->exists())->toBeFalse();

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'timago-1306-default')
        ->firstOrFail();

    expect($variant->sku)->toBe('MAYA')
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->currency)->toBe(Currency::PLN)
        ->and($variant->price_gross_amount)->toBe(499900)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(499900))
        ->and($variant->stock_status)->toBe(StockStatus::IN_STOCK);

    expect($product->images()->count())->toBe(0);

    expect(Storage::disk('public')->allFiles('products/timago/1306/content'))->toHaveCount(0);
});

it('downloads Timago gallery images when image import is enabled', function (): void {
    Storage::fake('public');
    Http::fake([
        'https://www.timago.com/_pliki_/produkty/1306/b-wozek-inwalidzki-elektryczny-maya.jpg' => Http::response(timagoTinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        'https://www.timago.com/_pliki_/produkty/1306/wozek-inwalidzki-elektryczny-maya-side.jpg' => Http::response(timagoTinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        'https://www.timago.com/_pliki_/produkty/1306/description.jpg' => Http::response(timagoTinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        '*' => Http::response('', 404),
    ]);

    writeTimagoImportFixture('scrapers/timago/test-product-data-images.json', [timagoImportProductPayload()]);

    $this->artisan('timago:import', [
        '--from' => 'scrapers/timago/test-product-data-images.json',
        '--image-limit' => '1',
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'timago')
        ->where('external_id', '1306')
        ->firstOrFail();

    expect($product->images()->count())->toBe(1)
        ->and($product->images()->firstOrFail()->is_main)->toBeTrue()
        ->and(Storage::disk('public')->allFiles('products/timago/1306/gallery'))->toHaveCount(1);
});

it('updates existing Timago products by external ID instead of duplicating them', function (): void {
    writeTimagoImportFixture('scrapers/timago/test-product-data-update.json', [timagoImportProductPayload()]);

    $this->artisan('timago:import', [
        '--from' => 'scrapers/timago/test-product-data-update.json',
        '--no-images' => true,
    ])->assertSuccessful();

    writeTimagoImportFixture('scrapers/timago/test-product-data-update.json', [
        timagoImportProductPayload([
            'name' => 'Updated Timago product',
            'price_gross_amount' => null,
            'availability' => 'out_of_stock',
            'availability_label' => 'Brak produktu',
        ]),
    ]);

    $this->artisan('timago:import', [
        '--from' => 'scrapers/timago/test-product-data-update.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'timago')
        ->where('external_id', '1306')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect(Product::query()->where('external_source', 'timago')->where('external_id', '1306')->count())->toBe(1)
        ->and($product->name)->toBe('Updated Timago product')
        ->and($variant->price_gross_amount)->toBeNull()
        ->and($variant->price_net_amount)->toBeNull()
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
});

function writeTimagoImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'timago',
        'products' => $products,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function timagoImportProductPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'timago',
        'source_url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
        'canonical_url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
        'external_product_id' => '1306',
        'slug' => 'wozek-inwalidzki-elektryczny-maya',
        'name' => 'Wózek inwalidzki elektryczny - Maya',
        'brand' => ['name' => 'Timago', 'slug' => 'timago'],
        'category' => 'Wózki elektryczne',
        'categories' => ['Rehabilitacja', 'Wózki elektryczne'],
        'product_link_category_path' => ['Rehabilitacja', 'Wózki elektryczne'],
        'seo_title' => 'Wózek inwalidzki elektryczny - Maya | Timago',
        'seo_description' => 'Lekki elektryczny wózek inwalidzki Maya.',
        'short_description' => 'Lekki elektryczny wózek inwalidzki Maya.',
        'description_html' => '<div><p><strong>Bardzo lekki elektryczny wózek</strong> dla pacjentów.</p><p><a href="https://www.timago.com/pl/rehabilitacja/">Link supplier</a></p><img src="https://www.timago.com/_pliki_/produkty/1306/description.jpg" srcset="https://www.timago.com/_pliki_/produkty/1306/description.jpg 600w" alt="Opis"></div>',
        'price_gross_amount' => '4999.00',
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'shipping_time' => '48 godzin',
        'sku' => 'MAYA',
        'ean' => '5900000000001',
        'attributes' => [
            ['code' => 'kod-produktu', 'label' => 'Kod produktu', 'value' => 'MAYA', 'slug' => 'maya'],
            ['code' => 'dostepnosc', 'label' => 'Dostępność', 'value' => 'Dostępny', 'slug' => 'dostepny'],
            ['code' => 'wysylka', 'label' => 'Wysyłka', 'value' => '48 godzin', 'slug' => '48-godzin'],
        ],
        'images' => [
            [
                'url' => 'https://www.timago.com/_pliki_/produkty/1306/b-wozek-inwalidzki-elektryczny-maya.jpg',
                'alt' => 'Wózek inwalidzki elektryczny - Maya',
                'sort_order' => 0,
            ],
            [
                'url' => 'https://www.timago.com/_pliki_/produkty/1306/wozek-inwalidzki-elektryczny-maya-side.jpg',
                'alt' => 'Maya bok',
                'sort_order' => 1,
            ],
        ],
        'variant_candidates' => [],
        'is_medical_device' => true,
    ], $overrides);
}

function timagoTinyJpeg(): string
{
    return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAqf/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/ASP/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/ASP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Ap//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IV//2gAMAwEAAgADAAAAEP/EFBQRAQAAAAAAAAAAAAAAAAAAARD/2gAIAQMBAT8QH//EFBQRAQAAAAAAAAAAAAAAAAAAARD/2gAIAQIBAT8QH//EFBABAQAAAAAAAAAAAAAAAAAAARD/2gAIAQEAAT8QH//Z') ?: 'jpeg';
}
