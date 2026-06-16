<?php

use App\Services\TwojaPeruka\TwojaPerukaCategoryScraper;
use App\Services\TwojaPeruka\TwojaPerukaProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('scrapes product links from a TwojaPeruka category and follows pagination', function (): void {
    Http::fake([
        'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48' => Http::response(twojaPerukaProductListFixture(
            nextUrl: '/pl/c/Peruki-syntetyczne/48/2',
            products: [
                ['/peruka-damska-laurel', 'Peruka syntetyczna, kolor brąz, długie włosy przedziałek Lace Front - LAUREL'],
                ['/kind-18-22-12-peruka-syntetyczna', 'Peruka syntetyczna KIND # 18/22/12'],
            ],
        )),
        'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48/2' => Http::response(twojaPerukaProductListFixture(
            nextUrl: null,
            products: [
                ['/kind-18-22-12-peruka-syntetyczna', 'Peruka syntetyczna KIND # 18/22/12'],
                ['/lovely-14-16-6r-peruka-syntetyczna', 'Peruka syntetyczna LOVELY # 14/16/6R'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductUrlScraper::class)->scrapeCategories([
        'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48',
    ]);

    expect($result['product_urls'])->toBe([
        'https://twojaperuka.pl/peruka-damska-laurel',
        'https://twojaperuka.pl/kind-18-22-12-peruka-syntetyczna',
        'https://twojaperuka.pl/lovely-14-16-6r-peruka-syntetyczna',
    ])
        ->and($result['visited_urls'])->toBe([
            'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48',
            'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48/2',
        ])
        ->and($result['category_results'][0]['product_count'])->toBe(3)
        ->and($result['category_results'][0]['pages_scraped'])->toBe(2)
        ->and($result['products'][0]['name'])->toBe('Peruka syntetyczna, kolor brąz, długie włosy przedziałek Lace Front - LAUREL');
});

it('scrapes product links from the allowed category hierarchy', function (): void {
    Http::fake([
        TwojaPerukaCategoryScraper::DEFAULT_CATEGORY_URL => Http::response(twojaPerukaProductLinksCategoryFixture()),
        'https://twojaperuka.pl/pl/c/Kucyki-doczepiane/42' => Http::response(twojaPerukaProductListFixture(
            nextUrl: null,
            products: [
                ['/kucyk-doczepiany-naturalny', 'Kucyk doczepiany naturalny'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductUrlScraper::class)->scrape(
        [TwojaPerukaCategoryScraper::DEFAULT_CATEGORY_URL],
        pageLimit: 1,
        categoryLimit: 1,
    );

    expect($result['product_urls'])->toBe([
        'https://twojaperuka.pl/kucyk-doczepiany-naturalny',
    ])
        ->and($result['source_categories'][0]['name'])->toBe('Kucyki doczepiane')
        ->and($result['source_categories'][0]['path'])->toBe([
            'Zagęszczanie włosów',
            'Kucyki doczepiane',
        ])
        ->and($result['products'][0]['top_category_name'])->toBe('Zagęszczanie włosów');
});

it('can use a saved TwojaPeruka category discovery JSON file', function (): void {
    Storage::disk('local')->put('scrapers/twojaperuka/categories-test.json', json_encode([
        'source' => 'twojaperuka',
        'top_categories' => [],
        'categories' => [
            [
                'name' => 'Toppery',
                'url' => 'https://twojaperuka.pl/pl/c/Toppery/35',
                'level' => 1,
                'path' => ['Toppery'],
            ],
            [
                'name' => 'Toppery syntetyczne',
                'url' => 'https://twojaperuka.pl/pl/c/Toppery-syntetyczne/36',
                'level' => 2,
                'path' => ['Toppery', 'Toppery syntetyczne'],
            ],
        ],
        'product_category_urls' => [
            'https://twojaperuka.pl/pl/c/Toppery-syntetyczne/36',
        ],
        'visited_urls' => ['https://twojaperuka.pl/peruki'],
        'failed_urls' => [],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    Http::fake([
        'https://twojaperuka.pl/pl/c/Toppery-syntetyczne/36' => Http::response(twojaPerukaProductListFixture(
            nextUrl: null,
            products: [
                ['/topper-syntetyczny-example', 'Topper syntetyczny Example'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('twojaperuka:product-links', [
        '--categories-from' => 'scrapers/twojaperuka/categories-test.json',
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['product_urls'])->toBe([
        'https://twojaperuka.pl/topper-syntetyczny-example',
    ])
        ->and($decoded['products'][0]['category_path'])->toBe([
            'Toppery',
            'Toppery syntetyczne',
        ]);
});

it('records failed TwojaPeruka product category page requests', function (): void {
    Http::fake([
        'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductUrlScraper::class)->scrapeCategories([
        'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48',
    ]);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48' => 'HTTP 500',
        ]);
});

/**
 * @param  array<int, array{0: string, 1: string}>  $products
 */
function twojaPerukaProductListFixture(?string $nextUrl, array $products): string
{
    $productTiles = '';

    foreach ($products as [$url, $name]) {
        $productTiles .= <<<HTML
            <product-tile>
                <div class="product-tile__content">
                    <a href="{$url}" title="{$name}">
                        <img src="/userdata/example.webp" alt="{$name}">
                    </a>
                </div>
                <div class="product-tile__footer">
                    <a aria-label="Zobacz produkt {$name}" href="{$url}" class="btn btn_s btn_primary">
                        Zobacz produkt
                    </a>
                    <a href="/basket/add/123/1">Do koszyka</a>
                </div>
            </product-tile>
        HTML;
    }

    $nextLink = $nextUrl === null
        ? ''
        : <<<HTML
            <a href="{$nextUrl}" class="btn pagination__button pagination__button_with-text" aria-label="Następne produkty">
                <span class="pagination__button-text">Następna</span>
            </a>
        HTML;

    return <<<HTML
        <html>
            <head>
                {$nextLink}
            </head>
            <body>
                <div class="product-list">
                    {$productTiles}
                </div>
                <footer class="product-list__footer">
                    <div class="pagination pagination_bottom">
                        {$nextLink}
                    </div>
                </footer>
            </body>
        </html>
    HTML;
}

function twojaPerukaProductLinksCategoryFixture(): string
{
    return <<<'HTML'
        <html>
            <body>
                <div class="sft-sidebar-menu" id="sft-sidebar-menu-549">
                    <ul class="sft-menu sft-category-menu sft-category-menu--level-1">
                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-41">
                            <div class="sft-category-link">
                                <a href="/pl/c/Zageszczanie-wlosow/41" title="Zagęszczanie włosów"><p class="head">Zagęszczanie włosów</p></a>
                            </div>
                            <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-2">
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-42">
                                    <div class="sft-category-link">
                                        <a href="/pl/c/Kucyki-doczepiane/42" title="Kucyki doczepiane"><p class="head">Kucyki doczepiane</p></a>
                                    </div>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </body>
        </html>
    HTML;
}
