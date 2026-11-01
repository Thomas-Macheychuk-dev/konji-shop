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

it('shows only top-level categories in the sidebar outside a category branch', function (): void {
    $activeCategory = createCategoryForProductListingTest();
    $childCategory = createCategoryForProductListingTest(
        'Bluzy medyczne',
        'bluzy-medyczne',
        CategoryStatus::ACTIVE,
        $activeCategory,
    );

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)
        ->toContain('data-category-link="'.$activeCategory->id.'"')
        ->not->toContain('data-category-toggle=')
        ->not->toContain('data-category-item="'.$childCategory->id.'"');
});

it('shows the nested active branch of the current category in the storefront sidebar', function (): void {
    $activeCategory = createCategoryForProductListingTest();
    $childCategory = createCategoryForProductListingTest(
        'Bluzy medyczne',
        'bluzy-medyczne',
        CategoryStatus::ACTIVE,
        $activeCategory,
    );
    $grandchildCategory = createCategoryForProductListingTest(
        'Bluzy damskie',
        'bluzy-damskie',
        CategoryStatus::ACTIVE,
        $childCategory,
    );

    createCategoryForProductListingTest('Archived category', 'archived-category', CategoryStatus::ARCHIVED);
    createCategoryForProductListingTest(
        'Archived child',
        'archived-child',
        CategoryStatus::ARCHIVED,
        $activeCategory,
    );

    $this
        ->get(route('categories.show', $grandchildCategory->slug))
        ->assertOk()
        ->assertSee('data-category-sidebar', false)
        ->assertSee('Kategorie produktów')
        ->assertSee('Odzież medyczna')
        ->assertSee('Bluzy medyczne')
        ->assertSee('Bluzy damskie')
        ->assertSee('data-category-toggle="'.$activeCategory->id.'"', false)
        ->assertSee('data-category-toggle="'.$childCategory->id.'"', false)
        ->assertSee('data-category-link="'.$grandchildCategory->id.'"', false)
        ->assertSee(route('categories.show', $activeCategory->slug), false)
        ->assertSee(route('categories.show', $grandchildCategory->slug), false)
        ->assertDontSee('Archived category')
        ->assertDontSee('Archived child');
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

it('includes active products assigned to active descendants at any depth', function (): void {
    $parentCategory = createCategoryForProductListingTest();
    $childCategory = createCategoryForProductListingTest(
        'Bluzy medyczne',
        'bluzy-medyczne',
        CategoryStatus::ACTIVE,
        $parentCategory,
    );
    $grandchildCategory = createCategoryForProductListingTest(
        'Bluzy damskie',
        'bluzy-damskie',
        CategoryStatus::ACTIVE,
        $childCategory,
    );
    $archivedChildCategory = createCategoryForProductListingTest(
        'Archived child',
        'archived-child',
        CategoryStatus::ARCHIVED,
        $parentCategory,
    );

    createProductForProductListingTest($grandchildCategory, 'Deep descendant product', 'deep-descendant-product');
    createProductForProductListingTest($archivedChildCategory, 'Archived child product', 'archived-child-product');

    $this
        ->get(route('categories.show', $parentCategory->slug))
        ->assertOk()
        ->assertSee('Deep descendant product')
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

it('opens the active category branch in the sidebar', function (): void {
    $parentCategory = createCategoryForProductListingTest();
    $childCategory = createCategoryForProductListingTest(
        'Bluzy medyczne',
        'bluzy-medyczne',
        CategoryStatus::ACTIVE,
        $parentCategory,
    );
    $grandchildCategory = createCategoryForProductListingTest(
        'Bluzy damskie',
        'bluzy-damskie',
        CategoryStatus::ACTIVE,
        $childCategory,
    );

    $response = $this
        ->get(route('categories.show', $grandchildCategory->slug))
        ->assertOk();

    expect($response->getContent())
        ->toMatch('/data-category-item="'.$parentCategory->id.'".*?data-category-open-by-default="true"/s')
        ->toMatch('/data-category-item="'.$childCategory->id.'".*?data-category-open-by-default="true"/s');
});



it('renders the complete category breadcrumb in root to leaf order', function (): void {
    $root = createCategoryForProductListingTest('Root department', 'root-department');
    $parent = createCategoryForProductListingTest('Parent category', 'parent-category', parent: $root);
    $leaf = createCategoryForProductListingTest('Leaf category', 'leaf-category', parent: $parent);

    $response = $this->get(route('categories.show', $leaf->slug))->assertOk();

    $response->assertViewHas('breadcrumbCategories', fn (array $categories): bool => array_map(fn (Category $category): int => $category->id, $categories) === [$root->id, $parent->id]
    );

    preg_match('/<nav[^>]*aria-label="Okruszki"[^>]*>(.*?)<\/nav>/s', $response->getContent(), $match);

    expect(preg_replace('/\s+/', ' ', trim(strip_tags($match[1]))))
        ->toBe('Strona główna / Root department / Parent category / Leaf category');
});

it('does not expose inactive or deleted ancestors in breadcrumbs', function (): void {
    foreach (['archived', 'deleted'] as $state) {
        $root = createCategoryForProductListingTest('Hidden '.$state, 'hidden-'.$state);
        $leaf = createCategoryForProductListingTest('Visible '.$state, 'visible-'.$state, parent: $root);

        if ($state === 'deleted') {
            $root->delete();
        } else {
            $root->update(['status' => CategoryStatus::ARCHIVED]);
        }

        $this->get(route('categories.show', $leaf->slug))
            ->assertOk()
            ->assertViewHas('breadcrumbCategories', []);
    }
});

it('terminates breadcrumb traversal when category ancestors form a cycle', function (): void {
    $first = createCategoryForProductListingTest('Cycle first', 'cycle-first');
    $second = createCategoryForProductListingTest('Cycle second', 'cycle-second', parent: $first);
    $first->update(['parent_id' => $second->id]);

    $controller = app(\App\Http\Controllers\CategoryProductIndexController::class);
    $method = new ReflectionMethod($controller, 'breadcrumbCategories');

    expect($method->invoke($controller, $second))->toBe([]);
});

it('redirects an archived duplicate category to its active canonical category', function (): void {
    $canonical = createCategoryForProductListingTest('Canonical blouse category', 'bluzy-medyczne-damskie');
    $duplicate = createCategoryForProductListingTest('Old blouse category', 'bluzy-damskie', CategoryStatus::ARCHIVED);

    $this->get(route('categories.show', $duplicate->slug))
        ->assertStatus(301)
        ->assertRedirect(route('categories.show', $canonical->slug));

    $canonical->delete();

    $this->get(route('categories.show', $duplicate->slug))->assertNotFound();
});
