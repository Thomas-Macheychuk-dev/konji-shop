<?php

use App\Services\Timago\TimagoProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers Timago product links from discovered categories with category context', function (): void {
    $categoryDiscovery = timagoCategoryDiscoveryFixture();

    Http::fake([
        'https://www.timago.com/pl/rehabilitacja/' => Http::response(timagoProductListFixture(
            nextUrl: '/pl/rehabilitacja/?page=2',
            products: [
                ['name' => 'Wózek inwalidzki elektryczny - Maya', 'slug' => 'wozek-inwalidzki-elektryczny-maya'],
            ],
        )),
        'https://www.timago.com/pl/rehabilitacja/?page=2' => Http::response(timagoProductListFixture(
            nextUrl: null,
            products: [
                ['name' => 'Podpórka aluminiowa - Yola', 'slug' => 'podporka-aluminiowa-yola'],
            ],
        )),
    ]);

    $result = app(TimagoProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($categoryDiscovery);

    expect($result['source'])->toBe('timago')
        ->and($result['product_urls'])->toBe([
            'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
            'https://www.timago.com/pl/podporka-aluminiowa-yola.html',
        ])
        ->and($result['products'][0])->toMatchArray([
            'url' => 'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
            'name' => 'Wózek inwalidzki elektryczny - Maya',
            'category_name' => 'Rehabilitacja',
            'category_url' => 'https://www.timago.com/pl/rehabilitacja/',
            'top_category_name' => 'Rehabilitacja',
            'top_category_url' => 'https://www.timago.com/pl/rehabilitacja/',
            'category_path' => ['Rehabilitacja'],
        ])
        ->and($result['category_results'][0])->toMatchArray([
            'name' => 'Rehabilitacja',
            'url' => 'https://www.timago.com/pl/rehabilitacja/',
            'product_count' => 2,
            'pages_scraped' => 2,
        ]);
});

it('ignores Timago product links from the Oferta menu Polecamy section', function (): void {
    Http::fake([
        'https://www.timago.com/pl/rehabilitacja/' => Http::response(<<<'HTML'
            <!doctype html>
            <html><body>
                <header>
                    <nav>
                        <ul class="nav__level-1"><li>
                            <a href="javascript:void();" title="Oferta">Oferta</a>
                            <ul class="nav__level-2"><li><a href="/pl/rehabilitacja/">Rehabilitacja</a>
                                <div class="nav__level-3"><div class="nav__level-3-hit"><ul>
                                    <li><a href="/pl/menu-product-that-should-not-be-used.html"><h4>Menu product</h4></a></li>
                                </ul></div></div>
                            </li></ul>
                        </li></ul>
                    </nav>
                </header>

                <main>
                    <div class="product__container row">
                        <div class="product__col">
                            <a href="/pl/wozek-inwalidzki-elektryczny-maya.html" class="product__item" title="Wózek inwalidzki elektryczny - Maya">
                                <h3>Wózek inwalidzki elektryczny - Maya</h3>
                            </a>
                        </div>
                    </div>
                </main>

                <footer><a href="/pl/footer-product-that-should-not-be-used.html">Footer product</a></footer>
            </body></html>
        HTML),
    ]);

    $result = app(TimagoProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://www.timago.com/pl/rehabilitacja/']);

    expect($result['product_urls'])->toBe([
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
    ]);
});

it('can save Timago product link discovery output from a saved category discovery file', function (): void {
    $categoryPath = storage_path('app/scrapers/timago/categories-test.json');
    $savedPath = storage_path('app/scrapers/timago/product-links-test.json');

    if (! is_dir(dirname($categoryPath))) {
        mkdir(dirname($categoryPath), 0755, true);
    }

    @unlink($categoryPath);
    @unlink($savedPath);

    file_put_contents(
        $categoryPath,
        json_encode(timagoCategoryDiscoveryFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    );

    Http::fake([
        'https://www.timago.com/pl/rehabilitacja/' => Http::response(timagoProductListFixture(
            nextUrl: null,
            products: [
                ['name' => 'Wózek inwalidzki elektryczny - Maya', 'slug' => 'wozek-inwalidzki-elektryczny-maya'],
            ],
        )),
    ]);

    $exitCode = Artisan::call('timago:product-links', [
        '--categories-from' => 'scrapers/timago/categories-test.json',
        '--save' => 'scrapers/timago/product-links-test.json',
        '--no-progress' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file($savedPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($savedPath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['product_urls'])->toBe([
        'https://www.timago.com/pl/wozek-inwalidzki-elektryczny-maya.html',
    ]);

    @unlink($categoryPath);
    @unlink($savedPath);
});

function timagoCategoryDiscoveryFixture(): array
{
    return [
        'source' => 'timago',
        'start_urls' => ['https://www.timago.com'],
        'top_categories' => [],
        'categories' => [
            [
                'source' => 'timago',
                'external_category_id' => 'rehabilitacja',
                'name' => 'Rehabilitacja',
                'source_name' => 'Rehabilitacja',
                'url' => 'https://www.timago.com/pl/rehabilitacja/',
                'slug' => 'rehabilitacja',
                'level' => 1,
                'parent_external_category_id' => null,
                'path' => ['Rehabilitacja'],
            ],
        ],
        'category_urls' => ['https://www.timago.com/pl/rehabilitacja/'],
        'product_category_urls' => ['https://www.timago.com/pl/rehabilitacja/'],
        'visited_urls' => ['https://www.timago.com'],
        'failed_urls' => [],
    ];
}

/**
 * @param  array<int, array{name: string, slug: string}>  $products
 */
function timagoProductListFixture(?string $nextUrl, array $products): string
{
    $productHtml = '';

    foreach ($products as $product) {
        $productHtml .= '<div class="product__col"><a class="product__item" href="/pl/'.$product['slug'].'.html" title="'.htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8').'"><figure></figure><h3>'.htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8').'</h3></a></div>';
    }

    $nextHtml = $nextUrl !== null
        ? '<div class="pagination"><a class="next" href="'.$nextUrl.'">następna</a></div>'
        : '';

    return '<!doctype html><html><body><header><nav><a href="/pl/menu-product.html">Menu</a></nav></header><main><section><div class="product__container row">'.$productHtml.'</div>'.$nextHtml.'</section></main></body></html>';
}
