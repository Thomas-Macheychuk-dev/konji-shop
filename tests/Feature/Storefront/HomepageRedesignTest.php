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

it('counts active products from the complete active category subtree on homepage cards', function (): void {
    $root = Category::query()->create([
        'name' => 'Obuwie Scholl',
        'slug' => 'obuwie-scholl',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $child = Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Obuwie damskie',
        'slug' => 'obuwie-damskie',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $archivedChild = Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Archiwalne obuwie',
        'slug' => 'archiwalne-obuwie',
        'status' => CategoryStatus::ARCHIVED,
    ]);

    $descendantProduct = Product::query()->create([
        'name' => 'Produkt potomny',
        'slug' => 'produkt-potomny',
        'status' => ProductStatus::ACTIVE,
    ]);
    $descendantProduct->categories()->attach($child->id, ['is_primary' => true]);

    $multiAssignedProduct = Product::query()->create([
        'name' => 'Produkt przypisany wielokrotnie',
        'slug' => 'produkt-przypisany-wielokrotnie',
        'status' => ProductStatus::ACTIVE,
    ]);
    $multiAssignedProduct->categories()->attach([
        $root->id => ['is_primary' => false],
        $child->id => ['is_primary' => true],
    ]);

    $draftProduct = Product::query()->create([
        'name' => 'Produkt roboczy',
        'slug' => 'produkt-roboczy',
        'status' => ProductStatus::DRAFT,
    ]);
    $draftProduct->categories()->attach($child->id, ['is_primary' => true]);

    $archivedBranchProduct = Product::query()->create([
        'name' => 'Produkt archiwalnej gałęzi',
        'slug' => 'produkt-archiwalnej-galezi',
        'status' => ProductStatus::ACTIVE,
    ]);
    $archivedBranchProduct->categories()->attach($archivedChild->id, ['is_primary' => true]);

    $this
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Obuwie Scholl')
        ->assertSee('2 produktów');
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
