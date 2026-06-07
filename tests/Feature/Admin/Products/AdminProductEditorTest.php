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
use App\Models\ProductAttributeValueImage;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCategoryForAdminProductEditorTest(string $name = 'Medical Clothing', string $slug = 'medical-clothing'): Category
{
    return Category::query()->create([
        'name' => $name,
        'slug' => $slug,
        'status' => CategoryStatus::ACTIVE,
    ]);
}

function createProductForAdminProductEditorTest(): Product
{
    $product = Product::query()->create([
        'name' => 'Editable Product',
        'slug' => 'editable-product',
        'short_description' => 'Existing short description.',
        'description' => '<p>Existing <strong>HTML</strong> description.</p>',
        'status' => ProductStatus::ACTIVE,
        'seo_title' => 'Existing SEO title',
        'seo_description' => 'Existing SEO description.',
        'external_source' => 'test',
        'external_id' => 'external-123',
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'EDIT-MISSING',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'EDIT-COMPLETE',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 2000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => false,
        'package_weight_grams' => 500,
        'package_length_mm' => 300,
        'package_width_mm' => 200,
        'package_height_mm' => 100,
    ]);

    return $product;
}

/**
 * @return array{product_image: ProductImage, second_product_image: ProductImage, attribute_value_image: ProductAttributeValueImage}
 */
function createImagesForAdminProductEditorTest(Product $product): array
{
    $productImage = ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/editable/base-product-image.jpg',
        'alt_text' => 'Base product image',
        'title' => 'Base product image',
        'sort_order' => 1,
        'is_main' => true,
    ]);

    $secondProductImage = ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => 'products/editable/second-product-image.jpg',
        'alt_text' => 'Second product image',
        'title' => 'Second product image',
        'sort_order' => 2,
        'is_main' => false,
    ]);

    $attribute = Attribute::query()->create([
        'name' => 'Colour',
        'slug' => 'colour',
    ]);

    $attributeValue = AttributeValue::query()->create([
        'attribute_id' => $attribute->id,
        'value' => 'Navy',
        'slug' => 'navy',
        'sort_order' => 1,
    ]);

    $product->variants()->firstOrFail()->attributeValues()->attach($attributeValue);

    $attributeValueImage = ProductAttributeValueImage::query()->create([
        'product_id' => $product->id,
        'attribute_value_id' => $attributeValue->id,
        'disk' => 'public',
        'path' => 'products/editable/navy-variant-image.jpg',
        'alt_text' => 'Navy variant image',
        'title' => 'Navy variant image',
        'sort_order' => 1,
        'is_main' => true,
    ]);

    return [
        'product_image' => $productImage,
        'second_product_image' => $secondProductImage,
        'attribute_value_image' => $attributeValueImage,
    ];
}

it('shows the admin product index', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index'))
        ->assertOk()
        ->assertSee('Produkty')
        ->assertSee('Editable Product')
        ->assertSee('1 z brakującą wagą/wymiarami');
});

it('shows the admin product edit page with variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertSee('Nazwa produktu')
        ->assertSee('Zapisz produkt')
        ->assertSee('Domyślne zdjęcie produktu')
        ->assertSee('Zastosuj cenę do wszystkich wariantów')
        ->assertSee('Ceny wariantów')
        ->assertSee('Zastosuj dane paczki do wszystkich wariantów')
        ->assertSee('EDIT-MISSING')
        ->assertSee('EDIT-COMPLETE')
        ->assertSee('Status produktu')
        ->assertSee('Kategoria produktu')
        ->assertSee('Krótki opis')
        ->assertSee('Existing short description.')
        ->assertSee('Opis HTML produktu')
        ->assertSee('&lt;p&gt;Existing &lt;strong&gt;HTML&lt;/strong&gt; description.&lt;/p&gt;', false)
        ->assertSee('Tytuł SEO')
        ->assertSee('Existing SEO title')
        ->assertSee('Opis SEO')
        ->assertSee('Existing SEO description.')
        ->assertSee('Brak kategorii')
        ->assertSee(ProductStatus::DRAFT->label())
        ->assertSee(ProductStatus::ACTIVE->label())
        ->assertSee(ProductStatus::ARCHIVED->label());
});

it('allows an admin to update product content and SEO fields', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $description = <<<'HTML'
<h2>Updated product description</h2>
<p>This <strong>HTML</strong> should be saved exactly.</p>
<ul><li>First benefit</li><li>Second benefit</li></ul>
HTML;

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => 'Updated Product Name',
            'short_description' => 'Updated short product summary.',
            'description' => $description,
            'seo_title' => 'Updated SEO title',
            'seo_description' => 'Updated SEO description for search results.',
            'status' => ProductStatus::DRAFT->value,
            'category_id' => null,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh())
        ->name->toBe('Updated Product Name')
        ->short_description->toBe('Updated short product summary.')
        ->description->toBe($description)
        ->seo_title->toBe('Updated SEO title')
        ->seo_description->toBe('Updated SEO description for search results.')
        ->status->toBe(ProductStatus::DRAFT);
});

it('shows selectable active categories on the admin product edit page', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $currentCategory = createCategoryForAdminProductEditorTest('Medical Tunics', 'medical-tunics');
    $newCategory = createCategoryForAdminProductEditorTest('Medical Trousers', 'medical-trousers');

    $product->categories()->attach($currentCategory->id, ['is_primary' => true]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('Kategoria produktu')
        ->assertSee('Brak kategorii')
        ->assertSee('Medical Tunics')
        ->assertSee('Medical Trousers');
});

it('allows an admin to change the product category', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $oldCategory = createCategoryForAdminProductEditorTest('Old Category', 'old-category');
    $newCategory = createCategoryForAdminProductEditorTest('New Category', 'new-category');

    $product->categories()->attach($oldCategory->id, ['is_primary' => true]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => $product->name,
            'status' => ProductStatus::ACTIVE->value,
            'category_id' => $newCategory->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh()->categories()->pluck('categories.id')->all())
        ->toBe([$newCategory->id]);

    expect((bool) $product->categories()->firstOrFail()->pivot->is_primary)->toBeTrue();
});

it('allows an admin to remove the product category', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $category = createCategoryForAdminProductEditorTest();

    $product->categories()->attach($category->id, ['is_primary' => true]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => $product->name,
            'status' => ProductStatus::ACTIVE->value,
            'category_id' => null,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh()->categories()->count())->toBe(0);
});

it('validates the product category when updating product details', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => $product->name,
            'status' => ProductStatus::ACTIVE->value,
            'category_id' => 999999,
        ])
        ->assertSessionHasErrors(['category_id']);

    expect($product->refresh()->categories()->count())->toBe(0);
});


it('shows selectable product and variant images on the admin product edit page', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    createImagesForAdminProductEditorTest($product);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('Domyślne zdjęcie produktu')
        ->assertSee('Zdjęcia produktu')
        ->assertSee('Zdjęcia wariantów')
        ->assertSee('Base product image')
        ->assertSee('Colour: Navy')
        ->assertSee('Zapisz domyślne zdjęcie');
});

it('allows an admin to choose a product gallery image as the default product image', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $images = createImagesForAdminProductEditorTest($product);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.default-image.update', $product), [
            'default_image' => Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE.':'.$images['second_product_image']->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh())
        ->default_image_type->toBe(Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE)
        ->default_image_id->toBe($images['second_product_image']->id)
        ->default_image_url->toBe($images['second_product_image']->refresh()->url);

    expect($images['product_image']->refresh()->is_main)->toBeFalse();
    expect($images['second_product_image']->refresh()->is_main)->toBeTrue();
});

it('allows an admin to choose a variant image as the default product image', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $images = createImagesForAdminProductEditorTest($product);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.default-image.update', $product), [
            'default_image' => Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE.':'.$images['attribute_value_image']->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh())
        ->default_image_type->toBe(Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE)
        ->default_image_id->toBe($images['attribute_value_image']->id)
        ->default_image_url->toBe($images['attribute_value_image']->refresh()->url);

    expect($images['product_image']->refresh()->is_main)->toBeTrue();
});

it('does not allow an admin to choose an image from another product as the default product image', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    createImagesForAdminProductEditorTest($product);

    $otherProduct = Product::query()->create([
        'name' => 'Other Product',
        'slug' => 'other-default-image-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $otherImage = ProductImage::query()->create([
        'product_id' => $otherProduct->id,
        'disk' => 'public',
        'path' => 'products/other/image.jpg',
        'alt_text' => 'Other image',
        'sort_order' => 1,
        'is_main' => true,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.default-image.update', $product), [
            'default_image' => Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE.':'.$otherImage->id,
        ])
        ->assertSessionHasErrors(['default_image']);

    expect($product->refresh())
        ->default_image_type->toBeNull()
        ->default_image_id->toBeNull();
});


it('allows an admin to apply a price to all product variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.prices.update', $product), [
            'gross_price' => '123.00',
            'currency' => Currency::PLN->value,
            'vat_rate' => VatRate::VAT_23->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ($product->variants()->get() as $variant) {
        $variant->refresh();

        expect($variant)
            ->price_net_amount->toBe(VatRate::VAT_23->netFromGross(12300))
            ->currency->toBe(Currency::PLN)
            ->vat_rate->toBe(VatRate::VAT_23);

        expect($variant->grossPriceAmount())->toBe(12300);
    }
});

it('allows an admin to update variant prices separately', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $variants = $product->variants()->orderBy('id')->get();

    $first = $variants[0];
    $second = $variants[1];

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.prices.update', $product), [
            'variants' => [
                $first->id => [
                    'gross_price' => '49.99',
                    'currency' => Currency::PLN->value,
                    'vat_rate' => VatRate::VAT_23->value,
                ],
                $second->id => [
                    'gross_price' => '59.99',
                    'currency' => Currency::PLN->value,
                    'vat_rate' => VatRate::VAT_8->value,
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $first->refresh();
    $second->refresh();

    expect($first)
        ->price_net_amount->toBe(VatRate::VAT_23->netFromGross(4999))
        ->currency->toBe(Currency::PLN)
        ->vat_rate->toBe(VatRate::VAT_23);

    expect($first->grossPriceAmount())->toBe(4999);

    expect($second)
        ->price_net_amount->toBe(VatRate::VAT_8->netFromGross(5999))
        ->currency->toBe(Currency::PLN)
        ->vat_rate->toBe(VatRate::VAT_8);

    expect($second->grossPriceAmount())->toBe(5999);
});

it('does not allow an admin to update prices for variants from another product', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $otherProduct = Product::query()->create([
        'name' => 'Other Price Product',
        'slug' => 'other-price-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $otherVariant = ProductVariant::query()->create([
        'product_id' => $otherProduct->id,
        'sku' => 'OTHER-PRICE',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.prices.update', $product), [
            'variants' => [
                $otherVariant->id => [
                    'gross_price' => '999.00',
                    'currency' => Currency::PLN->value,
                    'vat_rate' => VatRate::VAT_23->value,
                ],
            ],
        ])
        ->assertSessionHasErrors(['variants']);

    expect($otherVariant->refresh())
        ->price_net_amount->toBe(1000)
        ->currency->toBe(Currency::PLN)
        ->vat_rate->toBe(VatRate::VAT_23);
});

it('shows stock status controls on the admin product edit page', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('Status dostępności wariantów')
        ->assertSee('Zastosuj status dostępności do wszystkich wariantów')
        ->assertSee('Zapisz statusy dostępności wariantów')
        ->assertSee(StockStatus::IN_STOCK->label())
        ->assertSee(StockStatus::OUT_OF_STOCK->label())
        ->assertSee(StockStatus::PREORDER->label());
});

it('allows an admin to apply stock status to all product variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.stock-status.update', $product), [
            'stock_status' => StockStatus::OUT_OF_STOCK->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Status dostępności zastosowano do wszystkich wariantów.');

    foreach ($product->variants()->get() as $variant) {
        expect($variant->refresh()->stock_status)->toBe(StockStatus::OUT_OF_STOCK);
    }
});

it('allows an admin to update variant stock statuses separately', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $variants = $product->variants()->orderBy('id')->get();

    $first = $variants[0];
    $second = $variants[1];

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.stock-status.update', $product), [
            'variants' => [
                $first->id => [
                    'stock_status' => StockStatus::OUT_OF_STOCK->value,
                ],
                $second->id => [
                    'stock_status' => StockStatus::PREORDER->value,
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Statusy dostępności wariantów zostały zaktualizowane.');

    expect($first->refresh()->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($second->refresh()->stock_status)->toBe(StockStatus::PREORDER);
});

it('does not allow an admin to update stock status for variants from another product', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $otherProduct = Product::query()->create([
        'name' => 'Other Stock Product',
        'slug' => 'other-stock-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $otherVariant = ProductVariant::query()->create([
        'product_id' => $otherProduct->id,
        'sku' => 'OTHER-STOCK',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.stock-status.update', $product), [
            'variants' => [
                $otherVariant->id => [
                    'stock_status' => StockStatus::OUT_OF_STOCK->value,
                ],
            ],
        ])
        ->assertSessionHasErrors(['variants']);

    expect($otherVariant->refresh()->stock_status)->toBe(StockStatus::IN_STOCK);
});

it('allows an admin to apply package data to all product variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.package-dimensions.update', $product), [
            'package_weight_grams' => 750,
            'package_length_mm' => 310,
            'package_width_mm' => 210,
            'package_height_mm' => 110,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    foreach ($product->variants()->get() as $variant) {
        expect($variant)
            ->package_weight_grams->toBe(750)
            ->package_length_mm->toBe(310)
            ->package_width_mm->toBe(210)
            ->package_height_mm->toBe(110);
    }
});

it('allows an admin to update variant package data separately', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $variants = $product->variants()->orderBy('id')->get();

    $first = $variants[0];
    $second = $variants[1];

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.package-dimensions.update', $product), [
            'variants' => [
                $first->id => [
                    'package_weight_grams' => 100,
                    'package_length_mm' => 110,
                    'package_width_mm' => 120,
                    'package_height_mm' => 130,
                ],
                $second->id => [
                    'package_weight_grams' => 200,
                    'package_length_mm' => 210,
                    'package_width_mm' => 220,
                    'package_height_mm' => 230,
                ],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($first->refresh())
        ->package_weight_grams->toBe(100)
        ->package_length_mm->toBe(110)
        ->package_width_mm->toBe(120)
        ->package_height_mm->toBe(130);

    expect($second->refresh())
        ->package_weight_grams->toBe(200)
        ->package_length_mm->toBe(210)
        ->package_width_mm->toBe(220)
        ->package_height_mm->toBe(230);
});

it('does not allow guests to view admin products', function (): void {
    $this
        ->get(route('admin.products.index'))
        ->assertRedirect();
});

it('does not allow non-admin users to view admin products', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->get(route('admin.products.index'))
        ->assertForbidden();
});

it('filters products by product name', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    Product::query()->create([
        'name' => 'Other Product',
        'slug' => 'other-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index', ['search' => 'Editable']))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertDontSee('Other Product');
});

it('filters products by variant sku', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    Product::query()->create([
        'name' => 'Other Product',
        'slug' => 'other-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index', ['search' => 'EDIT-MISSING']))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertDontSee('Other Product');
});

it('filters products by missing package data', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createProductForAdminProductEditorTest();

    $completeProduct = Product::query()->create([
        'name' => 'Complete Package Product',
        'slug' => 'complete-package-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    ProductVariant::query()->create([
        'product_id' => $completeProduct->id,
        'sku' => 'COMPLETE-ONLY',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
        'package_weight_grams' => 500,
        'package_length_mm' => 300,
        'package_width_mm' => 200,
        'package_height_mm' => 100,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.index', ['missing_package_data' => 1]))
        ->assertOk()
        ->assertSee('Editable Product')
        ->assertSee('brakującą wagą/wymiarami')
        ->assertDontSee('Complete Package Product');
});

it('allows an admin to update the product name', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => 'Updated Product Name',
            'status' => ProductStatus::ACTIVE->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh())
        ->name->toBe('Updated Product Name')
        ->status->toBe(ProductStatus::ACTIVE);
});

it('allows an admin to change a product status from draft to active', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $product->update([
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => $product->name,
            'status' => ProductStatus::ACTIVE->value,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($product->refresh()->status)->toBe(ProductStatus::ACTIVE);
});

it('validates product status when updating product details', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.update', $product), [
            'name' => 'Updated Product Name',
            'status' => 'ACTIVE',
        ])
        ->assertSessionHasErrors(['status']);

    expect($product->refresh()->status)->toBe(ProductStatus::ACTIVE);
});


it('warns admins when priced variants are still draft', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();

    $product->variants()->update([
        'status' => ProductVariantStatus::DRAFT,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('admin.products.edit', $product))
        ->assertOk()
        ->assertSee('Publikacja wariantów')
        ->assertSee('Warianty w szkicu nie są widoczne w sklepie')
        ->assertSee('2 wycenione warianty w szkicu można teraz aktywować')
        ->assertSee('Aktywuj wycenione warianty');
});

it('allows an admin to activate only priced draft variants', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $product = createProductForAdminProductEditorTest();
    $variants = $product->variants()->orderBy('id')->get();

    $pricedDraftVariant = $variants[0];
    $unpricedDraftVariant = $variants[1];

    $pricedDraftVariant->update([
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => 1000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
    ]);

    $unpricedDraftVariant->update([
        'status' => ProductVariantStatus::DRAFT,
        'price_net_amount' => null,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
    ]);

    $this
        ->actingAs($admin)
        ->patch(route('admin.products.variants.activate-priced', $product))
        ->assertRedirect()
        ->assertSessionHas('success', '1 wyceniony wariant został aktywowany.');

    expect($pricedDraftVariant->refresh()->status)->toBe(ProductVariantStatus::ACTIVE)
        ->and($unpricedDraftVariant->refresh()->status)->toBe(ProductVariantStatus::DRAFT);
});
