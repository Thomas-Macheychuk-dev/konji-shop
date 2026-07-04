<?php

use App\Services\Butterfly\ButterflyCategoryUrlScraper;
use App\Services\Butterfly\ButterflyProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers Butterfly product links from discovered Shoper categories with category context', function (): void {
    $categoryDiscovery = butterflyCategoryDiscoveryFixture();

    Http::fake([
        'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18' => Http::response(butterflyProductListFixture(
            nextUrl: '/pl/c/Magnetyczne-poduszki-ortopedyczne/18/2#box_mainproducts',
            products: [
                ['id' => 78, 'stock' => 155, 'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly', 'slug' => 'Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly'],
            ],
        )),
        'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18/2' => Http::response(butterflyProductListFixture(
            nextUrl: null,
            products: [
                ['id' => 95, 'stock' => 172, 'name' => 'KOMPLET MAGNETYCZNY "FORTE"', 'slug' => 'KOMPLET-MAGNETYCZNY-FORTE-Materac-poduszka-Ort-Butterfly'],
            ],
        )),
    ]);

    $result = app(ButterflyProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($categoryDiscovery);

    expect($result['source'])->toBe('butterfly')
        ->and($result['product_urls'])->toBe([
            'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
            'https://butterfly-mag.com/pl/p/KOMPLET-MAGNETYCZNY-FORTE-Materac-poduszka-Ort-Butterfly/95',
        ])
        ->and($result['products'][0])->toMatchArray([
            'url' => 'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
            'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
            'category_name' => 'Magnetyczne poduszki ortopedyczne',
            'category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
            'top_category_name' => 'Magnetyczne poduszki ortopedyczne',
            'top_category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
            'category_path' => ['Magnetyczne poduszki ortopedyczne'],
        ])
        ->and($result['category_results'][0])->toMatchArray([
            'name' => 'Magnetyczne poduszki ortopedyczne',
            'url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
            'product_count' => 2,
            'pages_scraped' => 2,
        ]);
});

it('ignores product links outside Butterfly Shoper product cards', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19' => Http::response(<<<'HTML'
            <!doctype html>
            <html><body>
                <nav>
                    <a href="https://butterfly-mag.com/pl/p/Menu-product-that-should-not-be-used/999">Menu product</a>
                </nav>

                <div id="box_mainproducts">
                    <div class="products">
                        <div class="product product_view-extended">
                            <a class="prodimage" href="/pl/p/Magnetyczny-stabilizator-kregoslupa-Euromag/74" title="Magnetyczny stabilizator kręgosłupa Euromag"></a>
                            <a class="prodname" href="/pl/p/Magnetyczny-stabilizator-kregoslupa-Euromag/74">
                                <span class="productname">Magnetyczny stabilizator kręgosłupa Euromag</span>
                            </a>
                        </div>
                    </div>
                </div>

                <footer>
                    <a href="/pl/p/Footer-product-that-should-not-be-used/998">Footer product</a>
                </footer>
            </body></html>
        HTML),
    ]);

    $result = app(ButterflyProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19']);

    expect($result['product_urls'])->toBe([
        'https://butterfly-mag.com/pl/p/Magnetyczny-stabilizator-kregoslupa-Euromag/74',
    ]);
});

it('can save Butterfly product link discovery output from a saved category discovery file', function (): void {
    $categoryPath = storage_path('app/scrapers/butterfly/categories-test.json');
    $savedPath = storage_path('app/scrapers/butterfly/product-links-test.json');

    if (! is_dir(dirname($categoryPath))) {
        mkdir(dirname($categoryPath), 0755, true);
    }

    @unlink($categoryPath);
    @unlink($savedPath);

    file_put_contents(
        $categoryPath,
        json_encode(butterflyCategoryDiscoveryFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    Http::fake([
        'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18' => Http::response(butterflyProductListFixture(
            nextUrl: null,
            products: [
                ['id' => 78, 'stock' => 155, 'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly', 'slug' => 'Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly'],
            ],
        )),
    ]);

    $exitCode = Artisan::call('butterfly:product-links', [
        '--categories-from' => 'scrapers/butterfly/categories-test.json',
        '--save' => 'scrapers/butterfly/product-links-test.json',
        '--no-progress' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file($savedPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($savedPath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['product_urls'])->toBe([
        'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
    ]);

    @unlink($categoryPath);
    @unlink($savedPath);
});

it('records failed Butterfly category pages', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18' => Http::response('', 500),
    ]);

    $result = app(ButterflyProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18']);

    expect($result['product_urls'])->toBe([])
        ->and($result['failed_urls'])->toBe([
            'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18' => 'HTTP 500',
        ]);
});

function butterflyCategoryDiscoveryFixture(): array
{
    return [
        'source' => 'butterfly',
        'start_urls' => ['https://butterfly-mag.com'],
        'top_categories' => [
            [
                'source' => 'butterfly',
                'external_category_id' => '18',
                'name' => 'Magnetyczne poduszki ortopedyczne',
                'source_name' => 'Magnetyczne poduszki ortopedyczne',
                'url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
                'slug' => '18',
                'level' => 1,
                'parent_external_category_id' => null,
                'path' => ['Magnetyczne poduszki ortopedyczne'],
                'children' => [],
            ],
        ],
        'categories' => [
            [
                'source' => 'butterfly',
                'external_category_id' => '18',
                'name' => 'Magnetyczne poduszki ortopedyczne',
                'source_name' => 'Magnetyczne poduszki ortopedyczne',
                'url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
                'slug' => '18',
                'level' => 1,
                'parent_external_category_id' => null,
                'path' => ['Magnetyczne poduszki ortopedyczne'],
            ],
        ],
        'category_urls' => ['https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18'],
        'product_category_urls' => ['https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18'],
        'visited_urls' => ['https://butterfly-mag.com'],
        'failed_urls' => [],
    ];
}

function butterflyProductListFixture(?string $nextUrl, array $products): string
{
    $productHtml = '';

    foreach ($products as $product) {
        $productHtml .= <<<HTML
            <div data-product-id="{$product['id']}" data-category="Magnetyczne poduszki ortopedyczne" data-producer="Butterfly Bio Magnetic System" class="product product_view-extended s-grid-3 product-main-wrap">
                <div class="product-inner-wrap">
                    <a class="prodimage f-row" href="/pl/p/{$product['slug']}/{$product['id']}" title="{$product['name']}" rel="dofollow">
                        <span class="img-wrap lazy-load">
                            <img data-src="/environment/cache/images/productGfx_{$product['id']}_300_300.jpg" alt="{$product['name']}" />
                        </span>
                    </a>
                    <a class="prodname f-row" href="/pl/p/{$product['slug']}/{$product['id']}" title="{$product['name']}">
                        <span class="productname">{$product['name']}</span>
                    </a>
                    <form class="basket" action="/pl/basket/add/post" method="post">
                        <input type="hidden" value="{$product['stock']}" name="stock_id">
                    </form>
                </div>
            </div>
        HTML;
    }

    $nextHtml = $nextUrl === null ? '' : <<<HTML
        <ul class="paginator">
            <li class="selected"><span>1</span></li>
            <li class="last"><a href="{$nextUrl}" title="">&raquo;</a></li>
        </ul>
    HTML;

    return <<<HTML
        <!doctype html>
        <html>
            <body class="shop_product_list">
                <nav>
                    <a href="https://butterfly-mag.com/pl/p/Menu-product-that-should-not-be-used/999">Menu product link should be ignored</a>
                </nav>
                <div id="box_mainproducts">
                    <div class="products products_extended viewphot s-row">
                        {$productHtml}
                    </div>
                    {$nextHtml}
                </div>
            </body>
        </html>
    HTML;
}
