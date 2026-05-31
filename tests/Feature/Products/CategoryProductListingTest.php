<?php

use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCategoryForProductListingTest(
    string $name = 'Odzież medyczna',
    string $slug = 'odziez-medyczna',
    CategoryStatus $status = CategoryStatus::ACTIVE,
    ?Category $parent = null,
): Category {
    return Category::query()->create([
        'parent_id' => $parent?->id,
        'name' => $name,
        'slug' => $slug,
        'description' => $name.' category description.',
        'status' => $status,
    ]);
}

function createProductForProductListingTest(
    Category $category,
    string $name,
    string $slug,
    ProductStatus $status = ProductStatus::ACTIVE,
): Product {
    $product = Product::query()->create([
        'name' => $name,
        'slug' => $slug,
        'short_description' => $name.' short description.',
        'status' => $status,
    ]);

    $product->categories()->attach($category->id, [
        'is_primary' => true,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => strtoupper(str_replace('-', '_', $slug)),
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => VatRate::VAT_23->netFromGross(12300),
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    return $product;
}

it('shows active top-level categories in the storefront sidebar', function (): void {
    $activeCategory = createCategoryForProductListingTest();
    createCategoryForProductListingTest('Archived category', 'archived-category', CategoryStatus::ARCHIVED);
    createCategoryForProductListingTest('Child category', 'child-category', CategoryStatus::ACTIVE, $activeCategory);

    $this
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Categories')
        ->assertSee('Odzież medyczna')
        ->assertSee(route('categories.show', $activeCategory->slug), false)
        ->assertDontSee('Archived category')
        ->assertDontSee('Child category');
});

it('shows active products from a category with product links and prices', function (): void {
    $category = createCategoryForProductListingTest();
    $activeProduct = createProductForProductListingTest($category, 'Bluza medyczna', 'bluza-medyczna');
    createProductForProductListingTest($category, 'Draft product', 'draft-product', ProductStatus::DRAFT);

    $this
        ->get(route('categories.show', $category->slug))
        ->assertOk()
        ->assertSee('Odzież medyczna')
        ->assertSee('1 product found.')
        ->assertSee('Bluza medyczna')
        ->assertSee(route('products.show', $activeProduct->slug), false)
        ->assertSee('123,00 PLN')
        ->assertDontSee('Draft product');
});

it('includes active products assigned to active direct child categories', function (): void {
    $parentCategory = createCategoryForProductListingTest();
    $childCategory = createCategoryForProductListingTest('Bluzy medyczne', 'bluzy-medyczne', CategoryStatus::ACTIVE, $parentCategory);
    $archivedChildCategory = createCategoryForProductListingTest('Archived child', 'archived-child', CategoryStatus::ARCHIVED, $parentCategory);

    createProductForProductListingTest($childCategory, 'Child category product', 'child-category-product');
    createProductForProductListingTest($archivedChildCategory, 'Archived child product', 'archived-child-product');

    $this
        ->get(route('categories.show', $parentCategory->slug))
        ->assertOk()
        ->assertSee('Child category product')
        ->assertDontSee('Archived child product');
});

it('paginates category products at twenty four products per page', function (): void {
    $category = createCategoryForProductListingTest();

    foreach (range(1, 25) as $number) {
        $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

        createProductForProductListingTest(
            $category,
            'Category Product '.$suffix,
            'category-product-'.$suffix,
        );
    }

    $this
        ->get(route('categories.show', $category->slug))
        ->assertOk()
        ->assertSee('25 products found.')
        ->assertSee('Category Product 01')
        ->assertSee('Category Product 24')
        ->assertDontSee('Category Product 25');

    $this
        ->get(route('categories.show', [$category->slug, 'page' => 2]))
        ->assertOk()
        ->assertSee('Category Product 25')
        ->assertDontSee('Category Product 01');
});

it('does not show archived categories publicly', function (): void {
    $category = createCategoryForProductListingTest(
        'Archived medical clothing',
        'archived-medical-clothing',
        CategoryStatus::ARCHIVED,
    );

    $this
        ->get(route('categories.show', $category->slug))
        ->assertNotFound();
});
