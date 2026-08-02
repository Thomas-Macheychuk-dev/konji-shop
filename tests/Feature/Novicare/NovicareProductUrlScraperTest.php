<?php

declare(strict_types=1);

use App\Services\Novicare\NovicareProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers and deduplicates Novicare product links from saved categories', function (): void {
    $kneeUrl = 'https://novicare.pl/produkty/kolano/';
    $kneePageTwoUrl = 'https://novicare.pl/produkty/kolano/page/2/';
    $wristUrl = 'https://novicare.pl/produkty/nadgarstek/';

    Http::fake([
        $kneeUrl => Http::response(novicareProductListFixture([
            ['/produkty/kolano/orteza-stawu-kolanowego-dr-k018/', 'DR-K018 Orteza stawu kolanowego DR-K018'],
            ['/produkty/kolano/orteza-stawu-kolanowego-6155/', '6155 Orteza stawu kolanowego 6155'],
        ], ['/produkty/kolano/page/2/'])),
        $kneePageTwoUrl => Http::response(novicareProductListFixture([
            ['/produkty/kolano/orteza-stawu-kolanowego-6155/', '6155 duplicate'],
            ['/produkty/kolano/orteza-podrzepkowa-6876/', '6876 Orteza podrzepkowa 6876'],
        ])),
        $wristUrl => Http::response(novicareProductListFixture([
            ['/produkty/nadgarstek/orteza-stawu-nadgarstkowego-uni-dr-w056/', 'DR-W056 Orteza nadgarstkowa DR-W056'],
        ])),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'novicare',
            'categories' => [
                [
                    'external_category_id' => 'kolano',
                    'slug' => 'kolano',
                    'name' => 'Kolano',
                    'url' => $kneeUrl,
                    'path' => ['Kolano'],
                    'is_product_category' => true,
                ],
                [
                    'external_category_id' => 'nadgarstek',
                    'slug' => 'nadgarstek',
                    'name' => 'Nadgarstek',
                    'url' => $wristUrl,
                    'path' => ['Nadgarstek'],
                    'is_product_category' => true,
                ],
            ],
            'product_category_urls' => [$kneeUrl, $wristUrl],
            'failed_urls' => [],
        ]);

    expect($result['source'])->toBe('novicare')
        ->and($result['source_categories'])->toHaveCount(2)
        ->and($result['category_results'])->toHaveCount(2)
        ->and($result['visited_urls'])->toBe([$kneeUrl, $kneePageTwoUrl, $wristUrl])
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toHaveCount(4)
        ->and($result['product_urls'])->toContain(
            'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/',
            'https://novicare.pl/produkty/nadgarstek/orteza-stawu-nadgarstkowego-uni-dr-w056/',
        );

    expect($result['category_results'][0])->toMatchArray([
        'external_category_id' => 'kolano',
        'name' => 'Kolano',
        'category_path' => ['Kolano'],
        'pages_scraped' => 2,
        'failed_page_count' => 0,
        'product_count' => 3,
    ]);

    $product = collect($result['products'])
        ->firstWhere('slug', 'orteza-stawu-kolanowego-6155');

    expect($product)->toMatchArray([
        'source' => 'novicare',
        'category_slug' => 'kolano',
        'product_code' => '6155',
        'name' => '6155 Orteza stawu kolanowego 6155',
        'url' => 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/',
        'source_url' => 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/',
        'canonical_url' => 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/',
        'category_urls' => [$kneeUrl],
        'category_paths' => [['Kolano']],
    ])->and($product['external_id'])->toHaveLength(64);
});

it('normalizes Novicare hosts and excludes unrelated category and product links', function (): void {
    $categoryUrl = 'https://novicare.pl/produkty/kolano/';

    Http::fake([
        $categoryUrl => Http::response(<<<'HTML'
            <html><body>
                <a href="https://www.novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/?utm_source=test#details">
                    <h4>6155 Orteza stawu kolanowego 6155</h4>
                </a>
                <a href="/produkty/nadgarstek/orteza-stawu-nadgarstkowego-dr-w056/">Other category</a>
                <a href="/produkty/kolano/">Category</a>
                <a href="https://example.com/produkty/kolano/external/">External</a>
                <a href="/kontakt/">Contact</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['http://www.novicare.pl/produkty/kolano/?source=test']);

    expect($result['source_categories'])->toHaveCount(1)
        ->and($result['source_categories'][0]['url'])->toBe($categoryUrl)
        ->and($result['product_urls'])->toBe([
            'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/',
        ])
        ->and($result['products'][0]['product_code'])->toBe('6155');
});

it('honours the Novicare page limit for controlled smoke runs', function (): void {
    $categoryUrl = 'https://novicare.pl/produkty/kolano/';

    Http::fake([
        $categoryUrl => Http::response(novicareProductListFixture([
            ['/produkty/kolano/orteza-stawu-kolanowego-6155/', '6155 Orteza stawu kolanowego 6155'],
        ], ['/produkty/kolano/page/2/'])),
        'https://novicare.pl/produkty/kolano/page/2/' => Http::response(novicareProductListFixture([
            ['/produkty/kolano/orteza-podrzepkowa-6876/', '6876 Orteza podrzepkowa 6876'],
        ])),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([$categoryUrl], pageLimit: 1);

    expect($result['visited_urls'])->toBe([$categoryUrl])
        ->and($result['product_urls'])->toBe([
            'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/',
        ])
        ->and($result['category_results'][0]['pages_scraped'])->toBe(1);
});

it('records failed Novicare product-list pages after retries are exhausted', function (): void {
    $categoryUrl = 'https://novicare.pl/produkty/kolano/';

    Http::fake([
        $categoryUrl => Http::response('', 503),
    ]);

    $result = app(NovicareProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->withAttempts(1)
        ->scrapeCategories([$categoryUrl]);

    expect($result['product_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([$categoryUrl])
        ->and($result['failed_urls'])->toBe([$categoryUrl => 'HTTP 503'])
        ->and($result['category_results'][0])->toMatchArray([
            'pages_scraped' => 1,
            'failed_page_count' => 1,
            'product_count' => 0,
        ]);
});

it('runs the Novicare product-link command from saved categories and saves JSON', function (): void {
    $categoriesPath = 'scrapers/novicare/tests/categories-'.uniqid('', true).'.json';
    $resultPath = 'scrapers/novicare/tests/product-links-'.uniqid('', true).'.json';
    $absoluteCategoriesPath = storage_path('app/'.$categoriesPath);
    $absoluteResultPath = storage_path('app/'.$resultPath);
    $categoryUrl = 'https://novicare.pl/produkty/kolano/';

    if (! is_dir(dirname($absoluteCategoriesPath))) {
        mkdir(dirname($absoluteCategoriesPath), 0755, true);
    }

    file_put_contents($absoluteCategoriesPath, json_encode([
        'source' => 'novicare',
        'categories' => [[
            'external_category_id' => 'kolano',
            'slug' => 'kolano',
            'name' => 'Kolano',
            'url' => $categoryUrl,
            'path' => ['Kolano'],
            'is_product_category' => true,
        ]],
        'product_category_urls' => [$categoryUrl],
        'failed_urls' => [],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        $categoryUrl => Http::response(novicareProductListFixture([
            ['/produkty/kolano/orteza-stawu-kolanowego-6155/', '6155 Orteza stawu kolanowego 6155'],
            ['/produkty/kolano/orteza-stawu-kolanowego-dr-k018/', 'DR-K018 Orteza stawu kolanowego DR-K018'],
        ])),
        '*' => Http::response('', 404),
    ]);

    try {
        $exitCode = Artisan::call('novicare:product-links', [
            '--categories-from' => $categoriesPath,
            '--save' => $resultPath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Source product categories: 1')
            ->and($output)->toContain('Discovered product URLs: 2')
            ->and($output)->toContain('Kolano: 2 products, 1 page(s), 0 failed page(s)')
            ->and($output)->toContain('Saved product-link discovery result to storage/app/'.$resultPath)
            ->and(is_file($absoluteResultPath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absoluteResultPath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('novicare')
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
function novicareProductListFixture(array $products, array $pagination = []): string
{
    $productHtml = '';

    foreach ($products as [$url, $name]) {
        $productHtml .= sprintf(
            '<div class="wp-block-kadence-infobox">'.
            '<a class="kt-blocks-info-box-link-wrap info-box-link" href="%s">'.
            '<div class="kt-infobox-textcontent"><h4 class="kt-blocks-info-box-title">%s</h4></div>'.
            '</a></div>',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
        );
    }

    $paginationHtml = '';

    foreach ($pagination as $url) {
        $paginationHtml .= sprintf(
            '<a class="page-numbers" href="%s">2</a>',
            htmlspecialchars($url, ENT_QUOTES),
        );
    }

    return '<html><body><main>'.$productHtml.'</main><nav class="navigation pagination">'.
        $paginationHtml.'</nav></body></html>';
}
