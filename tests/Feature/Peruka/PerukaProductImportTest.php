<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can dry-run a Peruka product-data file without database writes', function (): void {
    writePerukaImportFixture('scrapers/peruka/test-product-data.json', [perukaImportProductPayload()]);

    $this->artisan('peruka:import', [
        '--from' => 'scrapers/peruka/test-product-data.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made. No images were downloaded.')
        ->expectsOutputToContain('Products to create/update: 1')
        ->expectsOutputToContain('Default variants to create/update: 1')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports Peruka product-data as independent products with one default variant each', function (): void {
    writePerukaImportFixture('scrapers/peruka/test-product-data.json', [
        perukaImportProductPayload(),
        perukaImportProductPayload([
            'external_product_id' => '38773',
            'source_url' => 'https://www.peruka.pl/turban-verbena-dec63-flower.html',
            'canonical_url' => 'https://www.peruka.pl/turban-verbena-dec63-flower.html',
            'slug' => 'turban-verbena-dec63-flower',
            'name' => 'Turban z oryginalnym węzłem beżowy VERBENA DEC63 + B9',
            'sku' => '38773',
            'ean' => '5900000106182',
            'price_gross_amount' => 90.00,
            'stock_quantity' => 56,
        ]),
    ]);

    $this->artisan('peruka:import', [
        '--from' => 'scrapers/peruka/test-product-data.json',
        '--status' => 'active',
        '--no-images' => true,
    ])->assertSuccessful();

    $firstProduct = Product::query()
        ->where('external_source', 'peruka')
        ->where('external_id', '38772')
        ->firstOrFail();

    $secondProduct = Product::query()
        ->where('external_source', 'peruka')
        ->where('external_id', '38773')
        ->firstOrFail();

    expect($firstProduct->name)->toBe('Turban z oryginalnym węzłem różowy VERBENA ME12+B61')
        ->and($firstProduct->slug)->toBe('turban-verbena-me12-flower')
        ->and($firstProduct->status)->toBe(ProductStatus::ACTIVE)
        ->and($firstProduct->seo_title)->toBe('Turban z oryginalnym węzłem różowy VERBENA # ME12+B61 | Peruka.pl')
        ->and($firstProduct->description)->toContain('<h2>Turban z oryginalnym węzłem różowy VERBENA ME12+B61</h2>')
        ->and($firstProduct->external_parent_sku)->toBe('https://www.peruka.pl/turban-verbena-me12-flower.html')
        ->and($secondProduct->external_id)->toBe('38773');

    $topCategory = Category::query()->where('slug', 'turbany')->firstOrFail();
    $childCategory = Category::query()->where('slug', 'flower')->firstOrFail();

    expect($childCategory->parent_id)->toBe($topCategory->id)
        ->and($firstProduct->categories()->whereKey($childCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue();

    $variant = ProductVariant::query()
        ->where('product_id', $firstProduct->id)
        ->firstOrFail();

    expect($variant->sku)->toBe('38772')
        ->and($variant->external_variant_id)->toBe('peruka-38772-default')
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($variant->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variant->price_gross_amount)->toBe(9000)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_23->netFromGross(9000));

    expect(ProductVariant::query()->where('product_id', $secondProduct->id)->count())->toBe(1)
        ->and(Product::query()->where('external_source', 'peruka')->count())->toBe(2);
});

it('updates existing Peruka products by external ID instead of duplicating them', function (): void {
    writePerukaImportFixture('scrapers/peruka/test-product-data.json', [perukaImportProductPayload()]);

    $this->artisan('peruka:import', [
        '--from' => 'scrapers/peruka/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    writePerukaImportFixture('scrapers/peruka/test-product-data.json', [
        perukaImportProductPayload([
            'name' => 'Updated Peruka product name',
            'price_gross_amount' => 95.50,
            'availability' => 'out_of_stock',
            'stock_quantity' => 0,
        ]),
    ]);

    $this->artisan('peruka:import', [
        '--from' => 'scrapers/peruka/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'peruka')
        ->where('external_id', '38772')
        ->firstOrFail();

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->firstOrFail();

    expect(Product::query()->where('external_source', 'peruka')->count())->toBe(1)
        ->and(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and($product->name)->toBe('Updated Peruka product name')
        ->and($variant->price_gross_amount)->toBe(9550)
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variant->status)->toBe(ProductVariantStatus::ACTIVE);
});



it('strips anchor tags from Peruka descriptions during import', function (): void {
    writePerukaImportFixture('scrapers/peruka/test-product-data.json', [
        perukaImportProductPayload([
            'description_html' => '<p>Read <a href="https://www.peruka.pl/example.html">product guide</a> and <a href="/more"><strong>details</strong></a>.</p>',
            'short_description_html' => '<p>Short <a href="https://www.peruka.pl/example.html">link text</a>.</p>',
        ]),
    ]);

    $this->artisan('peruka:import', [
        '--from' => 'scrapers/peruka/test-product-data.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'peruka')
        ->where('external_id', '38772')
        ->firstOrFail();

    expect($product->description)->toBe('<p>Read product guide and <strong>details</strong>.</p>')
        ->and($product->short_description)->toBe('<p>Short link text.</p>')
        ->and($product->description)->not->toContain('<a ')
        ->and($product->short_description)->not->toContain('<a ');
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writePerukaImportFixture(string $relativePath, array $products): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'peruka',
        'products' => $products,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function perukaImportProductPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'peruka',
        'external_product_id' => '38772',
        'source_url' => 'https://www.peruka.pl/turban-verbena-me12-flower.html',
        'canonical_url' => 'https://www.peruka.pl/turban-verbena-me12-flower.html',
        'slug' => 'turban-verbena-me12-flower',
        'name' => 'Turban z oryginalnym węzłem różowy VERBENA ME12+B61',
        'brand' => 'Rokoko Hair Company',
        'category' => 'FLOWER',
        'categories' => ['Turbany', 'FLOWER'],
        'seo_title' => 'Turban z oryginalnym węzłem różowy VERBENA # ME12+B61 | Peruka.pl',
        'seo_description' => 'Turban z oryginalnym węzłem różowy VERBENA ME12+B61 Elegancki turban z ciekawym wiązaniem.',
        'short_description' => 'Turban z oryginalnym węzłem różowy VERBENA ME12+B61 Elegancki turban z ciekawym wiązaniem.',
        'short_description_html' => null,
        'description_html' => '<h2>Turban z oryginalnym węzłem różowy VERBENA ME12+B61</h2><p>Elegancki turban.</p>',
        'price_gross_amount' => 90.00,
        'currency' => 'PLN',
        'stock_quantity' => 33,
        'availability' => 'in_stock',
        'availability_label' => 'Produkt jest dostępny',
        'ean' => '5900000106175',
        'sku' => '38772',
        'images' => [
            [
                'url' => 'https://www.peruka.pl/media/products/2c1da3f35b5376c52fa2d2ed2ebecbe3/images/verbena-ME12-B61-side-900x900.webp',
                'alt' => null,
            ],
        ],
        'variant_product_urls' => ['https://www.peruka.pl/turban-verbena-dec63-flower.html'],
        'is_medical_device' => false,
        'warnings' => [],
    ], $overrides);
}
