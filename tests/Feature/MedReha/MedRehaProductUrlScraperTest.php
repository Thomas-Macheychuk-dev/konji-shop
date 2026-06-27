<?php

use App\Services\MedReha\MedRehaCategoryUrlScraper;
use App\Services\MedReha\MedRehaProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('scrapes MedReha product links from discovered leaf categories and paginates category pages', function (): void {
    $categoryDiscovery = medRehaCategoryDiscoveryFixture();

    Http::fake([
        'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169' => Http::response(medRehaProductListFixture(
            nextUrl: '/pl/c/PASY-NA-KREGOSLUP/169/2',
            products: [
                [
                    '/ortezy-stabilizatory/pas-ledzwiowy-gorset',
                    'Pas Lędźwiowy Gorset Ortopedyczny',
                    '/pl/c/IGNORED-CATEGORY/999',
                ],
            ],
        )),
        'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169/2' => Http::response(medRehaProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
                    'Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
                    '/pl/i/Kontakt/9',
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($categoryDiscovery);

    expect($result['source'])->toBe('medreha')
        ->and($result['source_categories'])->toHaveCount(1)
        ->and($result['source_categories'][0])->toMatchArray([
            'name' => 'PASY NA KRĘGOSŁUP',
            'url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
            'top_category_name' => 'ORTEZY I STABILIZATORY',
            'top_category_url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
            'path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
        ])
        ->and($result['product_urls'])->toBe([
            'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
        ]);

    expect($result['products'][0])->toMatchArray([
        'url' => 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        'name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
        'category_name' => 'PASY NA KRĘGOSŁUP',
        'category_url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        'top_category_name' => 'ORTEZY I STABILIZATORY',
        'top_category_url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
        'category_path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
    ]);

    expect($result['category_results'][0])->toMatchArray([
        'name' => 'PASY NA KRĘGOSŁUP',
        'url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        'product_count' => 2,
        'pages_scraped' => 2,
        'visited_urls' => [
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169/2',
        ],
    ]);
});

it('can scrape product links from an explicit custom MedReha category URL without hierarchy discovery', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/sprzet-sportowy' => Http::response(medRehaProductListFixture(
            nextUrl: '/sprzet-sportowy/2',
            products: [
                [
                    '/pl/p/Stabilizator-Lokcia-Orteza-Opaska-Lokiec-Tenisisty/529',
                    'Stabilizator Łokcia Orteza Opaska Łokieć Tenisisty',
                    '/sprzet-sportowy/2',
                ],
            ],
        )),
        'https://sklep.medreha.pl/sprzet-sportowy/2' => Http::response(medRehaProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/sport/rekawiczki-sportowe-na-silownie-rower-do-cwiczen-silowych-fitness',
                    'Rękawiczki Sportowe Na Siłownie Rower Do Ćwiczeń Siłowych Fitness',
                    '/pl/s',
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://sklep.medreha.pl/sprzet-sportowy']);

    expect($result['product_urls'])->toBe([
        'https://sklep.medreha.pl/pl/p/Stabilizator-Lokcia-Orteza-Opaska-Lokiec-Tenisisty/529',
        'https://sklep.medreha.pl/sport/rekawiczki-sportowe-na-silownie-rower-do-cwiczen-silowych-fitness',
    ])
        ->and($result['source_categories'])->toBe([
            [
                'name' => 'Sprzet Sportowy',
                'url' => 'https://sklep.medreha.pl/sprzet-sportowy',
                'top_category_name' => null,
                'top_category_url' => null,
                'path' => [],
            ],
        ]);
});

it('stops MedReha category pagination when a later page only repeats already collected product links', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/sprzet-sportowy' => Http::response(medRehaProductListFixture(
            nextUrl: '/sprzet-sportowy/2',
            products: [
                [
                    '/pl/p/Stabilizator-Lokcia-Orteza-Opaska-Lokiec-Tenisisty/529',
                    'Stabilizator Łokcia Orteza Opaska Łokieć Tenisisty',
                    null,
                ],
            ],
        )),
        'https://sklep.medreha.pl/sprzet-sportowy/2' => Http::response(medRehaProductListFixture(
            nextUrl: '/sprzet-sportowy/3',
            products: [
                [
                    '/pl/p/Stabilizator-Lokcia-Orteza-Opaska-Lokiec-Tenisisty/529',
                    'Stabilizator Łokcia Orteza Opaska Łokieć Tenisisty',
                    null,
                ],
            ],
        )),
        'https://sklep.medreha.pl/sprzet-sportowy/3' => Http::response(medRehaProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/pl/p/Product-That-Should-Not-Be-Requested/999',
                    'Product That Should Not Be Requested',
                    null,
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://sklep.medreha.pl/sprzet-sportowy']);

    expect($result['product_urls'])->toBe([
        'https://sklep.medreha.pl/pl/p/Stabilizator-Lokcia-Orteza-Opaska-Lokiec-Tenisisty/529',
    ])
        ->and($result['category_results'][0]['visited_urls'])->toBe([
            'https://sklep.medreha.pl/sprzet-sportowy',
            'https://sklep.medreha.pl/sprzet-sportowy/2',
        ]);
});

it('can discover MedReha categories before scraping product links', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/c' => Http::response(medRehaCategoryMenuFixture()),
        'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169' => Http::response(medRehaProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/ortezy-stabilizatory/pas-ledzwiowy-gorset',
                    'Pas Lędźwiowy Gorset Ortopedyczny',
                    null,
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            startUrls: [MedRehaCategoryUrlScraper::DEFAULT_URL],
            pageLimit: 1,
            categoryLimit: 1,
        );

    expect($result['product_urls'])->toBe([
        'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
    ])
        ->and($result['source_categories'][0]['name'])->toBe('PASY NA KRĘGOSŁUP')
        ->and($result['source_categories'][0]['path'])->toBe([
            'ORTEZY I STABILIZATORY',
            'PASY ORTOPEDYCZNE',
            'PASY NA KRĘGOSŁUP',
        ]);
});

it('can use a saved MedReha category discovery JSON file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/medreha/categories-test.json', json_encode(
        medRehaCategoryDiscoveryFixture(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));

    Http::fake([
        'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169' => Http::response(medRehaProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/ortezy-stabilizatory/pas-ledzwiowy-gorset',
                    'Pas Lędźwiowy Gorset Ortopedyczny',
                    null,
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('medreha:product-links', [
        '--categories-from' => 'scrapers/medreha/categories-test.json',
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_urls'])->toBe([
        'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
    ])
        ->and($decoded['products'][0]['category_path'])->toBe([
            'ORTEZY I STABILIZATORY',
            'PASY ORTOPEDYCZNE',
            'PASY NA KRĘGOSŁUP',
        ]);
});

it('records failed MedReha product category page requests', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        ]);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169' => 'HTTP 500',
        ]);
});

/**
 * @return array<string, mixed>
 */
function medRehaCategoryDiscoveryFixture(): array
{
    return [
        'source' => 'medreha',
        'start_urls' => ['https://sklep.medreha.pl/pl/c'],
        'top_categories' => [],
        'categories' => [
            [
                'source' => 'medreha',
                'external_category_id' => '188',
                'name' => 'ORTEZY I STABILIZATORY',
                'source_name' => 'ORTEZY I STABILIZATORY',
                'url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
                'slug' => '188',
                'level' => 1,
                'parent_external_category_id' => null,
                'path' => ['ORTEZY I STABILIZATORY'],
            ],
            [
                'source' => 'medreha',
                'external_category_id' => '145',
                'name' => 'PASY ORTOPEDYCZNE',
                'source_name' => 'PASY ORTOPEDYCZNE',
                'url' => 'https://sklep.medreha.pl/pl/c/PASY-ORTOPEDYCZNE/145',
                'slug' => '145',
                'level' => 2,
                'parent_external_category_id' => '188',
                'path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE'],
            ],
            [
                'source' => 'medreha',
                'external_category_id' => '169',
                'name' => 'PASY NA KRĘGOSŁUP',
                'source_name' => 'PASY NA KRĘGOSŁUP',
                'url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
                'slug' => '169',
                'level' => 3,
                'parent_external_category_id' => '145',
                'path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
            ],
        ],
        'category_urls' => [
            'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
            'https://sklep.medreha.pl/pl/c/PASY-ORTOPEDYCZNE/145',
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        ],
        'product_category_urls' => [
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        ],
        'visited_urls' => ['https://sklep.medreha.pl/pl/c'],
        'failed_urls' => [],
    ];
}

/**
 * @param  array<int, array{0: string, 1: string, 2: string|null}>  $products
 */
function medRehaProductListFixture(?string $nextUrl, array $products): string
{
    $items = '';

    foreach ($products as [$url, $name, $extraUrl]) {
        $extraLink = $extraUrl === null ? '' : '<a href="'.$extraUrl.'">Ignored non-product link</a>';
        $items .= <<<HTML
            <div class="product s-grid-3 product-main-wrap">
                <a class="prodimage f-row" href="{$url}" title="{$name}"><img src="/userdata/public/fixture.webp" alt="{$name}"></a>
                <a class="prodname f-row" href="{$url}"><span>{$name}</span></a>
                {$extraLink}
            </div>
        HTML;
    }

    $nextLink = $nextUrl === null
        ? ''
        : <<<HTML
            <ul class="paginator">
                <li><a href="{$nextUrl}">2</a></li>
                <li class="next"><a href="{$nextUrl}">&gt;</a></li>
            </ul>
            <link rel="next" href="{$nextUrl}">
        HTML;

    return <<<HTML
        <html>
            <body class="shop_product_list">
                <div id="box_mainproducts">
                    <div class="products viewphot">
                        {$items}
                    </div>
                    {$nextLink}
                </div>
                <ul class="menu-list">
                    <li><a href="/pl/c/PASY-NA-KREGOSLUP/169">PASY NA KRĘGOSŁUP category menu link</a></li>
                    <li><a href="/pl/i/Kontakt/9">Kontakt</a></li>
                </ul>
            </body>
        </html>
    HTML;
}

function medRehaCategoryMenuFixture(): string
{
    return <<<'HTML'
        <html><body>
            <ul class="menu-list large standard">
                <li class="parent" id="hcategory_0">
                    <h3><a href="#"><span>Menu</span></a></h3>
                    <div class="submenu level1">
                        <ul class="level1">
                            <li id="hcategory_188" class="parent">
                                <h3><a href="/pl/c/ORTEZY-I-STABILIZATORY/188"><span>ORTEZY I STABILIZATORY</span></a></h3>
                                <div class="submenu level2">
                                    <ul class="level2">
                                        <li id="hcategory_145" class="parent">
                                            <h3><a href="/pl/c/PASY-ORTOPEDYCZNE/145"><span>PASY ORTOPEDYCZNE</span></a></h3>
                                            <div class="submenu level3">
                                                <ul class="level3">
                                                    <li id="hcategory_169"><h3><a href="/pl/c/PASY-NA-KREGOSLUP/169"><span>PASY NA KRĘGOSŁUP</span></a></h3></li>
                                                </ul>
                                            </div>
                                        </li>
                                    </ul>
                                </div>
                            </li>
                        </ul>
                    </div>
                </li>
            </ul>
        </body></html>
    HTML;
}
