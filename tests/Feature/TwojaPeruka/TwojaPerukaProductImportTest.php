<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can dry-run a TwojaPeruka full product-data file without database writes', function (): void {
    $path = storage_path('app/scrapers/twojaperuka/full-product-data-import-test.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'twojaperuka',
        'products' => [twojaPerukaImporterProductPayload()],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('twojaperuka:import', [
        '--from' => 'scrapers/twojaperuka/full-product-data-import-test.json',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run summary. No database writes were made.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Categories to create/update: 3')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)
        ->and(Category::query()->count())->toBe(0)
        ->and(ProductVariant::query()->count())->toBe(0);
});

it('imports TwojaPeruka scraped JSON as draft products with category hierarchy and default variants', function (): void {
    $path = storage_path('app/scrapers/twojaperuka/full-product-data-import-test.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'twojaperuka',
        'products' => [twojaPerukaImporterProductPayload()],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('twojaperuka:import', [
        '--from' => 'scrapers/twojaperuka/full-product-data-import-test.json',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'twojaperuka')
        ->where('external_id', '57')
        ->firstOrFail();

    expect($product->name)->toBe('Peruka syntetyczna, kolor blond, krótkie włosy - IRIS')
        ->and($product->slug)->toBe('iris')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->seo_title)->toBe('peruka Iris** twojaperuka.pl')
        ->and($product->description)->toContain('Peruka syntetyczna Iris')
        ->and($product->description)->not->toContain('<script')
        ->and($product->description)->not->toContain('<a ');

    $rootCategory = Category::query()->where('slug', 'peruki')->firstOrFail();
    $middleCategory = Category::query()->where('slug', 'peruki-syntetyczne')->firstOrFail();
    $leafCategory = Category::query()->where('slug', 'peruki-flower-collection')->firstOrFail();

    expect($middleCategory->parent_id)->toBe($rootCategory->id)
        ->and($leafCategory->parent_id)->toBe($middleCategory->id)
        ->and($product->categories()->whereKey($leafCategory->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($product->categories()->whereKey($rootCategory->id)->exists())->toBeTrue();

    $variant = ProductVariant::query()
        ->where('product_id', $product->id)
        ->where('external_variant_id', 'twojaperuka-57-default')
        ->firstOrFail();

    expect($variant->sku)->toBe('TWOJAPERUKA-57')
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->is_default)->toBeTrue()
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->price_gross_amount)->toBe(54000)
        ->and($variant->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(54000))
        ->and($variant->stock_status->isInStock())->toBeTrue();

    expect($product->images()->count())->toBe(0);
});

it('can import TwojaPeruka products as active products', function (): void {
    $path = storage_path('app/scrapers/twojaperuka/full-product-data-active-import-test.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'twojaperuka',
        'products' => [twojaPerukaImporterProductPayload()],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('twojaperuka:import', [
        '--from' => 'scrapers/twojaperuka/full-product-data-active-import-test.json',
        '--status' => 'active',
        '--no-images' => true,
    ])->assertSuccessful();

    $product = Product::query()
        ->where('external_source', 'twojaperuka')
        ->where('external_id', '57')
        ->firstOrFail();

    $variant = $product->variants()->firstOrFail();

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and($variant->status)->toBe(ProductVariantStatus::ACTIVE);
});

it('updates an existing TwojaPeruka product instead of duplicating it', function (): void {
    $path = storage_path('app/scrapers/twojaperuka/full-product-data-update-import-test.json');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode([
        'source' => 'twojaperuka',
        'products' => [twojaPerukaImporterProductPayload()],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('twojaperuka:import', [
        '--from' => 'scrapers/twojaperuka/full-product-data-update-import-test.json',
        '--no-images' => true,
    ])->assertSuccessful();

    file_put_contents($path, json_encode([
        'source' => 'twojaperuka',
        'products' => [twojaPerukaImporterProductPayload([
            'name' => 'Updated IRIS',
            'price_gross_amount' => 600.00,
        ])],
    ], JSON_THROW_ON_ERROR));

    $this->artisan('twojaperuka:import', [
        '--from' => 'scrapers/twojaperuka/full-product-data-update-import-test.json',
        '--no-images' => true,
    ])->assertSuccessful();

    expect(Product::query()->where('external_source', 'twojaperuka')->where('external_id', '57')->count())->toBe(1);

    $product = Product::query()
        ->where('external_source', 'twojaperuka')
        ->where('external_id', '57')
        ->firstOrFail();

    expect($product->name)->toBe('Updated IRIS')
        ->and($product->variants()->firstOrFail()->price_gross_amount)->toBe(60000);
});

function twojaPerukaImporterProductPayload(array $overrides = []): array
{
    return array_replace([
        'source' => 'twojaperuka',
        'source_url' => 'https://twojaperuka.pl/iris',
        'canonical_url' => 'https://twojaperuka.pl/iris',
        'external_product_id' => '57',
        'slug' => 'iris',
        'name' => 'Peruka syntetyczna, kolor blond, krótkie włosy - IRIS',
        'brand' => 'NAH',
        'category' => 'Peruki Flower Collection',
        'categories' => [
            'Peruki',
            'Peruki syntetyczne',
            'Peruki Flower Collection',
        ],
        'seo_title' => 'peruka Iris** twojaperuka.pl',
        'seo_description' => 'Peruka Iris** w kategorii Flower Collection',
        'short_description' => 'Peruka Iris** w kategorii Flower Collection',
        'short_description_html' => null,
        'description' => 'Peruka syntetyczna Iris opis produktu.',
        'description_html' => '<div><p>Peruka syntetyczna Iris opis produktu.</p><p><a href="https://twojaperuka.pl/manual">Link text</a></p><script>alert("x")</script></div>',
        'price_gross_amount' => 540.00,
        'currency' => 'PLN',
        'availability' => 'low_stock',
        'availability_label' => 'na wyczerpaniu',
        'stock_quantity' => null,
        'sku' => null,
        'ean' => null,
        'images' => [
            [
                'url' => 'https://twojaperuka.pl/environment/cache/images/productGfx_iris_0_0/iris.webp',
                'alt' => 'IRIS',
                'position' => 1,
            ],
        ],
        'variant_options' => [],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ], $overrides);
}
