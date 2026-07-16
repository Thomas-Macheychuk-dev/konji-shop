<?php

use App\Services\Vaya\VayaProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers paginated Vaya product links from saved leaf category records', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133' => Http::response(vayaProductListFixture(
            products: [
                ['/pl/p/Zelowe-poduszki-na-zrogowacenia/895', 'Żelowe poduszki na zrogowacenia'],
                ['/pl/p/Oslona-malego-palca/392', 'Osłona małego palca'],
            ],
            pagination: ['/pl/c/Wkladki-na-bunionette/133/2'],
        )),
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133/2' => Http::response(vayaProductListFixture([
            ['/pl/p/Oslona-malego-palca/392', 'Osłona małego palca'],
            ['/pl/p/Separujacy-klin-miedzypalcowy/377', 'Separujący klin międzypalcowy'],
        ])),
        'https://www.vaya.com.pl/produkty-scholl' => Http::response(vayaProductListFixture(
            products: [
                ['/pl/p/Scholl-wkladki-damskie/901', 'Scholl wkładki damskie'],
            ],
            pagination: ['/produkty-scholl/2'],
        )),
        'https://www.vaya.com.pl/produkty-scholl/2' => Http::response(vayaProductListFixture([
            ['/pl/p/Scholl-pilnik-do-stop/902', 'Scholl pilnik do stóp'],
        ])),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'vaya',
            'categories' => [
                [
                    'external_category_id' => '14',
                    'name' => 'Wkładki ortopedyczne',
                    'url' => 'https://www.vaya.com.pl/wkladki-medyczne-do-butow',
                    'path' => ['Wkładki ortopedyczne'],
                    'level' => 1,
                    'is_product_category' => false,
                ],
                [
                    'external_category_id' => '133',
                    'name' => 'Wkładki na bunionette',
                    'url' => 'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
                    'path' => ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
                    'level' => 2,
                    'is_product_category' => true,
                ],
                [
                    'external_category_id' => '38-1',
                    'name' => 'Scholl',
                    'url' => 'https://www.vaya.com.pl/produkty-scholl',
                    'path' => ['Scholl'],
                    'level' => 1,
                    'is_product_category' => true,
                ],
            ],
            'product_category_urls' => [
                'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
                'https://www.vaya.com.pl/produkty-scholl',
            ],
            'failed_urls' => [],
        ]);

    expect($result['source'])->toBe('vaya')
        ->and($result['source_categories'])->toHaveCount(2)
        ->and($result['category_results'])->toHaveCount(2)
        ->and($result['visited_urls'])->toBe([
            'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
            'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133/2',
            'https://www.vaya.com.pl/produkty-scholl',
            'https://www.vaya.com.pl/produkty-scholl/2',
        ])
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toHaveCount(5)
        ->and($result['product_urls'])->toContain(
            'https://www.vaya.com.pl/pl/p/Zelowe-poduszki-na-zrogowacenia/895',
            'https://www.vaya.com.pl/pl/p/Scholl-pilnik-do-stop/902',
        );

    expect($result['category_results'][0])->toMatchArray([
        'external_category_id' => '133',
        'name' => 'Wkładki na bunionette',
        'category_path' => ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
        'pages_scraped' => 2,
        'failed_page_count' => 0,
        'product_count' => 3,
    ])->and($result['category_results'][1])->toMatchArray([
        'name' => 'Scholl',
        'category_path' => ['Scholl'],
        'pages_scraped' => 2,
        'failed_page_count' => 0,
        'product_count' => 2,
    ]);

    $product = collect($result['products'])->firstWhere('external_id', '392');

    expect($product)->toMatchArray([
        'source' => 'vaya',
        'external_id' => '392',
        'slug' => 'Oslona-malego-palca',
        'name' => 'Osłona małego palca',
        'category_urls' => ['https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133'],
        'category_paths' => [['Wkładki ortopedyczne', 'Wkładki na bunionette']],
    ]);
});

it('normalizes Vaya hosts and excludes unrelated product and pagination links', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/produkty-scholl' => Http::response(<<<'HTML'
            <html><head><link rel="next" href="https://vaya.com.pl/produkty-scholl/2?utm_source=test"></head><body>
                <div class="products">
                    <div class="product product-main-wrap" data-product-id="901">
                        <a class="prodname" href="https://vaya.com.pl/pl/p/Scholl-wkladki-damskie/901?utm_source=test#details">
                            <span class="productname"> Scholl   wkładki damskie </span>
                        </a>
                    </div>
                    <a href="https://example.com/pl/p/External/999">External</a>
                    <a href="/pl/blog">Blog</a>
                </div>
                <div class="paginator">
                    <a href="/produkty-scholl/1/default/9">Sortowanie</a>
                    <a href="/produkty-scholl/2">2</a>
                    <a href="/pl/c/Durex/39/2">Other category</a>
                </div>
            </body></html>
            HTML),
        'https://www.vaya.com.pl/produkty-scholl/2' => Http::response(vayaProductListFixture([
            ['/pl/p/Scholl-pilnik/902', 'Scholl pilnik'],
        ])),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['http://vaya.com.pl/produkty-scholl?source=test']);

    expect($result['source_categories'])->toHaveCount(1)
        ->and($result['source_categories'][0]['url'])->toBe('https://www.vaya.com.pl/produkty-scholl')
        ->and($result['visited_urls'])->toBe([
            'https://www.vaya.com.pl/produkty-scholl',
            'https://www.vaya.com.pl/produkty-scholl/2',
        ])
        ->and($result['product_urls'])->toBe([
            'https://www.vaya.com.pl/pl/p/Scholl-pilnik/902',
            'https://www.vaya.com.pl/pl/p/Scholl-wkladki-damskie/901',
        ]);
});

it('records failed Vaya product-list pages after retries are exhausted', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/pl/c/Termometry/112' => Http::response('', 503),
    ]);

    $result = app(VayaProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(1, 0)
        ->scrapeCategories(['https://www.vaya.com.pl/pl/c/Termometry/112']);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://www.vaya.com.pl/pl/c/Termometry/112',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.vaya.com.pl/pl/c/Termometry/112' => 'HTTP 503',
        ])
        ->and($result['category_results'][0])->toMatchArray([
            'pages_scraped' => 1,
            'failed_page_count' => 1,
            'product_count' => 0,
        ]);
});

it('runs the Vaya product-link command from saved categories and saves JSON', function (): void {
    $categoriesPath = 'scrapers/vaya/tests/categories-'.uniqid('', true).'.json';
    $resultPath = 'scrapers/vaya/tests/product-links-'.uniqid('', true).'.json';
    $absoluteCategoriesPath = storage_path('app/'.$categoriesPath);
    $absoluteResultPath = storage_path('app/'.$resultPath);

    if (! is_dir(dirname($absoluteCategoriesPath))) {
        mkdir(dirname($absoluteCategoriesPath), 0755, true);
    }

    file_put_contents($absoluteCategoriesPath, json_encode([
        'source' => 'vaya',
        'categories' => [
            [
                'external_category_id' => '133',
                'name' => 'Wkładki na bunionette',
                'url' => 'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
                'path' => ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
                'level' => 2,
                'is_product_category' => true,
            ],
        ],
        'product_category_urls' => [
            'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
        ],
        'failed_urls' => [],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133' => Http::response(vayaProductListFixture([
            ['/pl/p/Zelowe-poduszki-na-zrogowacenia/895', 'Żelowe poduszki na zrogowacenia'],
            ['/pl/p/Oslona-malego-palca/392', 'Osłona małego palca'],
        ])),
        '*' => Http::response('', 404),
    ]);

    try {
        $exitCode = Artisan::call('vaya:product-links', [
            '--categories-from' => $categoriesPath,
            '--save' => $resultPath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Source product categories: 1')
            ->and($output)->toContain('Discovered product URLs: 2')
            ->and($output)->toContain('Wkładki ortopedyczne > Wkładki na bunionette: 2 products, 1 page(s), 0 failed page(s)')
            ->and($output)->toContain('Saved product-link discovery result to storage/app/'.$resultPath)
            ->and(is_file($absoluteResultPath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absoluteResultPath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('vaya')
            ->and($saved['source_categories'])->toHaveCount(1)
            ->and($saved['product_urls'])->toHaveCount(2)
            ->and($saved['products'])->toHaveCount(2);
    } finally {
        @unlink($absoluteCategoriesPath);
        @unlink($absoluteResultPath);
    }
});

/**
 * @param  array<int, array{0: string, 1: string}>  $products
 * @param  array<int, string>  $pagination
 */
function vayaProductListFixture(array $products, array $pagination = []): string
{
    $productHtml = '';

    foreach ($products as [$url, $name]) {
        $productHtml .= sprintf(
            '<div class="product s-grid-3 product-main-wrap" data-product-id="%s">'.
            '<div class="product-inner-wrap">'.
            '<a class="prodimage f-row" href="%s" title="%s"></a>'.
            '<a class="prodname f-row" href="%s" title="%s"><span class="productname">%s</span></a>'.
            '</div></div>',
            basename($url),
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
        );
    }

    $paginationHtml = '';

    foreach ($pagination as $url) {
        $paginationHtml .= sprintf('<a href="%s">2</a>', htmlspecialchars($url, ENT_QUOTES));
    }

    return '<html><body><div class="row paginator">'.$paginationHtml.'</div>'.
        '<div class="products viewphot s-row">'.$productHtml.'</div></body></html>';
}
