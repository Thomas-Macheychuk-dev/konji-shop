<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the redesigned medical storefront shell and homepage sections', function (): void {
    Category::query()->create([
        'name' => 'Ortezy i stabilizatory',
        'slug' => 'ortezy-i-stabilizatory',
        'description' => 'Produkty wspierające stabilizację i codzienny ruch.',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $this
        ->get(route('home'))
        ->assertOk()
        ->assertSee('/images/ortezka-logo-v4.png', false)
        ->assertSee('name="q"', false)
        ->assertSee('Komfort i stabilizacja na każdy dzień')
        ->assertSee('Sprawdzone produkty medyczne')
        ->assertSee('Znajdź produkt dopasowany do potrzeb')
        ->assertSee('Ortezy i stabilizatory')
        ->assertSee('Nie wiesz, jaki produkt wybrać?')
        ->assertSee('Dostawa i płatności');
});

it('searches only active storefront products from the global header', function (): void {
    $activeProduct = Product::query()->create([
        'name' => 'Stabilizator kolana premium',
        'slug' => 'stabilizator-kolana-premium',
        'short_description' => 'Stabilne wsparcie kolana.',
        'status' => ProductStatus::ACTIVE,
    ]);

    Product::query()->create([
        'name' => 'Stabilizator kolana roboczy',
        'slug' => 'stabilizator-kolana-roboczy',
        'status' => ProductStatus::DRAFT,
    ]);

    $this
        ->get(route('home', ['q' => 'kolana']))
        ->assertOk()
        ->assertSee('Wyniki wyszukiwania')
        ->assertSee('Stabilizator kolana premium')
        ->assertSee(route('products.show', $activeProduct->slug), false)
        ->assertDontSee('Stabilizator kolana roboczy')
        ->assertSee('<meta name="robots" content="noindex, follow">', false);
});
