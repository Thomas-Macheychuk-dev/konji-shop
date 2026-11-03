<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('renders an XML sitemap with public pages, active categories and active products', function (): void {
    Config::set('app.url', 'https://konji-shop.example.test');

    $activeCategory = Category::query()->create([
        'name' => 'Sitemap Active Category',
        'slug' => 'sitemap-active-category',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $inactiveCategory = Category::query()->create([
        'name' => 'Sitemap Archived Category',
        'slug' => 'sitemap-archived-category',
        'status' => CategoryStatus::ARCHIVED,
    ]);

    $activeProduct = Product::query()->create([
        'name' => 'Sitemap Active Product',
        'slug' => 'sitemap-active-product',
        'status' => ProductStatus::ACTIVE,
    ]);

    $inactiveProduct = Product::query()->create([
        'name' => 'Sitemap Inactive Product',
        'slug' => 'sitemap-inactive-product',
        'status' => ProductStatus::DRAFT,
    ]);
    $activeProduct->categories()->attach($activeCategory->id, ['is_primary' => true]);

    $this
        ->get(route('sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml')
        ->assertSee('<?xml version="1.0" encoding="UTF-8"?>', false)
        ->assertSee('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', false)
        ->assertSee(route('home'), false)
        ->assertSee(route('legal.terms'), false)
        ->assertSee(route('legal.privacy'), false)
        ->assertSee(route('legal.returns'), false)
        ->assertSee(route('categories.show', $activeCategory->slug), false)
        ->assertSee(route('products.show', $activeProduct->slug), false)
        ->assertDontSee(route('categories.show', $inactiveCategory->slug), false)
        ->assertDontSee(route('products.show', $inactiveProduct->slug), false);
});

it('lists only categories whose active subtree holds an active product', function (): void {
    $parent = Category::query()->create(['name' => 'Parent', 'slug' => 'sitemap-parent', 'status' => CategoryStatus::ACTIVE]);
    $child = Category::query()->create(['parent_id' => $parent->id, 'name' => 'Child', 'slug' => 'sitemap-child', 'status' => CategoryStatus::ACTIVE]);
    $empty = Category::query()->create(['parent_id' => $parent->id, 'name' => 'Empty', 'slug' => 'sitemap-empty', 'status' => CategoryStatus::ACTIVE]);
    $draftOnly = Category::query()->create(['name' => 'Draft only', 'slug' => 'sitemap-draft-only', 'status' => CategoryStatus::ACTIVE]);
    $archived = Category::query()->create(['name' => 'Archived', 'slug' => 'sitemap-archived-middle', 'status' => CategoryStatus::ARCHIVED]);
    $orphan = Category::query()->create(['parent_id' => $archived->id, 'name' => 'Under archived', 'slug' => 'sitemap-under-archived', 'status' => CategoryStatus::ACTIVE]);
    $root = Category::query()->create(['name' => 'Root', 'slug' => 'sitemap-root', 'status' => CategoryStatus::ACTIVE]);
    $archived->update(['parent_id' => $root->id]);

    Product::query()->create(['name' => 'Active', 'slug' => 'sitemap-child-product', 'status' => ProductStatus::ACTIVE])
        ->categories()->attach($child->id, ['is_primary' => true]);
    Product::query()->create(['name' => 'Draft', 'slug' => 'sitemap-draft-product', 'status' => ProductStatus::DRAFT])
        ->categories()->attach($draftOnly->id, ['is_primary' => true]);
    Product::query()->create(['name' => 'Under archived', 'slug' => 'sitemap-orphan-product', 'status' => ProductStatus::ACTIVE])
        ->categories()->attach($orphan->id, ['is_primary' => true]);

    $this->get(route('sitemap'))
        ->assertOk()
        ->assertSee(route('categories.show', $parent->slug), false)
        ->assertSee(route('categories.show', $child->slug), false)
        ->assertSee(route('categories.show', $orphan->slug), false)
        ->assertDontSee(route('categories.show', $empty->slug), false)
        ->assertDontSee(route('categories.show', $draftOnly->slug), false)
        // The category page does not count products beneath an archived child, so neither does the sitemap.
        ->assertDontSee(route('categories.show', $root->slug), false);
});

it('includes the NFZ page and uses recorded modification dates only', function (): void {
    $product = Product::query()->create([
        'name' => 'Dated product',
        'slug' => 'dated-product',
        'status' => ProductStatus::ACTIVE,
    ]);
    $updatedAt = $product->updated_at->toAtomString();
    $this->travel(1)->days();
    $response = $this->get(route('sitemap'))->assertOk();
    $xml = simplexml_load_string($response->getContent());
    $urls = [];
    foreach ($xml->url as $entry) {
        $urls[(string) $entry->loc] = $entry;
    }

    expect($urls)->toHaveKey(route('legal.nfz'));
    expect($urls[route('home')]->lastmod->count())->toBe(0);
    expect($urls[route('legal.contact')]->lastmod->count())->toBe(0);
    expect((string) $urls[route('products.show', $product->slug)]->lastmod)->toBe($updatedAt);
});
