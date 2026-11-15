<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Sanipur\SanipurProductGrouper;
use App\Services\Sanipur\SanipurProductImporter;
use App\Services\Sanipur\SanipurProductScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function sanipurProductHtml(array $overrides = []): string
{
    $p = array_merge([
        'id' => '8668',
        'name' => 'SANIPUR Prześcieradło nieprzemakalne Jersey 180x200',
        'sku' => 'TS0106_0609',
        'price' => 'Cena regularna: 143,94 zł',
        'crumbs' => ['Medyczne', 'Prześcieradła nieprzemakalne'],
        'medical' => true,
        'image' => 'https://sanipur.eu/images/thumbs/0033739_jersey.jpeg',
    ], $overrides);

    $crumbs = implode('', array_map(
        fn (string $crumb) => '<li itemprop="itemListElement"><a href="/pl/x" itemprop="item"><span itemprop="name">'.$crumb.'</span></a></li>',
        $p['crumbs'],
    ));
    $medical = $p['medical'] ? '<li>Wyrób medyczny: produkt posiada status wyrobu medycznego.</li>' : '';

    return <<<HTML
<html><body>
<div class="breadcrumb"><ul><li><a href="/pl"><span>Strona główna</span></a></li>{$crumbs}
<li itemprop="itemListElement"><strong class="current-item" itemprop="name">{$p['name']}</strong></li></ul></div>
<form method="post" id="product-details-form">
  <div class="picture-gallery"><a data-full-image-url="{$p['image']}"><img src="{$p['image']}"></a>
    <a class="thumb-item" data-full-image-url="https://cdn.example.com/foreign.jpeg"></a></div>
  <div class="product-name"><h1> {$p['name']} </h1></div>
  <div class="short-description">Nieprzemakalne prześcieradło z gumą.</div>
  <div class="manufacturers"><span class="label">Producent:</span><span class="value"><a href="/pl/sanipur">Sanipur</a></span></div>
  <div class="sku"><span class="value" id="sku-{$p['id']}">{$p['sku']}</span></div>
  <div class="full-description"><p>Opis <script>alert(1)</script>produktu.</p><ul>{$medical}</ul></div>
  <div class="product-price"><span id="price-value-{$p['id']}" class="price-value-{$p['id']}">{$p['price']}</span></div>
  <button class="add-to-cart-button" data-productid="{$p['id']}">Do koszyka</button>
</form>
<div id="quickTab-description"><div class="full-description"><p>Opis <script>alert(1)</script>produktu.</p><ul>{$medical}</ul></div></div>
<div class="product-specs-box"><table><tr><td class="spec-name">Skład</td><td class="spec-value">100% bawełna</td></tr>
<tr><td class="spec-name">Rozmiar</td><td class="spec-value">180x200 cm</td></tr></table></div>
</body></html>
HTML;
}

function sanipurPage(array $overrides = []): array
{
    return array_merge([
        'source' => 'sanipur',
        'source_url' => 'https://sanipur.eu/pl/p-'.($overrides['external_product_id'] ?? '1'),
        'external_product_id' => '1',
        'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, turkusowe',
        'sku' => 'TM0131_31A',
        'brand' => 'Matex',
        'breadcrumbs' => ['Dla Domu', 'Prześcieradła', 'Prześcieradła według rozmiaru', 'Prześcieradła 90x200 cm'],
        'short_description' => 'Prześcieradło jersey.',
        'description_html' => '<p>Opis.</p>',
        'price_gross_amount' => 56.48,
        'is_medical_device' => false,
        'attributes' => [['label' => 'Kolor', 'value' => 'turkusowy'], ['label' => 'Skład', 'value' => '100% bawełna']],
        'images' => [['url' => 'https://sanipur.eu/images/thumbs/1.jpeg', 'alt' => '']],
    ], $overrides);
}

function writeSanipurFixture(array $pages, string $path = 'scrapers/sanipur/test.json'): void
{
    Storage::disk('local')->put($path, json_encode(['products' => $pages], JSON_UNESCAPED_UNICODE));
}

it('parses a sanipur.eu product page and ignores non-product pages', function (): void {
    $scraper = new SanipurProductScraper;
    $product = $scraper->parse(sanipurProductHtml(), 'https://sanipur.eu/pl/przescieradlo-jersey-180x200');

    expect($product)->not->toBeNull()
        ->and($product['external_product_id'])->toBe('8668')
        ->and($product['name'])->toBe('SANIPUR Prześcieradło nieprzemakalne Jersey 180x200')
        ->and($product['sku'])->toBe('TS0106_0609')
        ->and($product['brand'])->toBe('Sanipur')
        ->and($product['price_gross_amount'])->toBe(143.94)
        ->and($product['breadcrumbs'])->toBe(['Medyczne', 'Prześcieradła nieprzemakalne'])
        ->and($product['is_medical_device'])->toBeTrue()
        ->and($product['attributes'])->toHaveCount(2)
        ->and(array_column($product['images'], 'url'))->toBe(['https://sanipur.eu/images/thumbs/0033739_jersey.jpeg']);

    expect($scraper->parse('<html><body><div class="product-grid"></div></body></html>', 'https://sanipur.eu/pl/kategoria'))->toBeNull()
        ->and($scraper->parse(sanipurProductHtml(['price' => 'Cena regularna: 0,00 zł']), 'https://sanipur.eu/pl/x')['price_gross_amount'])->toBeNull();
});

it('splits sizes and colours out of sanipur.eu product names', function (): void {
    $grouper = new SanipurProductGrouper;

    expect($grouper->parseName('Matex Prześcieradło jersey z gumą 90/100x190/200, ciemno niebieskie'))
        ->toBe(['base' => 'Matex Prześcieradło jersey z gumą', 'size' => '90/100x190/200 cm', 'colour' => 'ciemno niebieskie'])
        ->and($grouper->parseName('SANIPUR Podkład higieniczny- BASIC, 40x70, miętowy'))
        ->toBe(['base' => 'SANIPUR Podkład higieniczny- BASIC', 'size' => '40x70 cm', 'colour' => 'miętowy'])
        ->and($grouper->parseName('Matex Prześcieradło frotte CLASSIC z gumą, 120x200 cm, różowe'))
        ->toBe(['base' => 'Matex Prześcieradło frotte CLASSIC z gumą', 'size' => '120x200 cm', 'colour' => 'różowe'])
        ->and($grouper->parseName('SANIPUR Pokrowiec nieprzemakalny na koc JERSEY zamek 140x200'))
        ->toBe(['base' => 'SANIPUR Pokrowiec nieprzemakalny na koc JERSEY zamek', 'size' => '140x200 cm', 'colour' => null]);
});

it('groups colour and size pages into one product per product line', function (): void {
    $groups = (new SanipurProductGrouper)->group([
        sanipurPage(['external_product_id' => '1', 'sku' => 'TM0131_31A', 'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, turkusowe']),
        sanipurPage(['external_product_id' => '2', 'sku' => 'TM0131_25A', 'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, ciemno niebieskie']),
        sanipurPage(['external_product_id' => '3', 'sku' => 'TM0131_25A', 'name' => 'duplicate SKU on another URL']),
        sanipurPage(['external_product_id' => '4', 'sku' => 'TS3502_0242', 'name' => 'SANIPUR Pokrowiec na poduszkę JERSEY zamek 50x60', 'price_gross_amount' => 45.96]),
        sanipurPage(['external_product_id' => '5', 'sku' => 'TS3502_0501', 'name' => 'SANIPUR Pokrowiec na koc JERSEY zamek 140x200', 'price_gross_amount' => 185.07]),
        sanipurPage(['external_product_id' => '6', 'sku' => 'TS3502_0601', 'name' => 'SANIPUR Pokrowiec na koc JERSEY zamek 160x200', 'price_gross_amount' => 199.0]),
        sanipurPage(['external_product_id' => '7', 'sku' => 'TS0106_0115', 'name' => 'SANIPUR Prześcieradło nieprzemakalne Jersey 80x200', 'price_gross_amount' => 93.14]),
        sanipurPage(['external_product_id' => '8', 'sku' => 'TS0106_0101', 'name' => 'SANIPUR Prześcieradło nieprzemakalne Jersey 80x200, białe', 'price_gross_amount' => 93.14]),
        sanipurPage(['external_product_id' => '9', 'sku' => 'TS0106_0315', 'name' => 'SANIPUR Prześcieradło nieprzemakalne Jersey 120x200, beżowe', 'price_gross_amount' => 111.89]),
        sanipurPage(['external_product_id' => '10', 'sku' => 'TS0106_0309', 'name' => 'SANIPUR Prześcieradło nieprzemakalne Jersey 120x200', 'price_gross_amount' => 111.89]),
    ]);

    expect($groups)->toHaveCount(4);

    $jersey = collect($groups)->firstWhere('name', 'SANIPUR Prześcieradło nieprzemakalne Jersey');
    expect(array_column($jersey['variant_candidates'], 'label'))
        ->toBe(['80x200 cm / Beżowe', '80x200 cm / Białe', '120x200 cm / Beżowe', '120x200 cm / Nr 09']);

    $sheets = collect($groups)->firstWhere('name', 'Matex Prześcieradło jersey z gumą');
    expect($sheets['variant_candidates'])->toHaveCount(2)
        ->and(array_column($sheets['variant_candidates'], 'label'))->toBe(['Ciemno niebieskie', 'Turkusowe'])
        ->and($sheets['attributes'])->toBe([['label' => 'Skład', 'value' => '100% bawełna']]);

    $blanket = collect($groups)->firstWhere('name', 'SANIPUR Pokrowiec na koc JERSEY zamek');
    expect(array_column($blanket['variant_candidates'], 'label'))->toBe(['140x200 cm', '160x200 cm'])
        ->and(array_column($blanket['variant_candidates'], 'price_gross_amount'))->toBe([185.07, 199.0]);

    $pillow = collect($groups)->firstWhere('name', 'SANIPUR Pokrowiec na poduszkę JERSEY zamek 50x60');
    expect($pillow['variant_candidates'])->toHaveCount(1)
        ->and($pillow['variant_candidates'][0]['attributes'])->toBe([]);
});

it('merges sizes kept in separate SKU lines and names colours from other lines of the brand', function (): void {
    $groups = (new SanipurProductGrouper)->group([
        sanipurPage(['external_product_id' => '1', 'sku' => 'TM0131_31A', 'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, turkusowe']),
        sanipurPage(['external_product_id' => '2', 'sku' => 'TM0134_31A', 'name' => 'Matex Prześcieradło jersey z gumą 150/160x190/200', 'price_gross_amount' => 78.70]),
        sanipurPage(['external_product_id' => '3', 'sku' => 'TM0134_03A', 'name' => 'Matex Prześcieradło jersey z gumą 150/160x190/200', 'price_gross_amount' => 78.70]),
        sanipurPage(['external_product_id' => '4', 'sku' => 'TM0135_03A', 'name' => 'Matex Prześcieradło frotte z gumą 180/190x190/200, żółte']),
        sanipurPage(['external_product_id' => '5', 'sku' => 'TM0260_92', 'name' => 'Pościel flanela CZ/SK (140x200-1,70x90-1)', 'brand' => null]),
        sanipurPage(['external_product_id' => '6', 'sku' => 'TM0260_93', 'name' => 'Pościel flanela CZ/SK (140x200-1,70x90-1)', 'brand' => null]),
    ]);

    $jersey = collect($groups)->firstWhere('name', 'Matex Prześcieradło jersey z gumą');
    expect(array_column($jersey['variant_candidates'], 'label'))
        ->toBe(['90/100x190/200 cm / Turkusowe', '150/160x190/200 cm / Turkusowe', '150/160x190/200 cm / Żółte']);

    $bedding = collect($groups)->firstWhere('name', 'Pościel flanela CZ/SK (140x200-1,70x90-1)');
    expect(array_column($bedding['variant_candidates'], 'label'))->toBe(['Nr 92', 'Nr 93']);
});

it('drops per-size levels from the sanipur.eu category path', function (): void {
    expect(SanipurProductImporter::categoryPath(['Dla Domu', 'Prześcieradła', 'Prześcieradła według rozmiaru', 'Prześcieradła 160x200 cm']))
        ->toBe(['Dla Domu', 'Prześcieradła'])
        ->and(SanipurProductImporter::categoryPath(['Pościel dla dzieci według rozmiaru', 'Pościel dla dzieci 80x100 cm']))
        ->toBe(['Pościel dla dzieci'])
        ->and(SanipurProductImporter::categoryPath(['Medyczne', 'A', 'B', 'C', 'D']))->toBe(['Medyczne', 'A', 'B'])
        ->and(SanipurProductImporter::categoryPath([]))->toBe(['Pozostałe']);
});

it('crawls the sitemap into a JSON file without touching the database', function (): void {
    Http::fake([
        'https://sanipur.eu/sitemap.xml' => Http::response(
            '<urlset><url><loc>https://sanipur.eu/pl</loc></url><url><loc>https://sanipur.eu/pl/search</loc></url>'
            .'<url><loc>https://sanipur.eu/pl/prze%C5%9Bcierad%C5%82a</loc></url>'
            .'<url><loc>https://sanipur.eu/pl/jersey-180x200</loc></url><url><loc>https://sanipur.eu/en/jersey-180x200</loc></url>'
            .'<url><loc>https://sanipur.eu/pl/bez-ceny</loc></url></urlset>'
        ),
        'https://sanipur.eu/pl/bez-ceny' => Http::response(sanipurProductHtml(['id' => '10071', 'price' => 'Cena regularna: 0,00 zł'])),
        'https://sanipur.eu/pl/jersey-180x200' => Http::response(sanipurProductHtml()),
        'https://sanipur.eu/pl/*' => Http::response('<html><body>kategoria</body></html>'),
    ]);

    $this->artisan('sanipur:crawl', ['--save' => 'scrapers/sanipur/crawl-test.json', '--request-delay-ms' => 0, '--no-progress' => true])
        ->expectsOutputToContain('Pages to read: 3')
        ->expectsOutputToContain('Product pages: 1')
        ->expectsOutputToContain('Product pages without a price (not imported): 1')
        ->expectsOutputToContain('Non-product pages skipped: 1')
        ->assertSuccessful();

    $saved = json_decode(Storage::disk('local')->get('scrapers/sanipur/crawl-test.json'), true);

    expect($saved['product_count'])->toBe(1)
        ->and($saved['products'][0]['sku'])->toBe('TS0106_0609')
        ->and(Product::query()->count())->toBe(0);
});

it('dry-runs the import without database writes', function (): void {
    writeSanipurFixture([sanipurPage(['external_product_id' => '1']), sanipurPage(['external_product_id' => '2', 'sku' => 'TM0131_25A', 'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, niebieskie'])]);

    $this->artisan('sanipur:import', ['--from' => 'scrapers/sanipur/test.json', '--dry-run' => true])
        ->expectsOutputToContain('Dry-run summary. No database writes were made.')
        ->expectsOutputToContain('Products to import/update: 1')
        ->expectsOutputToContain('Variants to create/update: 2')
        ->expectsOutputToContain('Variants without a VAT rate: 2')
        ->expectsOutputToContain('Dla Domu > Prześcieradła')
        ->assertSuccessful();

    expect(Product::query()->count())->toBe(0)->and(Category::query()->count())->toBe(0);
});

it('refuses to import Sanipur products without an explicit VAT rate', function (): void {
    writeSanipurFixture([sanipurPage()]);

    $this->artisan('sanipur:import', ['--from' => 'scrapers/sanipur/test.json', '--no-images' => true])
        ->expectsOutputToContain('Sanipur VAT rate is not explicit.')
        ->assertFailed();

    expect(Product::query()->count())->toBe(0);
});

it('imports grouped Sanipur products as drafts with priced variants and updates them in place', function (): void {
    writeSanipurFixture([
        sanipurPage(['external_product_id' => '1', 'sku' => 'TM0131_31A']),
        sanipurPage(['external_product_id' => '2', 'sku' => 'TM0131_25A', 'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, niebieskie', 'price_gross_amount' => 59.99]),
        sanipurPage([
            'external_product_id' => '8668', 'sku' => 'TS0106_0609', 'brand' => 'Sanipur',
            'name' => 'SANIPUR Prześcieradło nieprzemakalne Jersey 180x200', 'price_gross_amount' => 143.94,
            'is_medical_device' => true, 'breadcrumbs' => ['Medyczne', 'Prześcieradła nieprzemakalne'],
        ]),
    ]);

    $this->artisan('sanipur:import', ['--from' => 'scrapers/sanipur/test.json', '--no-images' => true, '--vat-rate' => 23, '--medical-vat-rate' => 8])
        ->expectsOutputToContain('Imported products: 2')
        ->assertSuccessful();

    $sheets = Product::query()->where('external_source', 'sanipur')->where('name', 'Matex Prześcieradło jersey z gumą')->firstOrFail();
    $variants = ProductVariant::query()->where('product_id', $sheets->id)->orderBy('id')->get();

    expect($sheets->status)->toBe(ProductStatus::DRAFT)
        ->and($sheets->categories()->firstOrFail()->slug)->toBe('sanipur-dla-domu-przescieradla')
        ->and($sheets->categories()->firstOrFail()->parent->parent->slug)->toBe('sanipur')
        ->and($variants)->toHaveCount(2)
        ->and($variants->pluck('sku')->sort()->values()->all())->toBe(['SNP-TM0131_25A', 'SNP-TM0131_31A'])
        ->and($variants->every(fn ($v) => $v->vat_rate === VatRate::VAT_23 && $v->status === ProductVariantStatus::DRAFT))->toBeTrue()
        ->and($variants->firstWhere('sku', 'SNP-TM0131_25A')->price_gross_amount)->toBe(5999)
        ->and($variants->firstWhere('sku', 'SNP-TM0131_25A')->attributeValues()->whereHas('attribute', fn ($q) => $q->where('slug', 'kolor'))->exists())->toBeTrue();

    $medical = ProductVariant::query()->where('sku', 'SNP-TS0106_0609')->firstOrFail();
    expect($medical->vat_rate)->toBe(VatRate::VAT_8)
        ->and($medical->price_gross_amount)->toBe(14394)
        ->and($medical->price_net_amount)->toBe(VatRate::VAT_8->netFromGross(14394));

    writeSanipurFixture([
        sanipurPage(['external_product_id' => '1', 'sku' => 'TM0131_31A', 'price_gross_amount' => 61.00]),
        sanipurPage(['external_product_id' => '2', 'sku' => 'TM0131_25A', 'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, niebieskie', 'price_gross_amount' => 59.99]),
    ]);

    $this->artisan('sanipur:import', ['--from' => 'scrapers/sanipur/test.json', '--no-images' => true, '--vat-rate' => 23])->assertSuccessful();

    expect(Product::query()->where('external_source', 'sanipur')->count())->toBe(2)
        ->and(ProductVariant::query()->where('sku', 'SNP-TM0131_31A')->value('price_gross_amount'))->toBe(6100);
});
