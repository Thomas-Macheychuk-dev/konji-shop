<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders persisted reviewed title corrections from the database', function (): void {
    $copy = require resource_path('content/product-title-copy.pl.php');
    foreach ($copy as $id => $entry) {
        $attributes = $entry;
        unset($attributes['title']);
        $attributes['seo_title'] = $entry['title'];
        $attributes['id'] = $id;
        $attributes['status'] = ProductStatus::ACTIVE;
        $product = new Product;
        $product->forceFill($attributes)->save();
        $response = $this->get(route('products.show', $product->slug))->assertOk();
        expect($response->viewData('seoTitle'))->toBe($entry['title']);
        expect($response->viewData('openGraphTitle'))->toBe($entry['title']);
        expect($product->fresh()->seo_title)->toBe($entry['title']);
        expect($product->fresh()->name)->toBe($entry['name']);
        $response->assertSee('<h1', false)->assertSee($entry['name']);
    }
});

it('uses later database edits as the source of product metadata', function (): void {
    config()->set('storefront.cache.enabled', true);
    config()->set('storefront.cache.store', 'array');
    $copy = require resource_path('content/product-title-copy.pl.php');
    $id = array_key_first($copy);
    $entry = $copy[$id];
    $attributes = $entry;
    unset($attributes['title']);
    $attributes['seo_title'] = $entry['title'];
    $product = new Product;
    $product->forceFill($attributes + ['id' => $id, 'status' => ProductStatus::ACTIVE])->save();
    expect($this->get(route('products.show', $product->slug))->viewData('seoTitle'))->toBe($entry['title']);
    $product->update(['seo_title' => 'Tytuł przygotowany w panelu sklepu']);
    expect($this->get(route('products.show', $product->slug))->viewData('seoTitle'))->toBe('Tytuł przygotowany w panelu sklepu');
    $product->update(['seo_title' => $entry['seo_title'], 'name' => 'Inny model']);
    expect($this->get(route('products.show', $product->slug))->viewData('seoTitle'))->toBe($entry['seo_title']);
});

it('does not assign the default variant colour or guess a conflicting source colour', function (): void {
    // This source URL says white while its description says black. It is held.
    $product = new Product;
    $product->forceFill([
        'id' => 217,
        'name' => 'BOA VISTA WIDE MED Health and Fashion Shoes Italia S',
        'slug' => 'boa-vista-wide-med-white',
        'external_source' => 'mobilex',
        'external_id' => 'held-source-id',
        'seo_title' => 'BOA VISTA WIDE MED - GaleriaZdrowia.pl',
        'status' => ProductStatus::ACTIVE,
    ])->save();
    $response = $this->get(route('products.show', $product->slug))->assertOk();
    expect($response->viewData('seoTitle'))->toBe($product->seo_title);
    expect($response->viewData('seoTitle'))->not->toContain('kolor biały');
});
