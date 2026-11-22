<?php

use App\Enums\ProductStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Corradomed\CorradomedPageScraper;
use App\Services\Corradomed\CorradomedPriceList;
use App\Services\Corradomed\CorradomedProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function corradomedPriceList(): CorradomedPriceList
{
    $row = fn (string $code, int $vat, array $prices, bool $star = false): array => [
        'code' => $code,
        'name' => '',
        'vat_rate' => $vat,
        'made_to_order' => $star,
        'prices' => array_merge(array_fill_keys(CorradomedPriceList::COLUMNS, null), $prices),
    ];

    return new CorradomedPriceList([
        'valid_from' => '2026-03-01',
        'items' => [
            $row('UP-2', 8, ['painted_25_white' => 34, 'painted_25_black' => 41, 'inox_25' => 89]),
            $row('UP-10', 8, ['painted_25_white' => 54, 'inox_25' => 130], star: true),
            $row('UKS-3', 8, ['painted_25_white' => 52, 'painted_32_white' => 68]),
            $row('UPPP - 6/6/6', 8, ['painted_25_white' => 175]),
            $row('KOZETKA', 8, ['painted_25_white' => 525, 'painted_25_black' => 630, 'inox_25' => 790]),
            $row('TABORET CHROM', 23, ['painted_25_white' => 260]),
            $row('SŁUCHAWKI', 23, ['inox_25' => 115]),
        ],
        'aliases' => ['TABORETLEKARSKIPODSTAWACHROM' => 'TABORET CHROM'],
        'page_codes' => [],
        'page_names' => ['medycz?do=kozet' => 'Kozetka lekarska'],
    ]);
}

function corradomedHtml(string $h1, array $codes, string $extra = ''): string
{
    $rows = implode('', array_map(fn (string $code) => "<tr><td class=\"tab8\">{$code}</td><td class=\"tab8\">200</td></tr>", $codes));

    $html = <<<HTML
<html><head><meta http-equiv="Content-type" content="text/html; charset=iso-8859-2"><title>Uchwyty - malowane proszkowo fi 25 mm - Corradomed</title></head><body>
<div id="cssmenu"><ul><li><a href="uchwyty" class="activ"><span>Uchwyty</span></a></li><li><a href="medycz"><span>Meble medyczne</span></a></li></ul></div>
<table><tr><td class="tab4"><div id="cssmenu2"><ul><li><a href="uchwyty?do=proste" class="activ"><span>Proste</span></a></li></ul></div></td>
<td class="tab3"><div>
<h1>{$h1}</h1>
<div><a href="uchwyt/proste/rys01c.jpg"><img src="uchwyt/proste/rys01b.jpg"></a></div>
<div><strong>Informacja: Uchwyty o długości od 1100 mm posiadają dodatkowy wspornik.</strong></div>
<div><img src="uchwyt/uchwyty-łazienkowe-proste.jpg"><img src="rys/logo.png"><img src="medycz/wzornik/1004m.jpg"></div>
<table class="wyroby2"><tr><td class="tab8"><strong>Oznaczenie Uchwytu</strong></td><td class="tab8"><strong>A [mm]</strong></td></tr>{$rows}
<tr><td colspan="2">Informacja: do uchwytów jest możliwość dokupienia zawieszki.</td></tr></table>
{$extra}
<a href="uchwyty?do=proste02">dalej</a> <a href="https://corradomed.pl/medycz?do=kozet">kozetka</a> <a href="https://example.com/x">obcy</a>
</div></td></tr></table></body></html>
HTML;

    return mb_convert_encoding($html, 'ISO-8859-2', 'UTF-8');
}

function corradomedPage(array $overrides = []): array
{
    return array_merge([
        'source' => 'corradomed',
        'page' => 'uchwyty?do=proste01',
        'source_url' => 'https://corradomed.pl/uchwyty?do=proste01',
        'title' => 'UP - Uchwyty łazienkowe proste',
        'subtitle' => 'malowane proszkowo fi 25 mm - różne rozmiary',
        'section' => 'Uchwyty',
        'subsection' => 'Proste',
        'codes' => ['UP - 2', 'UP - 10', 'UP - 99'],
        'specification' => [
            ['code' => 'UP - 2', 'values' => ['A [mm]' => '200']],
            ['code' => 'UP - 10', 'values' => ['A [mm]' => '1000']],
        ],
        'notes' => ['Uchwyty o długości od 1100 mm posiadają dodatkowy wspornik.'],
        'description_paragraphs' => [],
        'upholstery_colours' => [],
        'images' => [],
    ], $overrides);
}

it('parses an ISO-8859-2 corradomed.pl page into codes, images, notes and links', function (): void {
    $parsed = (new CorradomedPageScraper)->parse(
        corradomedHtml('UP - Uchwyty łazienkowe proste<br><span>- malowane proszkowo fi 25 mm - różne rozmiary</span>', ['UP - 2', 'UP - 10']),
        'uchwyty?do=proste01',
    );
    $product = $parsed['product'];

    expect($product['title'])->toBe('UP - Uchwyty łazienkowe proste')
        ->and($product['subtitle'])->toBe('malowane proszkowo fi 25 mm - różne rozmiary')
        ->and($product['section'])->toBe('Uchwyty')
        ->and($product['subsection'])->toBe('Proste')
        ->and($product['codes'])->toBe(['UP - 2', 'UP - 10'])
        ->and($product['specification'][0])->toBe(['code' => 'UP - 2', 'values' => ['A [mm]' => '200']])
        ->and($product['notes'])->toContain('Uchwyty o długości od 1100 mm posiadają dodatkowy wspornik.')
        ->and($product['images'])->toBe([
            'https://corradomed.pl/uchwyt/proste/rys01c.jpg',
            'https://corradomed.pl/uchwyt/uchwyty-łazienkowe-proste.jpg',
        ])
        ->and($parsed['links'])->toContain('uchwyty?do=proste02', 'medycz?do=kozet', 'uchwyty', 'uchwyty?do=proste')
        ->and($parsed['links'])->not->toContain('https://example.com/x');

    // Section pages repeat a sub-page and are not products; a heading without the finish takes it from <title>.
    expect((new CorradomedPageScraper)->parse(corradomedHtml('UP', ['UP - 2']), 'uchwyty')['product'])->toBeNull()
        ->and((new CorradomedPageScraper)->parse(corradomedHtml('Uchwyty do umywalki', ['UU - 5']), 'uchwyty?do=umyw01')['product']['subtitle'])
        ->toBe('malowane proszkowo fi 25 mm');
});

it('matches corradomed.pl codes to price list codes', function (string $site, string $list): void {
    expect(CorradomedPriceList::normaliseCode($site))->toBe(CorradomedPriceList::normaliseCode($list));
})->with([
    ['UP - 2 INOX ø 25', 'UP-2'],
    ['UP - 3 M ø 32', 'UP-3'],
    ['UU - 5 s ø 32', 'UU-5s'],
    ['UKS 3 INOX ø 32', 'UKS-3'],
    ['UPPP-L-6/6/6 ø 32', 'UPPP - 6/6/6'],
    ['UWP-P-P-10/7/9 INOX ø 25', 'UWP-P - 10/7/9 *'],
    ['UUWC - 5 noga ø 32', 'UUWC-5 NOGA'],
    ['UUWCW-6s ø32', 'UUWCW-6s'],
    ['KPU-2 INOX PVC', 'KPU-2 PVC'],
    ['DR. 900x900x500 INOX ø 25', 'DR 900X900X500'],
    ['ŁNW', 'ŁNW'],
]);

it('prices list net plus 25% with the list VAT, rounded to whole złoty', function (): void {
    expect(CorradomedPriceList::grossMinorUnits(34, VatRate::VAT_8))->toBe(4600)        // 45,90
        ->and(CorradomedPriceList::grossMinorUnits(525, VatRate::VAT_8))->toBe(70900)   // 708,75
        ->and(CorradomedPriceList::grossMinorUnits(85, VatRate::VAT_23))->toBe(13100);  // 130,69
});

it('plans one variant per priced code and colour of the page finish', function (): void {
    $importer = app(CorradomedProductImporter::class);
    $plan = $importer->plan(corradomedPage(), corradomedPriceList());

    expect($plan['name'])->toBe('Corradomed UP - Uchwyty łazienkowe proste, malowane proszkowo fi 25 mm')
        ->and($plan['category'])->toBe(['Uchwyty', 'Proste'])
        ->and(array_map(fn ($v) => [$v['model'], $v['option'], $v['price_gross_amount']], $plan['variants']))->toBe([
            ['UP-2', 'biały (RAL 9003)', 4600],
            ['UP-2', 'czarny (RAL 9005)', 5500],
            ['UP-10', 'biały (RAL 9003)', 7300],
        ])
        ->and($plan['variants'][2]['made_to_order'])->toBeTrue()
        ->and($plan['unpriced_codes'])->toBe(['UP - 99']);

    $inox = $importer->plan(corradomedPage(['page' => 'uchwyty?do=proste04', 'title' => 'UP INOX ø 25 - Uchwyty łazienkowe proste', 'subtitle' => 'stal nierdzewna fi 25 mm']), corradomedPriceList());

    expect(array_map(fn ($v) => [$v['model'], $v['option'], $v['list_net']], $inox['variants']))->toBe([['UP-2', null, 89], ['UP-10', null, 130]]);

    // Medical furniture names no finish: the frame finishes the list prices become options.
    $furniture = $importer->plan(corradomedPage([
        'page' => 'medycz?do=kozet', 'title' => 'Kozetka', 'subtitle' => null,
        'codes' => ['KOZETKA', 'Taboret lekarski podstawa chrom'],
    ]), corradomedPriceList());

    expect($furniture['name'])->toBe('Corradomed Kozetka lekarska')
        ->and(array_map(fn ($v) => [$v['model'], $v['option']], $furniture['variants']))->toBe([
            ['KOZETKA', 'malowany proszkowo, biały (RAL 9003)'],
            ['KOZETKA', 'malowany proszkowo, czarny (RAL 9005)'],
            ['KOZETKA', 'stal nierdzewna'],
            ['Taboret lekarski podstawa chrom', null],
        ])
        ->and($furniture['variants'][3]['vat_rate'])->toBe(23);
});

it('imports a page as a draft product and updates it in place', function (): void {
    $importer = app(CorradomedProductImporter::class);
    $product = $importer->import(corradomedPage(), corradomedPriceList(), importImages: false)['product'];

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_source)->toBe('corradomed')
        ->and($product->external_id)->toBe('uchwyty-proste01')
        ->and($product->variants)->toHaveCount(3)
        ->and($product->description)->toContain('UP-10 wykonywane są na zamówienie', '<td>UP-2</td><td>200</td>')
        ->and($product->categories->first()->name)->toBe('Proste')
        ->and(Category::where('slug', 'corradomed-uchwyty-proste')->exists())->toBeTrue();

    $variant = ProductVariant::where('sku', 'CRD-UP-2-P25W')->firstOrFail();

    expect($variant->price_gross_amount)->toBe(4600)
        ->and($variant->price_net_amount)->toBe(4259)
        ->and($variant->vat_rate)->toBe(VatRate::VAT_8)
        ->and($variant->attributeValues->pluck('value')->all())->toEqualCanonicalizing(['UP-2', 'biały (RAL 9003)']);

    $brand = $product->attributeValues->firstWhere('value', 'Corradomed');
    expect($brand?->manufacturer?->name)->toBe('Corradomed Sp. z o.o.')
        ->and($product->attributeValues->pluck('value'))->toContain('Tak');

    $product->update(['status' => ProductStatus::ACTIVE]);
    $again = $importer->import(corradomedPage(['codes' => ['UP - 2']]), corradomedPriceList(), importImages: false)['product'];

    expect($again->id)->toBe($product->id)
        ->and($again->status)->toBe(ProductStatus::ACTIVE)
        ->and($again->variants)->toHaveCount(2)
        ->and(Product::count())->toBe(1);
});

it('crawls corradomed.pl and dry-runs the import without database writes', function (): void {
    Http::fake([
        'corradomed.pl/uchwyty?do=proste01' => Http::response(corradomedHtml('UP - Uchwyty łazienkowe proste<br><span>- malowane proszkowo fi 25 mm</span>', ['UP - 2', 'UP - 99'])),
        '*' => Http::response('<html><body></body></html>'),
    ]);

    $this->artisan('corradomed:crawl', ['--page' => ['uchwyty?do=proste01'], '--save' => 'scrapers/corradomed/test.json', '--request-delay-ms' => 0, '--no-progress' => true])
        ->expectsOutputToContain('Product pages: 1')
        ->assertSuccessful();

    Http::assertSentCount(1);

    $this->artisan('corradomed:import', ['--from' => 'scrapers/corradomed/test.json', '--dry-run' => true, '--list' => true])
        ->expectsOutputToContain('products to import: 1, variants: 2')
        ->expectsOutputToContain('uchwyty?do=proste01: UP - 99')
        ->expectsOutputToContain('Dry run: no database writes')
        ->assertSuccessful();

    expect(Product::count())->toBe(0);
});

it('gives large furniture the flat large-item delivery price', function (): void {
    $importer = app(CorradomedProductImporter::class);
    $couch = $importer->import(corradomedPage([
        'page' => 'medycz?do=kozet', 'title' => 'Kozetka', 'subtitle' => null,
        'section' => 'Meble medyczne', 'subsection' => 'Kozetka', 'codes' => ['KOZETKA'],
    ]), corradomedPriceList(), importImages: false)['product'];
    $rail = $importer->import(corradomedPage(), corradomedPriceList(), importImages: false)['product'];

    expect($couch->large_item_delivery_amount)->toBe(8000)
        ->and($rail->large_item_delivery_amount)->toBeNull()
        ->and(CorradomedProductImporter::LARGE_ITEM_DELIVERY['Parawan'])->toBe(7200);
});

it('previews, publishes and rolls back the Corradomed drafts', function (): void {
    $product = app(CorradomedProductImporter::class)->import(corradomedPage(), corradomedPriceList(), importImages: false)['product'];

    $this->artisan('corradomed:publish')
        ->expectsOutputToContain('=== CORRADOMED PUBLISH ===')
        ->expectsOutputToContain('PRODUCTS_TO_PUBLISH=1')
        ->assertSuccessful();

    expect($product->fresh()->status)->toBe(ProductStatus::DRAFT);

    $this->artisan('corradomed:publish', ['--write' => true])
        ->expectsOutputToContain('PUBLISHED_PRODUCTS=1')
        ->assertSuccessful();

    expect($product->fresh()->status)->toBe(ProductStatus::ACTIVE)
        ->and($product->fresh()->published_at)->not->toBeNull();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->first(fn (string $path): bool => str_contains($path, 'corradomed-publish-'));

    $this->artisan('corradomed:publish', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRODUCTS=1')
        ->assertSuccessful();

    expect($product->fresh()->status)->toBe(ProductStatus::DRAFT);
});
