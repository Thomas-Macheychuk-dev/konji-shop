<?php

use App\Services\RelaxSan\RelaxSanCategoryUrlScraper;
use App\Services\RelaxSan\RelaxSanProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('scrapes RelaxSan product links from discovered leaf categories and preserves category context', function (): void {
    $categoryDiscovery = relaxSanCategoryDiscoveryFixture();

    Http::fake([
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => Http::response(relaxSanProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
                    'Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic',
                    '/wyroby-przeciwzylakowe',
                ],
                [
                    '/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-kompresja-12-17-mmHg-RelaxSan-Basic-/102',
                    'Podkolanówki uciskowe profilaktyczne 70 DEN kompresja 12-17 mmHg RelaxSan Basic',
                    '/pl/i/Kontakt/15',
                ],
                [
                    '/pl/p/Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber/103',
                    'Podkolanówki przeciwżylakowe profilaktyczne 70 DEN z mikrofibry ucisk 12-17 mmHg RelaxSan Microfiber',
                    '/pl/n/list',
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($categoryDiscovery);

    expect($result['source'])->toBe('relaxsan')
        ->and($result['source_categories'])->toHaveCount(1)
        ->and($result['source_categories'][0])->toMatchArray([
            'name' => 'Podkolanówki uciskowe profilaktyczne',
            'url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
            'top_category_name' => 'Przeciwżylakowe',
            'top_category_url' => 'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
            'path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
        ])
        ->and($result['product_urls'])->toBe([
            'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
            'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-kompresja-12-17-mmHg-RelaxSan-Basic-/102',
            'https://relaxsansklep.pl/pl/p/Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber/103',
        ]);

    expect($result['products'][0])->toMatchArray([
        'url' => 'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
        'name' => 'Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic',
        'category_name' => 'Podkolanówki uciskowe profilaktyczne',
        'category_url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        'top_category_name' => 'Przeciwżylakowe',
        'top_category_url' => 'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
        'category_path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
    ]);

    expect($result['category_results'][0])->toMatchArray([
        'name' => 'Podkolanówki uciskowe profilaktyczne',
        'url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        'product_count' => 3,
        'pages_scraped' => 1,
        'visited_urls' => [
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        ],
    ]);
});

it('can scrape RelaxSan product links from an explicit category URL without hierarchy discovery', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => Http::response(relaxSanProductListFixture(
            nextUrl: '/podkolanowki-uciskowe-profilaktyczne/2',
            products: [
                [
                    '/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
                    'Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic',
                    null,
                ],
            ],
        )),
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne/2' => Http::response(relaxSanProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-kompresja-12-17-mmHg-RelaxSan-Basic-/102',
                    'Podkolanówki uciskowe profilaktyczne 70 DEN kompresja 12-17 mmHg RelaxSan Basic',
                    null,
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne']);

    expect($result['product_urls'])->toBe([
        'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
        'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-kompresja-12-17-mmHg-RelaxSan-Basic-/102',
    ])
        ->and($result['source_categories'])->toBe([
            [
                'name' => 'Podkolanowki Uciskowe Profilaktyczne',
                'url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
                'top_category_name' => null,
                'top_category_url' => null,
                'path' => [],
            ],
        ]);
});

it('ignores RelaxSan non-card product links and utility links from category pages', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => Http::response(<<<'HTML'
            <html><body class="shop_product_list">
                <ul class="menu-list">
                    <li><a href="https://relaxsansklep.pl/pl/p/Maseczka-ochronna-wielokrotnego-uzytku-RelaxSan-2-sztuki-/325">Maseczki</a></li>
                    <li><a href="/pl/i/Kontakt/15">Kontakt</a></li>
                </ul>
                <div id="box_mainproducts">
                    <div class="products viewphot">
                        <div class="product s-grid-3 product-main-wrap">
                            <a class="prodimage f-row" href="/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101" title="Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic">
                                <img alt="Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic">
                            </a>
                            <a href="/pl/i/Kontakt/15">Ignored utility link inside card</a>
                        </div>
                    </div>
                </div>
            </body></html>
        HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne']);

    expect($result['product_urls'])->toBe([
        'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
    ]);
});

it('can discover RelaxSan categories before scraping product links', function (): void {
    Http::fake([
        'https://relaxsansklep.pl' => Http::response(relaxSanCategoryMenuFixture()),
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => Http::response(relaxSanProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
                    'Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic',
                    null,
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            startUrls: [RelaxSanCategoryUrlScraper::DEFAULT_URL],
            pageLimit: 1,
            categoryLimit: 1,
        );

    expect($result['product_urls'])->toBe([
        'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
    ])
        ->and($result['source_categories'][0]['name'])->toBe('Podkolanówki uciskowe profilaktyczne')
        ->and($result['source_categories'][0]['path'])->toBe([
            'Przeciwżylakowe',
            'Podkolanówki uciskowe',
            'Podkolanówki uciskowe profilaktyczne',
        ]);
});

it('can use a saved RelaxSan category discovery JSON file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/relaxsan/categories-test.json', json_encode(
        relaxSanCategoryDiscoveryFixture(),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ));

    Http::fake([
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => Http::response(relaxSanProductListFixture(
            nextUrl: null,
            products: [
                [
                    '/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
                    'Podkolanówki uciskowe profilaktyczne 70 DEN bez pięty kompresja 12-17 mmHg RelaxSan Basic',
                    null,
                ],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('relaxsan:product-links', [
        '--categories-from' => 'scrapers/relaxsan/categories-test.json',
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_urls'])->toBe([
        'https://relaxsansklep.pl/pl/p/Podkolanowki-uciskowe-profilaktyczne-70-DEN-bez-piety-kompresja-12-17-mmHg-RelaxSan-Basic/101',
    ])
        ->and($decoded['products'][0]['category_path'])->toBe([
            'Przeciwżylakowe',
            'Podkolanówki uciskowe',
            'Podkolanówki uciskowe profilaktyczne',
        ]);
});

it('records failed RelaxSan product category page requests', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        ]);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne' => 'HTTP 500',
        ]);
});

/**
 * @return array<string, mixed>
 */
function relaxSanCategoryDiscoveryFixture(): array
{
    return [
        'source' => 'relaxsan',
        'start_urls' => ['https://relaxsansklep.pl'],
        'top_categories' => [],
        'categories' => [
            [
                'source' => 'relaxsan',
                'external_category_id' => '201',
                'name' => 'Przeciwżylakowe',
                'source_name' => 'Przeciwżylakowe',
                'url' => 'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
                'slug' => 'wyroby-przeciwzylakowe',
                'level' => 1,
                'parent_external_category_id' => null,
                'path' => ['Przeciwżylakowe'],
            ],
            [
                'source' => 'relaxsan',
                'external_category_id' => '209',
                'name' => 'Podkolanówki uciskowe',
                'source_name' => 'Podkolanówki uciskowe',
                'url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe',
                'slug' => 'podkolanowki-uciskowe',
                'level' => 2,
                'parent_external_category_id' => '201',
                'path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe'],
            ],
            [
                'source' => 'relaxsan',
                'external_category_id' => '248',
                'name' => 'Podkolanówki uciskowe profilaktyczne',
                'source_name' => 'Podkolanówki uciskowe profilaktyczne',
                'url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
                'slug' => 'podkolanowki-uciskowe-profilaktyczne',
                'level' => 3,
                'parent_external_category_id' => '209',
                'path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
            ],
        ],
        'category_urls' => [
            'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
            'https://relaxsansklep.pl/podkolanowki-uciskowe',
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        ],
        'product_category_urls' => [
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
        ],
        'visited_urls' => ['https://relaxsansklep.pl'],
        'failed_urls' => [],
    ];
}

/**
 * @param  array<int, array{0: string, 1: string, 2: string|null}>  $products
 */
function relaxSanProductListFixture(?string $nextUrl, array $products): string
{
    $items = '';

    foreach ($products as [$url, $name, $extraUrl]) {
        $extraLink = $extraUrl === null ? '' : '<a href="'.$extraUrl.'">Ignored non-product link</a>';
        $items .= <<<HTML
            <div data-product-id="101" data-category="Podkolanówki uciskowe profilaktyczne" data-producer="RelaxSan" class="product s-grid-3 product-main-wrap">
                <div class="product-inner-wrap">
                    <a class="prodimage f-row" href="{$url}" title="{$name}" rel="nofollow">
                        <span class="f-grid-12 img-wrap replace-img-list lazy-load">
                            <img src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs%3D" data-src="/environment/cache/images/productGfx_3129_300_300/fixture.webp" alt="{$name}" />
                        </span>
                    </a>
                    <p class="h3">
                        <a class="prodname f-row" href="{$url}" title="{$name}">
                            <span class="productname">{$name}</span>
                        </a>
                    </p>
                    {$extraLink}
                </div>
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
            <body id="shop_category248" class="shop_product_list">
                <ul class="menu-list">
                    <li><a href="https://relaxsansklep.pl/pl/p/Maseczka-ochronna-wielokrotnego-uzytku-RelaxSan-2-sztuki-/325">Maseczki menu product link should be ignored</a></li>
                    <li><a href="/pl/i/Kontakt/15">Kontakt</a></li>
                </ul>
                <div id="box_mainproducts">
                    <div class="products viewphot s-row">
                        {$items}
                    </div>
                    {$nextLink}
                </div>
            </body>
        </html>
    HTML;
}

function relaxSanCategoryMenuFixture(): string
{
    return <<<'HTML'
        <html><body>
            <ul class="menu-list large standard">
                <li class="parent" id="hcategory_201">
                    <p class="h3"><a href="/wyroby-przeciwzylakowe" title="Przeciwżylakowe"><span>Przeciwżylakowe</span></a></p>
                    <div class="submenu level1">
                        <ul class="level1">
                            <li class="parent" id="hcategory_209">
                                <p class="h3"><a href="/podkolanowki-uciskowe" title="Podkolanówki uciskowe"><span>Podkolanówki uciskowe</span></a></p>
                                <div class="submenu level2">
                                    <ul class="level2">
                                        <li id="hcategory_248"><p class="h3"><a href="/podkolanowki-uciskowe-profilaktyczne" title="Podkolanówki uciskowe profilaktyczne"><span>Podkolanówki uciskowe profilaktyczne</span></a></p></li>
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
