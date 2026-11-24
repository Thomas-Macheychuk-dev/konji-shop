<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Services\OldShop\OldShopProductImporter;
use App\Services\OldShop\OldShopScraper;
use App\Services\Products\ProductBrandAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

const OLD_SHOP_URL = 'https://ortezka.pl/lombaskin-wysokosc-21cm-pas-ledzwiowy-thuasne-id-4309';

it('reads product URLs from the old shop listing without the combination', function (): void {
    $scraper = new OldShopScraper;
    $html = '<div class="product-miniature"><a href="https://ortezka.pl/kule-pachowe-thuasne-7094-id-2697#/5-rozmiar-l">Kule</a></div>'
        .'<div class="product-miniature"><a href="/balkonik-zwykly-thuasne-id-2698">Balkonik</a><a href="https://ortezka.pl/cat-124">x</a></div>';

    expect($scraper->productUrlsFromListing($html))->toBe([
        'https://ortezka.pl/kule-pachowe-thuasne-7094-id-2697',
        'https://ortezka.pl/balkonik-zwykly-thuasne-id-2698',
    ])->and($scraper->productUrl('https://example.com/x-id-1'))->toBeNull();
});

it('scrapes a product with the price of every size and colour it sells', function (): void {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => str_contains($request->url(), 'controller=product')
        ? Http::response(oldShopRefresh($request->data()))
        : Http::response(oldShopProductHtml()));

    $page = (new OldShopScraper)->withRequestDelayMilliseconds(0)->scrape(OLD_SHOP_URL);

    expect($page)->toMatchArray([
        'id_product' => 4309,
        'name' => 'LombaSkin wysokość 21cm Pas lędźwiowy Thuasne',
        'reference' => '0871 02',
        'brand' => 'THUASNE',
        'category' => 'Ortezy i stabilizatory',
        'vat_rate' => 8,
        'images' => ['https://ortezka.pl/14858-thickbox_default/lombaskin.jpg'],
    ])
        ->and($page['description_html'])->toContain('<h2>Lomba Skin')
        ->and($page['groups'])->toBe([
            ['id' => '2', 'label' => 'ROZMIAR', 'options' => ['154' => '1', '157' => '2']],
            ['id' => '3', 'label' => 'KOLOR', 'options' => ['3' => 'Czarny', '36' => 'Beżowy']],
        ])
        // Size 2 in black is not sold: the shop answers with size 2 in beige, which is kept once.
        ->and(array_map(fn (array $c): array => [$c['options'], $c['price_gross']], $page['combinations']))->toBe([
            [['ROZMIAR' => '1', 'KOLOR' => 'Czarny'], 295.0],
            [['ROZMIAR' => '1', 'KOLOR' => 'Beżowy'], 295.0],
            [['ROZMIAR' => '2', 'KOLOR' => 'Beżowy'], 310.0],
        ]);
});

it('waits and retries when the old shop refuses requests', function (): void {
    Http::preventStrayRequests();
    $pageRequests = 0;
    Http::fake(function (Request $request) use (&$pageRequests) {
        if (str_contains($request->url(), 'controller=product')) {
            return Http::response(oldShopRefresh($request->data()));
        }

        return ++$pageRequests === 1 ? Http::response('', 403) : Http::response(oldShopProductHtml());
    });
    $waits = [];

    $page = (new OldShopScraper)->withRequestDelayMilliseconds(0)
        ->withBackoff([60], function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        })
        ->scrape(OLD_SHOP_URL);

    expect($waits)->toBe([60])->and($page['combinations'])->toHaveCount(3);
});

it('continues a crawl from the products it saved', function (): void {
    Http::preventStrayRequests();
    $pageRequests = 0;
    Http::fake(function (Request $request) use (&$pageRequests) {
        if (str_contains($request->url(), 'controller=product')) {
            return Http::response(oldShopRefresh($request->data()));
        }

        $pageRequests++;

        return Http::response(oldShopProductHtml());
    });

    $this->artisan('oldshop:crawl', ['--url' => [OLD_SHOP_URL], '--search' => 'THUASNE', '--request-delay-ms' => 0])->assertSuccessful();
    $this->artisan('oldshop:crawl', ['--url' => [OLD_SHOP_URL], '--search' => 'THUASNE', '--request-delay-ms' => 0])
        ->expectsOutputToContain('RESUMED PRODUCTS=1 OTHER_BRAND=0')
        ->expectsOutputToContain('PRODUCTS=1 OTHER_BRAND=0 FAILED=0')
        ->assertSuccessful();

    expect($pageRequests)->toBe(1);
});

it('imports a product into the category of the same name at the old shop prices', function (): void {
    $orthoses = Category::query()->create(['name' => 'Ortezy i stabilizatory', 'slug' => 'ortezy-i-stabilizatory', 'status' => CategoryStatus::ACTIVE]);
    $importer = app(OldShopProductImporter::class);

    $product = $importer->import(oldShopPage(), importImages: false)['product'];

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_source)->toBe('ortezka-old')
        ->and($product->external_id)->toBe('p4309')
        ->and($product->categories->pluck('id')->all())->toBe([$orthoses->id])
        ->and($product->variants)->toHaveCount(2)
        ->and(app(ProductBrandAttribute::class)->hasBrand($product))->toBeTrue()
        ->and($product->attributeValues()->pluck('value')->all())->toContain('Thuasne', 'Tak');

    $variant = $product->variants->firstWhere('sku', '0871-02-24576');

    expect($variant->price_gross_amount)->toBe(29500)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->attributeValues->mapWithKeys(fn ($v) => [$v->attribute->name => $v->value])->all())->toBe(['Rozmiar' => '1', 'Kolor' => 'Beżowy']);

    $product->update(['status' => ProductStatus::ACTIVE]);
    $page = oldShopPage();
    $page['combinations'][0]['price_gross'] = 299;
    $again = $importer->import($page, importImages: false)['product'];

    expect($again->id)->toBe($product->id)
        ->and($again->status)->toBe(ProductStatus::ACTIVE)
        ->and($again->variants->firstWhere('sku', '0871-02-24580')->price_gross_amount)->toBe(29900)
        ->and(Product::query()->count())->toBe(1);
});

it('puts a product with an unknown category under the brand', function (): void {
    $page = oldShopPage(['category' => 'Balkoniki', 'vat_rate' => 23, 'combinations' => [['id_product_attribute' => null, 'options' => [], 'price_gross' => 450]]]);

    $product = app(OldShopProductImporter::class)->import($page, importImages: false)['product'];
    $category = $product->categories->first();

    expect($category->name)->toBe('Balkoniki')
        ->and($category->parent->name)->toBe('Thuasne')
        ->and($product->variants->first()->sku)->toBe('0871-02')
        ->and($product->variants->first()->vat_rate)->toBe(VatRate::VAT_23);

    $home = app(OldShopProductImporter::class)->import(oldShopPage(['id_product' => 4310, 'category' => 'Strona główna']), importImages: false)['product'];

    expect($home->categories->pluck('name')->all())->toBe(['Thuasne']);
});

it('crawls the brand from the search, dry-runs the import, imports and publishes', function (): void {
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'searchiqit')) {
            return Http::response(str_contains($request->url(), 'page=1')
                ? '<div class="product-miniature"><a href="'.OLD_SHOP_URL.'#/154-rozmiar-1">a</a></div><div class="product-miniature"><a href="https://ortezka.pl/inny-id-5000">b</a></div>'
                : '');
        }

        if (str_contains($request->url(), 'controller=product')) {
            return Http::response(oldShopRefresh($request->data()));
        }

        return Http::response(str_contains($request->url(), 'id-5000') ? oldShopProductHtml(brand: 'ORTO', id: 5000) : oldShopProductHtml());
    });

    $this->artisan('oldshop:crawl', ['--search' => 'THUASNE', '--request-delay-ms' => 0])
        ->expectsOutputToContain('PRODUCTS=1 OTHER_BRAND=1 FAILED=0')
        ->expectsOutputToContain('SAVED=scrapers/oldshop/thuasne.json')
        ->assertSuccessful();

    $this->artisan('oldshop:import', ['--from' => 'scrapers/oldshop/thuasne.json', '--dry-run' => true])
        ->expectsOutputToContain('PRODUCTS=1 VARIANTS=3 UNPRICED=0')
        ->expectsOutputToContain('CATEGORY Ortezy i stabilizatory [nowa pod Thuasne]: 1')
        ->expectsOutputToContain('MODE=DRY_RUN')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0);

    $this->artisan('oldshop:import', ['--from' => 'scrapers/oldshop/thuasne.json', '--no-images' => true])
        ->expectsOutputToContain('IMPORTED=1 FAILED=0')
        ->assertSuccessful();

    $this->artisan('oldshop:publish', ['--write' => true])
        ->expectsOutputToContain('PUBLISHED_PRODUCTS=1 PUBLISHED_VARIANTS=3')
        ->assertSuccessful();

    expect(Product::query()->first()->status)->toBe(ProductStatus::ACTIVE);
});

/** @param  array<string, mixed>  $overrides */
function oldShopPage(array $overrides = []): array
{
    return array_merge([
        'source' => 'ortezka-old',
        'source_url' => OLD_SHOP_URL,
        'id_product' => 4309,
        'name' => 'LombaSkin wysokość 21cm Pas lędźwiowy Thuasne',
        'reference' => '0871 02',
        'brand' => 'THUASNE',
        'category' => 'Ortezy i stabilizatory',
        'vat_rate' => 8,
        'price_gross' => 295.0,
        'description_html' => '<p>Pas lędźwiowy podtrzymujący zwarty typu „druga skóra”.</p>',
        'short_description_html' => null,
        'images' => [],
        'groups' => [],
        'combinations' => [
            ['id_product_attribute' => 24580, 'options' => ['ROZMIAR' => '1', 'KOLOR' => 'Czarny'], 'price_gross' => 295.0],
            ['id_product_attribute' => 24576, 'options' => ['ROZMIAR' => '1', 'KOLOR' => 'Beżowy'], 'price_gross' => 295.0],
        ],
    ], $overrides);
}

function oldShopProductHtml(string $brand = 'THUASNE', int $id = 4309): string
{
    $data = htmlspecialchars(json_encode([
        'id_product' => $id,
        'name' => 'LombaSkin wysokość 21cm Pas lędźwiowy Thuasne',
        'reference' => '0871 02',
        'category_name' => 'Ortezy i stabilizatory',
        'rate' => 8,
        'price_amount' => 295,
        'description' => '<h2>Lomba Skin Pas lędźwiowy</h2><p>Opis</p>',
        'description_short' => '',
        'images' => [['bySize' => ['thickbox_default' => ['url' => 'https://ortezka.pl/14858-thickbox_default/lombaskin.jpg']]]],
    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);

    return '<html><head><script>var prestashop = {"static_token":"abc123"};</script>'
        .'<script type="application/ld+json">{"@type":"Product","name":"LombaSkin","brand":{"@type":"Brand","name":"'.$brand.'"}}</script></head><body>'
        .'<div class="product-variants">'
        .'<div class="product-variants-item"><span class="form-control-label">ROZMIAR</span><select name="group[2]"><option value="154">1</option><option value="157">2</option></select></div>'
        .'<div class="product-variants-item"><span class="form-control-label">KOLOR</span><ul><li><label><input type="radio" name="group[3]" value="3" title="Czarny"></label></li><li><label><input type="radio" name="group[3]" value="36" title="Beżowy"></label></li></ul></div>'
        .'</div><div id="product-details" data-product="'.$data.'"></div></body></html>';
}

/** @param  array<string, string>  $data */
function oldShopRefresh(array $data): array
{
    $size = $data['group[2]'] ?? null;
    $colour = $data['group[3]'] ?? null;
    $combinations = ['154-3' => [24580, 295], '154-36' => [24576, 295], '157-36' => [24573, 310]];
    // PrestaShop answers a combination it does not sell with the nearest one it does.
    $key = isset($combinations[$size.'-'.$colour]) ? $size.'-'.$colour : '157-36';
    [$id, $price] = $combinations[$key];
    [$sizeId, $colourId] = explode('-', $key);

    return [
        'id_product_attribute' => $id,
        'product_url' => OLD_SHOP_URL.'#/'.$colourId.'-kolor-x/'.$sizeId.'-rozmiar-x',
        'product_prices' => '<span class="current-price"><span class="product-price current-price-value" content="'.$price.'"> '.$price.',00 zł </span></span>',
    ];
}
