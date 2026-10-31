<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders reviewed category copy in the page and metadata without writing catalogue fields', function (): void {
    $copy = require resource_path('content/category-copy.pl.php');
    foreach ($copy as $slug => $entry) {
        $category = Category::query()->create([
            'name' => $entry['name'],
            'slug' => $slug,
            'status' => CategoryStatus::ACTIVE,
        ]);
        $response = $this->get(route('categories.show', $slug))->assertOk();
        expect($response->viewData('seoDescription'))->toBe($entry['meta']);
        expect($response->viewData('categoryDescription'))->toBe($entry['description']);
        foreach (explode("\n\n", $entry['description']) as $paragraph) {
            $response->assertSee($paragraph);
        }
        expect($category->fresh()->description)->toBeNull();
        expect($category->fresh()->seo_description)->toBeNull();
    }
});

it('keeps database authored copy and metadata ahead of the editorial fallback', function (): void {
    $category = Category::query()->create([
        'name' => 'Peruki i akcesoria',
        'slug' => 'peruki',
        'status' => CategoryStatus::ACTIVE,
        'description' => "Opis sklepu.\n\nDalsze informacje sklepu.",
        'seo_description' => 'Własny opis meta sklepu.',
    ]);
    $response = $this->get(route('categories.show', $category->slug))->assertOk();
    $response->assertSee('Opis sklepu.')->assertSee('Dalsze informacje sklepu.');
    expect($response->viewData('seoDescription'))->toBe('Własny opis meta sklepu.');

    $category->update(['seo_description' => null]);
    expect($this->get(route('categories.show', $category->slug))->viewData('seoDescription'))
        ->toBe('Opis sklepu. Dalsze informacje sklepu.');
});

it('does not reuse copy after a category is renamed or repeat the full copy on page two', function (): void {
    $category = Category::query()->create([
        'name' => 'Peruki i akcesoria',
        'slug' => 'peruki',
        'status' => CategoryStatus::ACTIVE,
    ]);
    for ($index = 1; $index <= 25; $index++) {
        $product = Product::query()->create([
            'name' => 'Editorial product '.$index,
            'slug' => 'editorial-product-'.$index,
            'status' => ProductStatus::ACTIVE,
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }
    $this->get(route('categories.show', ['category' => $category->slug, 'page' => 2]))
        ->assertOk()->assertDontSee('Toppery, tupety i dopinki mają inne sposoby zakładania');

    $category->update(['name' => 'Inna kategoria']);
    expect($this->get(route('categories.show', $category->slug))->viewData('categoryDescription'))->toBeNull();
});

it('preserves distinguishing product dimensions and authored titles beyond seventy characters', function (): void {
    foreach (['200×45 mm', '200×50 mm'] as $index => $dimension) {
        $name = 'Koło gumowe – poliuretanowe do wózka inwalidzkiego – rozmiar 8″ – '.$dimension;
        $product = Product::query()->create([
            'name' => $name,
            'slug' => 'editorial-wheel-'.$index,
            'status' => ProductStatus::ACTIVE,
        ]);
        $response = $this->get(route('products.show', $product->slug))->assertOk();
        expect($response->viewData('seoTitle'))->toBe($name);
        $response->assertSee($dimension);
        $product->update(['seo_title' => 'Tytuł przygotowany przez sklep – '.$name]);
        expect($this->get(route('products.show', $product->slug))->viewData('seoTitle'))
            ->toBe('Tytuł przygotowany przez sklep – '.$name);
    }
});
