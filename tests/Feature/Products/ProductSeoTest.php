<?php

use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Support\Seo\ProductIdentifiers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('renders product SEO metadata and structured data', function (): void {
    Config::set('app.name', 'Konji Shop');
    Config::set('app.url', 'https://konji-shop.example.test');
    Config::set('legal.seller.shop_name', 'Konji Shop');
    Config::set('legal.seller.company_name', 'Konji Shop Sp. z o.o.');

    $product = Product::query()->create([
        'name' => 'Premium Dog Harness',
        'slug' => 'premium-dog-harness',
        'short_description' => 'Comfortable adjustable dog harness for daily walks.',
        'description' => '<p>Longer product description should be stripped from SEO text.</p>',
        'seo_title' => 'Premium Dog Harness for Daily Walks',
        'seo_description' => 'Buy a comfortable adjustable dog harness with secure fit and VAT-inclusive pricing.',
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'HARNESS-BLACK-M',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $productUrl = 'https://konji-shop.example.test/products/'.$product->slug;

    $response = $this
        ->get($productUrl)
        ->assertOk()
        ->assertSee('<title>Premium Dog Harness for Daily Walks - Konji Shop</title>', false)
        ->assertSee('<meta name="description" content="Buy a comfortable adjustable dog harness with secure fit and VAT-inclusive pricing.">', false)
        ->assertSee('<link rel="canonical" href="'.$productUrl.'">', false)
        ->assertSee('<meta property="og:title" content="Premium Dog Harness for Daily Walks">', false)
        ->assertSee('<meta property="og:type" content="product">', false)
        ->assertSee('<meta property="og:url" content="'.$productUrl.'">', false)
        ->assertSee('<meta property="og:description" content="Buy a comfortable adjustable dog harness with secure fit and VAT-inclusive pricing.">', false)
        ->assertSee('<meta name="twitter:card" content="summary">', false)
        ->assertSee('<script type="application/ld+json">', false)
        ->assertSee('"@type": "Product"', false)
        ->assertSee('"name": "Premium Dog Harness"', false)
        ->assertSee('"sku": "HARNESS-BLACK-M"', false)
        ->assertSee('"@type": "Offer"', false)
        ->assertSee('"priceCurrency": "PLN"', false)
        ->assertSee('"price": "123.00"', false)
        ->assertSee('"availability": "https://schema.org/InStock"', false)
        ->assertSee('"@type": "MerchantReturnPolicy"', false)
        ->assertSee('"merchantReturnDays": 14', false)
        ->assertSee('"@type": "BreadcrumbList"', false);

    expect($response->getContent())
        ->toContain('"url": "'.$productUrl.'"');
});

it('renders category breadcrumbs above the product configurator and in structured data', function (): void {
    Config::set('app.name', 'Konji Shop');
    Config::set('app.url', 'https://konji-shop.example.test');

    $parentCategory = Category::query()->create([
        'name' => 'Orthopedic braces',
        'slug' => 'orthopedic-braces',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $category = Category::query()->create([
        'parent_id' => $parentCategory->id,
        'name' => 'Knee supports',
        'slug' => 'knee-supports',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $product = Product::query()->create([
        'name' => 'Premium Knee Brace',
        'slug' => 'premium-knee-brace',
        'short_description' => 'Supportive knee brace for daily use.',
        'status' => ProductStatus::ACTIVE,
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'KNEE-BRACE-M',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $productUrl = 'https://konji-shop.example.test/products/'.$product->slug;
    $parentCategoryUrl = 'https://konji-shop.example.test/categories/'.$parentCategory->slug;
    $categoryUrl = 'https://konji-shop.example.test/categories/'.$category->slug;

    $this
        ->get($productUrl)
        ->assertOk()
        ->assertSee('<nav class="mb-6 text-sm" aria-label="Breadcrumb">', false)
        ->assertSee('href="'.$parentCategoryUrl.'"', false)
        ->assertSee('Orthopedic braces', false)
        ->assertSee('href="'.$categoryUrl.'"', false)
        ->assertSee('Knee supports', false)
        ->assertSee('href="'.$productUrl.'"', false)
        ->assertSee('aria-current="page"', false)
        ->assertSee('Premium Knee Brace', false)
        ->assertSee('"@type": "BreadcrumbList"', false)
        ->assertSee('"position": 2', false)
        ->assertSee('"name": "Orthopedic braces"', false)
        ->assertSee('"item": "'.$parentCategoryUrl.'"', false)
        ->assertSee('"position": 3', false)
        ->assertSee('"name": "Knee supports"', false)
        ->assertSee('"item": "'.$categoryUrl.'"', false)
        ->assertSee('"position": 4', false)
        ->assertSee('"name": "Premium Knee Brace"', false)
        ->assertSee('"item": "'.$productUrl.'"', false);
});

it('falls back to product name and description when explicit SEO fields are missing', function (): void {
    Config::set('app.name', 'Konji Shop');

    $product = Product::query()->create([
        'name' => 'Fallback SEO Product',
        'slug' => 'fallback-seo-product',
        'short_description' => 'Short fallback description.',
        'description' => '<p>Long fallback description.</p>',
        'seo_title' => null,
        'seo_description' => null,
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'FALLBACK-SKU',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 5000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('<title>Fallback SEO Product - Konji Shop</title>', false)
        ->assertSee('<meta name="description" content="Short fallback description.">', false)
        ->assertSee('<meta property="og:title" content="Fallback SEO Product">', false)
        ->assertSee('<meta property="og:description" content="Short fallback description.">', false);
});

it('renders stored meta descriptions without broken list punctuation', function (): void {
    $product = Product::query()->create([
        'name' => 'OrthoSleeve FS6+',
        'slug' => 'orthosleeve-fs6',
        'seo_description' => 'Przeciążenia mięśnia brzuchatego łydki,. Niewydolność krążenia kończyn dolnych,. Zapalenie rozcięgna podeszwowego (tzw. ostroga piętowa),.',
        'status' => ProductStatus::ACTIVE,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('<meta name="description" content="Przeciążenia mięśnia brzuchatego łydki. Niewydolność krążenia kończyn dolnych. Zapalenie rozcięgna podeszwowego (tzw. ostroga piętowa).">', false)
        ->assertDontSee(',.', false);
});

it('renders product Open Graph and Twitter image metadata when a main image exists', function (): void {
    Config::set('app.name', 'Konji Shop');
    Config::set('app.url', 'https://konji-shop.example.test');
    Config::set('filesystems.disks.public.url', 'https://konji-shop.example.test/storage');

    Storage::fake('public');

    $product = Product::query()->create([
        'name' => 'Image SEO Product',
        'slug' => 'image-seo-product',
        'short_description' => 'Product with a main image.',
        'description' => '<p>Product with image metadata.</p>',
        'seo_title' => 'Image SEO Product',
        'seo_description' => 'Product page with Open Graph image metadata.',
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'IMAGE-SEO-SKU',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 7500,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    Storage::disk('public')->put('products/image-seo-product/main.webp', 'fake-image-content');

    ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/image-seo-product/main.webp',
        'source_url' => null,
        'mime_type' => 'image/webp',
        'file_size' => 18,
        'sha256' => hash('sha256', 'fake-image-content'),
        'alt_text' => 'Image SEO Product main image',
        'title' => 'Image SEO Product',
        'sort_order' => 1,
        'is_main' => true,
    ]);

    $imageUrl = 'https://konji-shop.example.test/storage/products/image-seo-product/main.webp';

    $this
        ->get('https://konji-shop.example.test/products/'.$product->slug)
        ->assertOk()
        ->assertSee('<meta property="og:image" content="'.$imageUrl.'">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<meta name="twitter:image" content="'.$imageUrl.'">', false)
        ->assertSee('"image": [', false)
        ->assertSee('"'.$imageUrl.'"', false);
});

it('does not render inactive products publicly', function (): void {
    $product = Product::query()->create([
        'name' => 'Draft SEO Product',
        'slug' => 'draft-seo-product',
        'short_description' => 'Draft product should not be indexable.',
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertNotFound();
});

it('uses only active variants for product SEO and page payload', function (): void {
    Config::set('app.name', 'Konji Shop');

    $product = Product::query()->create([
        'name' => 'Variant SEO Product',
        'slug' => 'variant-seo-product',
        'short_description' => 'Product with active and inactive variants.',
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'INACTIVE-DEFAULT-SKU',
        'status' => ProductVariantStatus::ARCHIVED,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'ACTIVE-SKU',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 2000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => false,
    ]);

    $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('"sku": "ACTIVE-SKU"', false)
        ->assertSee('"price": "24.60"', false)
        ->assertDontSee('INACTIVE-DEFAULT-SKU');
});

it('does not claim that the retailer is the brand of every product', function (): void {
    Config::set('legal.seller.shop_name', 'Ortezka.pl');
    Config::set('legal.seller.identity_address', '');
    Config::set('legal.seller.company_name', 'MAX-CORP sp. z o.o.');
    $product = Product::query()->create([
        'name' => 'Manufacturer product',
        'slug' => 'manufacturer-product',
        'status' => ProductStatus::ACTIVE,
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'MANUFACTURER-SKU',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);
    $response = $this->get(route('products.show', $product->slug))->assertOk();
    $schema = $response->viewData('structuredData')[0];

    expect($schema)->not->toHaveKey('brand');
    expect($schema['offers']['seller']['name'])->toBe('MAX-CORP sp. z o.o.');
    expect($schema['offers']['price'])->toBe('123.00');
});

it('omits Product structured data when no active variant has a price', function (): void {
    $product = Product::query()->create([
        'name' => 'Price on request wheelchair',
        'slug' => 'price-on-request-wheelchair',
        'status' => ProductStatus::ACTIVE,
    ]);
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'POR-WHEELCHAIR',
        'status' => ProductVariantStatus::ACTIVE,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $response = $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('"@type": "BreadcrumbList"', false)
        ->assertDontSee('"@type": "Product"', false);

    expect($response->viewData('structuredData'))->toHaveCount(1);
});

it('adds the manufacturer brand and a single valid GTIN to Product structured data', function (): void {
    Config::set('legal.seller.company_name', 'MAX-CORP sp. z o.o.');
    $product = Product::query()->create([
        'name' => 'Podnóżek RF-3',
        'slug' => 'podnozek-rf-3',
        'status' => ProductStatus::ACTIVE,
        'description' => '<p>Podnóżek do wózka.</p><table><tr><th>Kod EAN</th><td>5901234123457</td></tr></table>',
    ]);
    $producer = Attribute::query()->create(['name' => 'Producent', 'slug' => 'producent']);
    $product->attributeValues()->attach(
        AttributeValue::query()->create(['attribute_id' => $producer->id, 'value' => 'Reha Fund sp. z o. o.', 'slug' => 'reha-fund'])
    );
    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'RF-3',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 10000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $schema = $this->get(route('products.show', $product->slug))->assertOk()->viewData('structuredData')[0];

    expect($schema['brand'])->toBe(['@type' => 'Brand', 'name' => 'Reha Fund'])
        ->and($schema['gtin'])->toBe('5901234123457');
});

it('leaves GTIN out when it is ambiguous or invalid', function (): void {
    $description = '<table><tr><th>EAN</th><td>5901234123457</td></tr></table>';

    expect(ProductIdentifiers::gtin($description, 1))->toBe('5901234123457')
        ->and(ProductIdentifiers::gtin($description, 3))->toBeNull()
        ->and(ProductIdentifiers::gtin('<p>Kod EAN: 5901234123458</p>', 1))->toBeNull()
        ->and(ProductIdentifiers::gtin('<p>EAN 5901234123457, EAN 4006381333931</p>', 1))->toBeNull()
        ->and(ProductIdentifiers::gtin('<p>Kod EAN5903</p>', 1))->toBeNull()
        ->and(ProductIdentifiers::brandName('Antar Spółka Jawna'))->toBe('Antar')
        ->and(ProductIdentifiers::brandName('Medi GmbH'))->toBe('Medi');
});

it('prefers the default variant GTIN for products with several variants', function (): void {
    $product = Product::query()->create(['name' => 'Peruka Coral', 'slug' => 'peruka-coral', 'status' => ProductStatus::ACTIVE]);

    foreach ([['CORAL-1', '5901234123457', true], ['CORAL-2', '4006381333931', false]] as [$sku, $gtin, $isDefault]) {
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'gtin' => $gtin,
            'status' => ProductVariantStatus::ACTIVE,
            'price_net_amount' => 10000,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::VAT_23,
            'stock_status' => StockStatus::IN_STOCK,
            'is_default' => $isDefault,
        ]);
    }

    $schema = $this->get(route('products.show', $product->slug))->assertOk()->viewData('structuredData')[0];

    expect($schema['sku'])->toBe('CORAL-1')
        ->and($schema['gtin'])->toBe('5901234123457');
});
