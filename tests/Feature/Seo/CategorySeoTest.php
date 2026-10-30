<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function categorySeoFixture(int $products = 25): Category
{
    $root = Category::query()->create([
        'name' => 'Ortezy i stabilizatory',
        'slug' => 'seo-orthoses',
        'status' => CategoryStatus::ACTIVE,
    ]);
    $category = Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Ortezy kolana',
        'slug' => 'seo-knee',
        'status' => CategoryStatus::ACTIVE,
    ]);

    foreach (range(1, $products) as $number) {
        $product = Product::query()->create([
            'name' => sprintf('SEO Product %02d', $number),
            'slug' => 'seo-product-'.$number,
            'status' => ProductStatus::ACTIVE,
        ]);
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    return $category;
}

it('gives each category page its own canonical URL and page title', function (): void {
    config()->set('app.name', 'Ortezka.pl');
    $category = categorySeoFixture();
    $url = 'https://ortezka.pl/categories/'.$category->slug;

    $this->get($url.'?page=1&utm_source=test')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.$url.'">', false)
        ->assertSee('<title>Ortezy kolana - Ortezka.pl</title>', false);

    $this->get($url.'?page=2&utm_source=test')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.$url.'?page=2">', false)
        ->assertSee('<title>Ortezy kolana — Strona 2 - Ortezka.pl</title>', false)
        ->assertSee('SEO Product 25')
        ->assertDontSee('SEO Product 01');
});

it('keeps cached category pagination on the requesting host without tracking parameters', function (): void {
    config()->set('storefront.cache.enabled', true);
    config()->set('storefront.cache.store', 'array');
    config()->set('storefront.cache.category_pages_ttl', 600);
    $category = categorySeoFixture();

    foreach (['staging.ortezka.pl', 'ortezka.pl', 'staging.ortezka.pl', 'ortezka.pl'] as $host) {
        $url = 'https://'.$host.'/categories/'.$category->slug;
        $response = $this->get($url.'?page=2&utm_source=seo-audit')->assertOk();
        $paginator = $response->viewData('products');

        expect($paginator->url(1))->toBe($url.'?page=1');
        expect($paginator->url(2))->toBe($url.'?page=2');
        expect($response->viewData('canonicalUrl'))->toBe($url.'?page=2');
        expect($response->viewData('structuredData')[0]['itemListElement'][1]['item'])
            ->toBe('https://'.$host.'/categories/seo-orthoses');
    }
});

it('returns a real 404 for a category page beyond the last page', function (): void {
    $category = categorySeoFixture();

    $this->get(route('categories.show', ['category' => $category->slug, 'page' => 3]))
        ->assertNotFound();
});

it('provides category description fallback and breadcrumb structured data', function (): void {
    $category = categorySeoFixture();
    $url = 'https://ortezka.pl/categories/'.$category->slug;
    $response = $this->get($url.'?page=2')->assertOk();

    $response->assertSee('<meta name="description" content="Przeglądaj kategorię Ortezy kolana. Sprawdź produkty, dostępne warianty i szczegóły oferty.">', false);
    preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $response->getContent(), $matches);
    $data = array_map(fn ($json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    $breadcrumbs = collect($data)->firstWhere('@type', 'BreadcrumbList');

    expect(array_column($breadcrumbs['itemListElement'], 'name'))
        ->toBe(['Strona główna', 'Ortezy i stabilizatory', 'Ortezy kolana']);
    expect(array_column($breadcrumbs['itemListElement'], 'position'))->toBe([1, 2, 3]);
    expect($breadcrumbs['itemListElement'][2]['item'])->toBe($url.'?page=2');
});

it('preserves explicit category metadata and excludes hidden breadcrumb ancestors', function (): void {
    $category = categorySeoFixture();
    $category->update(['seo_title' => 'Własny tytuł', 'seo_description' => 'Opis przygotowany dla tej kategorii.']);
    $category->parent->update(['status' => CategoryStatus::ARCHIVED]);
    $response = $this->get(route('categories.show', $category->slug))->assertOk();

    expect($response->viewData('seoTitle'))->toBe('Własny tytuł');
    expect($response->viewData('seoDescription'))->toBe('Opis przygotowany dla tej kategorii.');
    expect(array_column($response->viewData('structuredData')[0]['itemListElement'], 'name'))
        ->toBe(['Strona główna', 'Ortezy kolana']);
});
