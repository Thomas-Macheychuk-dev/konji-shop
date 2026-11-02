<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function titleFixtureCategory(string $name, string $slug, ?Category $parent = null): Category
{
    return Category::query()->create([
        'name' => $name,
        'slug' => $slug,
        'parent_id' => $parent?->id,
        'status' => CategoryStatus::ACTIVE,
    ]);
}

function titleFixtureProduct(array $attributes, ?Category $category = null): Product
{
    $product = Product::query()->forceCreate($attributes + ['status' => ProductStatus::ACTIVE]);
    if ($category !== null) {
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    return $product;
}

it('keeps the product name as the only h1 when the imported description has its own', function (): void {
    $product = titleFixtureProduct([
        'name' => 'Pas brzuszny neoprenowy',
        'slug' => 'pas-brzuszny-neoprenowy',
        'description' => '<h1 class="title">PAS BRZUSZNY- NEOPREN</h1><p>Opis produktu.</p>',
    ]);

    $html = $this->get(route('products.show', $product->slug))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<h2 class="title">PAS BRZUSZNY- NEOPREN</h2>');
});

it('adds the parent to a repeated category name in the title only', function (): void {
    $wedges = titleFixtureCategory('Kliny rehabilitacyjne', 'kliny');
    $collars = titleFixtureCategory('Kołnierze ortopedyczne', 'kolnierze');
    $repeated = titleFixtureCategory('Nowości', 'kliny-nowosci', $wedges);
    titleFixtureCategory('Nowości', 'kolnierze-nowosci', $collars);

    expect($this->get(route('categories.show', $repeated->slug))->viewData('seoTitle'))
        ->toBe('Nowości – Kliny rehabilitacyjne')
        ->and($this->get(route('categories.show', $wedges->slug))->viewData('seoTitle'))
        ->toBe('Kliny rehabilitacyjne');

    $repeated->update(['seo_title' => 'Nowe kliny']);
    expect($this->get(route('categories.show', $repeated->slug))->viewData('seoTitle'))->toBe('Nowe kliny');
});

it('says what a Vermeiren model is when its generated title is only the model name', function (): void {
    $mobility = titleFixtureCategory('Wózki manualne', 'wozki-manualne');
    $special = titleFixtureCategory('Specjalne', 'wozki-specjalne', $mobility);
    $canes = titleFixtureCategory('Laski', 'laski');

    $normandie = titleFixtureProduct(['name' => 'Normandie Plus', 'slug' => 'normandie-plus', 'external_source' => 'vermeiren', 'seo_title' => 'Normandie Plus | Vermeiren'], $special);
    $monica = titleFixtureProduct(['name' => 'MONICA', 'slug' => 'monica', 'external_source' => 'vermeiren', 'seo_title' => 'MONICA | Vermeiren'], $canes);
    $named = titleFixtureProduct(['name' => 'Laska MONICA', 'slug' => 'laska-monica', 'external_source' => 'vermeiren', 'seo_title' => 'Laska MONICA | Vermeiren'], $canes);
    $edited = titleFixtureProduct(['name' => 'BRITNEY', 'slug' => 'britney', 'external_source' => 'vermeiren', 'seo_title' => 'Laska BRITNEY z regulacją | Vermeiren'], $canes);

    $title = fn (Product $product) => $this->get(route('products.show', $product->slug))->viewData('seoTitle');

    expect($title($normandie))->toBe('Normandie Plus – Wózki manualne | Vermeiren')
        ->and($title($monica))->toBe('MONICA – Laski | Vermeiren')
        ->and($title($named))->toBe('Laska MONICA | Vermeiren')
        ->and($title($edited))->toBe('Laska BRITNEY z regulacją | Vermeiren');
});
