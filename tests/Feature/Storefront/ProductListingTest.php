<?php

use App\Enums\AttributeDisplayType;
use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function listingCategory(string $slug = 'ortezy-kolana'): Category
{
    return Category::query()->firstOrCreate(['slug' => $slug], ['name' => 'Ortezy kolana', 'status' => CategoryStatus::ACTIVE]);
}

function listingProduct(string $name, ?int $gross = 10000, array $options = []): Product
{
    static $sequence = 0;
    $sequence++;

    $product = Product::query()->create([
        'name' => $name,
        'slug' => str($name)->slug()->append('-'.$sequence)->toString(),
        'description' => $options['description'] ?? null,
        'status' => ProductStatus::ACTIVE,
        'published_at' => $options['published_at'] ?? now()->subDays(100 - $sequence),
        'external_parent_sku' => $options['parent_sku'] ?? null,
    ]);

    $product->categories()->attach(($options['category'] ?? listingCategory())->id, ['is_primary' => true]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => $options['sku'] ?? 'SKU-'.$sequence,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => $gross === null ? null : VatRate::VAT_8->netFromGross($gross),
        'price_gross_amount' => $gross,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => $options['stock'] ?? StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    if (isset($options['brand'])) {
        $attribute = Attribute::query()->firstOrCreate(['slug' => 'producent'], ['name' => 'Producent', 'display_type' => AttributeDisplayType::SELECT]);
        $value = AttributeValue::query()->firstOrCreate(
            ['attribute_id' => $attribute->id, 'slug' => str($options['brand'])->slug()->toString()],
            ['value' => $options['brand'], 'sort_order' => 0],
        );
        $product->attributeValues()->attach($value->id);
    }

    return $product;
}

/** @return list<string> */
function listedNames($response, string $key): array
{
    return collect($response->viewData($key)->items())->pluck('name')->all();
}

it('pages through every search result with the real total', function (): void {
    foreach (range(1, 30) as $number) {
        listingProduct('Orteza kolana '.$number);
    }

    $this->get('/?q=orteza')
        ->assertOk()
        ->assertSee('Znaleziono 30 produktów.')
        ->assertSee('page=2', false);

    expect($this->get('/?q=orteza&page=2')->viewData('searchResults')->count())->toBe(6);
});

it('matches every word in any order, in descriptions and SKUs', function (): void {
    listingProduct('Stabilizator na kolano', options: ['description' => '<p>Neoprenowy, rozmiar uniwersalny</p>']);
    listingProduct('Opaska na łokieć', options: ['sku' => 'AM-OSK-Z']);
    listingProduct('Stabilizator nadgarstka');

    expect(listedNames($this->get('/?q=kolano stabilizator'), 'searchResults'))->toBe(['Stabilizator na kolano'])
        ->and(listedNames($this->get('/?q=stabilizator neoprenowy'), 'searchResults'))->toBe(['Stabilizator na kolano'])
        ->and(listedNames($this->get('/?q=am-osk-z'), 'searchResults'))->toBe(['Opaska na łokieć']);
});

it('ranks name matches before description matches', function (): void {
    listingProduct('Pas lędźwiowy', options: ['description' => 'Zakładany pod ortezę lub jako samodzielna orteza']);
    listingProduct('Orteza kolana');

    expect(listedNames($this->get('/?q=orteza'), 'searchResults'))->toBe(['Orteza kolana', 'Pas lędźwiowy']);
});

it('suggests the closest product words when nothing matches', function (string $typed, string $suggested): void {
    listingProduct('Orteza kręgosłupa lędźwiowego');
    listingProduct('Orteza kolana');

    $this->get('/?q='.urlencode($typed))
        ->assertOk()
        ->assertSee('Nie znaleźliśmy takiego produktu')
        ->assertSee('Czy chodziło Ci o:')
        ->assertSee($suggested);
})->with([
    'typo' => ['ortze', 'orteza'],
    'no diacritics' => ['kregoslupa', 'kręgosłupa'],
    'two words' => ['ortza kolna', 'orteza kolana'],
]);

it('sorts a category by price with unpriced products last', function (): void {
    listingProduct('Średnia', 20000);
    listingProduct('Bez ceny', null);
    listingProduct('Tania', 5000);
    listingProduct('Droga', 90000);

    expect(listedNames($this->get('/categories/ortezy-kolana?sort=cena-rosnaco'), 'products'))->toBe(['Tania', 'Średnia', 'Droga', 'Bez ceny'])
        ->and(listedNames($this->get('/categories/ortezy-kolana?sort=cena-malejaco'), 'products'))->toBe(['Droga', 'Średnia', 'Tania', 'Bez ceny']);
});

it('puts priced and newest products first by default', function (): void {
    listingProduct('10x Opaska', 2000, ['published_at' => now()->subYear()]);
    listingProduct('Bez ceny nowa', null, ['published_at' => now()]);
    listingProduct('Orteza nowa', 30000, ['published_at' => now()->subDay()]);

    expect(listedNames($this->get('/categories/ortezy-kolana'), 'products'))->toBe(['Orteza nowa', '10x Opaska', 'Bez ceny nowa']);
});

it('filters a category by price, brand and availability', function (): void {
    listingProduct('Reh4Mat tania', 5000, ['brand' => 'Reh4Mat']);
    listingProduct('Reh4Mat droga', 50000, ['brand' => 'Reh4Mat']);
    listingProduct('Zamst', 30000, ['brand' => 'Zamst']);
    listingProduct('Brak w magazynie', 30000, ['stock' => StockStatus::OUT_OF_STOCK]);

    $sorted = fn (string $query) => collect(listedNames($this->get('/categories/ortezy-kolana?'.$query), 'products'))->sort()->values()->all();

    expect($sorted('cena_od=100&cena_do=400'))->toBe(['Brak w magazynie', 'Zamst'])
        ->and($sorted('marka[]=reh4mat'))->toBe(['Reh4Mat droga', 'Reh4Mat tania'])
        ->and($sorted('dostepne=1&cena_od=100'))->toBe(['Reh4Mat droga', 'Zamst'])
        ->and($sorted('cena_od=400&cena_do=100'))->toBe(['Brak w magazynie', 'Zamst']);

    $this->get('/categories/ortezy-kolana')
        ->assertSee('Producent')
        ->assertSee('value="reh4mat"', false)
        ->assertSee('value="zamst"', false);
});

it('keeps filters in pagination links and out of the search index', function (): void {
    foreach (range(1, 30) as $number) {
        listingProduct('Orteza '.$number, 10000 + $number);
    }

    $this->get('/categories/ortezy-kolana?sort=cena-rosnaco&dostepne=1')
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('sort=cena-rosnaco', false)
        ->assertSee('dostepne=1', false);

    $this->get('/categories/ortezy-kolana')->assertDontSee('noindex', false);
});

it('shows codes on cards that share a name', function (): void {
    listingProduct('2x Stabilizator Orteza', 5120, ['parent_sku' => '1-02-G']);
    listingProduct('2x Stabilizator Orteza', 4500, ['parent_sku' => '1-03-G']);
    listingProduct('Orteza kolana', 9000, ['parent_sku' => 'OKD-15']);

    $this->get('/categories/ortezy-kolana')
        ->assertSee('Kod: 1-02-G')
        ->assertSee('Kod: 1-03-G')
        ->assertDontSee('Kod: OKD-15');
});
