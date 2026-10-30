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
