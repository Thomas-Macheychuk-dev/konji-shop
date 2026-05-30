<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

it('renders an XML sitemap with public pages and active products', function (): void {
    Config::set('app.url', 'https://konji-shop.example.test');

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
        ->assertSee(route('products.show', $activeProduct->slug), false)
        ->assertDontSee(route('products.show', $inactiveProduct->slug), false);
});
