<?php

use App\Services\Reh4Mat\Reh4MatCategoryScraper;
use App\Services\Reh4Mat\Reh4MatProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('scrapes Reh4Mat product links from a category and follows pagination', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/' => Http::response(reh4MatProductListFixture(
            nextUrl: 'https://www.reh4mat.com/produkt/ortezy-dloni/page/2/',
            products: [
                ['https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/', 'AM-SP-07'],
                ['https://www.reh4mat.com/produkt/ortezy-dloni/am-d-07/', 'AM-D-07'],
            ],
        )),
        'https://www.reh4mat.com/produkt/ortezy-dloni/page/2/' => Http::response(reh4MatProductListFixture(
            nextUrl: null,
            products: [
                ['https://www.reh4mat.com/produkt/ortezy-dloni/am-d-07/', 'AM-D-07'],
                ['https://www.reh4mat.com/produkt/ortezy-dloni/am-d-08-orteza-dloni/', 'AM-D-08 Orteza dłoni'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([
            'https://www.reh4mat.com/produkt/ortezy-dloni/',
        ]);

    expect($result['product_urls'])->toBe([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-d-07/',
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-d-08-orteza-dloni/',
    ])
        ->and($result['visited_urls'])->toBe([
            'https://www.reh4mat.com/produkt/ortezy-dloni/',
            'https://www.reh4mat.com/produkt/ortezy-dloni/page/2/',
        ])
        ->and($result['category_results'][0]['product_count'])->toBe(3)
        ->and($result['category_results'][0]['pages_scraped'])->toBe(2)
        ->and($result['products'][0]['name'])->toBe('AM-SP-07');
});

it('scrapes Reh4Mat product cards from child page category grids', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/cala-konczyna-dolna/' => Http::response(reh4MatChildPageProductGridFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([
            'https://www.reh4mat.com/produkt/cala-konczyna-dolna/',
        ]);

    expect($result['product_urls'])->toBe([
        'https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/',
        'https://www.reh4mat.com/produkt/kolano-funkcja-pooperacyjna/aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r/',
        'https://www.reh4mat.com/produkt/pediatryczne-cala-konczyna-dolna/dziecieca-orteza-kolana-fix-kd-14/',
        'https://www.reh4mat.com/produkt/hkafo-konczyna-dolna/orteza-konczyny-dolnej-z-gorsetem-tulowia-complex-plus/',
    ])
        ->and($result['products'][0]['name'])->toBe('4ARMY-SK-11')
        ->and($result['products'][1]['name'])->toBe('Orteza kończyny dolnej AM-KD-AM/2R')
        ->and($result['products'][2]['name'])->toBe('Dziecięca orteza kolana FIX-KD-14')
        ->and($result['products'][3]['name'])->toBe('Orteza kończyny dolnej z gorsetem tułowia COMPLEX PLUS TLSO')
        ->and($result['category_results'][0]['product_count'])->toBe(4);
});

it('scrapes Reh4Mat product links from the allowed category hierarchy', function (): void {
    Http::fake([
        Reh4MatCategoryScraper::DEFAULT_CATEGORY_URL => Http::response(reh4MatProductLinksCategoryFixture()),
        'https://www.reh4mat.com/produkt/cala-konczyna-dolna/' => Http::response(reh4MatProductListFixture(
            nextUrl: null,
            products: [
                ['https://www.reh4mat.com/produkt/udo/orteza-konczyny-dolnej-as-u-04/', 'Orteza kończyny dolnej AS-U-04'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            [Reh4MatCategoryScraper::DEFAULT_CATEGORY_URL],
            pageLimit: 1,
            categoryLimit: 1,
        );

    expect($result['product_urls'])->toBe([
        'https://www.reh4mat.com/produkt/udo/orteza-konczyny-dolnej-as-u-04/',
    ])
        ->and($result['source_categories'][0]['name'])->toBe('Ortezy całej kończyny dolnej')
        ->and($result['source_categories'][0]['path'])->toBe([
            'KOŃCZYNA DOLNA',
            'Ortezy całej kończyny dolnej',
        ])
        ->and($result['products'][0]['top_category_name'])->toBe('KOŃCZYNA DOLNA');
});

it('can use a saved Reh4Mat category discovery JSON file', function (): void {
    Storage::disk('local')->put('scrapers/reh4mat/categories-test.json', json_encode([
        'source' => 'reh4mat',
        'top_categories' => [],
        'categories' => [
            [
                'name' => 'KOŃCZYNA GÓRNA',
                'url' => 'https://www.reh4mat.com/produkt/konczyna-gorna/',
                'level' => 1,
                'path' => ['KOŃCZYNA GÓRNA'],
            ],
            [
                'name' => 'Ortezy dłoni',
                'url' => 'https://www.reh4mat.com/produkt/ortezy-dloni/',
                'level' => 2,
                'path' => ['KOŃCZYNA GÓRNA', 'Ortezy dłoni'],
            ],
        ],
        'product_category_urls' => [
            'https://www.reh4mat.com/produkt/ortezy-dloni/',
        ],
        'visited_urls' => ['https://www.reh4mat.com/produkt/'],
        'failed_urls' => [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/' => Http::response(reh4MatProductListFixture(
            nextUrl: null,
            products: [
                ['https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/', 'AM-SP-07'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('reh4mat:product-links', [
        '--categories-from' => 'scrapers/reh4mat/categories-test.json',
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_urls'])->toBe([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
    ])
        ->and($decoded['products'][0]['category_path'])->toBe([
            'KOŃCZYNA GÓRNA',
            'Ortezy dłoni',
        ]);
});

it('records failed Reh4Mat product category page requests', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([
            'https://www.reh4mat.com/produkt/ortezy-dloni/',
        ]);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://www.reh4mat.com/produkt/ortezy-dloni/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.reh4mat.com/produkt/ortezy-dloni/' => 'HTTP 500',
        ]);
});

/**
 * @param  array<int, array{0: string, 1: string}>  $products
 */
function reh4MatProductListFixture(?string $nextUrl, array $products): string
{
    $items = '';

    foreach ($products as [$url, $name]) {
        $items .= <<<HTML
            <div>
                <h2><a href="{$url}" rel="bookmark" title="{$name}">{$name}</a> <span>29.05.2026</span></h2>
            </div>
        HTML;
    }

    $nextLink = $nextUrl === null
        ? ''
        : <<<HTML
            <div class="nav-previous"><a href="{$nextUrl}">Starsze wpisy</a></div>
            <link rel="next" href="{$nextUrl}" />
        HTML;

    return <<<HTML
        <html>
            <body>
                <div id="content">
                    <div class="column">
                        {$items}
                        {$nextLink}
                    </div>
                </div>
                <div id="navigation">
                    <a href="https://www.reh4mat.com/produkt/ortezy-dloni/">Ortezy dłoni category menu link</a>
                    <a href="https://www.reh4mat.com/wyroby-na-zamowienie/">Wyroby na zamówienie</a>
                </div>
            </body>
        </html>
    HTML;
}

function reh4MatChildPageProductGridFixture(): string
{
    return <<<'HTML'
        <html>
            <body>
                <div id="content">
                    <div class="column">
                        <h1>Ortezy całej kończyny dolnej</h1>
                        <div class="child_pages">
                            <div id="child_page-323786" class="child_page">
                                <div class="child_page-container">
                                    <a class="childpagea" title="4ARMY-SK-11" href="https://www.reh4mat.com/produkt/cala-konczyna-dolna/4army-sk-11/"></a>
                                    <h4>4ARMY-SK-11</h4>
                                    <div class="post_thumb"><img src="https://www.reh4mat.com/uploads/2024/01/sk-11small-427x600.png" alt="4ARMY-SK-11" /></div>
                                </div>
                            </div>
                            <div id="child_page-3692" class="child_page">
                                <div class="child_page-container">
                                    <a class="childpagea" title="Orteza kończyny dolnej AM-KD-AM/2R" href="https://www.reh4mat.com/produkt/kolano-funkcja-pooperacyjna/aparat-szynowo-opaskowy-konczyny-dolnej-z-szynami-2r-am-kd-am2r/"></a>
                                    <h4>AM-KD-AM/2R</h4>
                                    <div class="post_thumb"><img src="https://www.reh4mat.com/uploads/2012/09/AM-KD-AM_2R_m.png" alt="Orteza kończyny dolnej AM-KD-AM/2R" /></div>
                                </div>
                            </div>
                            <div id="child_page-81033" class="child_page">
                                <div class="child_page-container">
                                    <a class="childpagea" title="Dziecięca orteza kolana FIX-KD-14" href="https://www.reh4mat.com/produkt/pediatryczne-cala-konczyna-dolna/dziecieca-orteza-kolana-fix-kd-14/"></a>
                                    <h4>FIX-KD-14</h4>
                                    <div class="post_thumb"><img src="https://www.reh4mat.com/uploads/2020/04/FIX-KD-14.png" alt="Dziecięca orteza kolana FIX-KD-14" /></div>
                                </div>
                            </div>
                            <div id="child_page-117940" class="child_page">
                                <div class="child_page-container">
                                    <a class="childpagea" title="L'attelle de genou FIX-KD-14" href="https://www.reh4mat.com/produkt/pediatryczne-cala-konczyna-dolna/dziecieca-orteza-kolana-fix-kd-14/"></a>
                                    <h4>FIX-KD-14</h4>
                                    <div class="post_thumb"><img src="https://www.reh4mat.com/uploads/2020/04/FIX-KD-14.png" alt="L'attelle de genou FIX-KD-14" /></div>
                                </div>
                            </div>
                        </div>
                        <h2><a name="hkafo-konczyna-dolna" href="https://www.reh4mat.com/produkt/hkafo-konczyna-dolna/" title="HKAFO">HKAFO</a></h2>
                        <div class="child_pages">
                            <div id="child_page-61905" class="child_page">
                                <div class="child_page-container">
                                    <a class="childpagea" title="Orteza kończyny dolnej z gorsetem tułowia COMPLEX PLUS TLSO" href="https://www.reh4mat.com/produkt/hkafo-konczyna-dolna/orteza-konczyny-dolnej-z-gorsetem-tulowia-complex-plus/"></a>
                                    <h4>COMPLEX PLUS TLSO</h4>
                                    <div class="post_thumb"><img src="https://www.reh4mat.com/uploads/2016/04/COMPLEX-plus-miniatura-1.png" alt="Orteza kończyny dolnej z gorsetem tułowia COMPLEX PLUS TLSO" /></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </body>
        </html>
    HTML;
}

function reh4MatProductLinksCategoryFixture(): string
{
    return <<<'HTML'
        <html>
            <body>
                <div id="front-menu-wrapper">
                    <div id="front-menu" class="single-menu">
                        <ul id="menu-gorne-menu" class="menu">
                            <li><a href="https://www.reh4mat.com/produkt/konczyna-dolna/"><div class="main-menu-desc">KOŃCZYNA DOLNA</div></a></li>
                        </ul>
                    </div>
                </div>
                <ul id="menu-glowne-menu" class="menu">
                    <li class="produktycssmenu menu-item menu-item-has-children"><a href="#">Produkty</a>
                        <ul class="sub-menu">
                            <li class="katalogprod menu-item"><a>KATALOG</a></li>
                            <li id="menu-item-320537" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/konczyna-dolna/">Kończyna dolna</a>
                                <ul class="sub-menu">
                                    <li id="menu-item-320549"><a href="https://www.reh4mat.com/produkt/cala-konczyna-dolna/">Ortezy całej kończyny dolnej</a></li>
                                    <li id="menu-item-320548"><a href="https://www.reh4mat.com/wyroby-na-zamowienie/">Wyroby na&nbsp;zamówienie</a></li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </body>
        </html>
    HTML;
}
