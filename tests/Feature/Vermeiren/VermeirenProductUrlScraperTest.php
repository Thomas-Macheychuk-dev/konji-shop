<?php

declare(strict_types=1);

use App\Services\Vermeiren\VermeirenProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers and deduplicates Vermeiren product links from saved categories', function (): void {
    $manualUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroupW%C3%B3zki%20manualneSubGroupSpecjalne';
    $childUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroup%C5%9Awiat%20dzieckaSubGroupFoteliki';

    Http::fake([
        $manualUrl => Http::response(vermeirenProductListFixture([
            [
                '/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupSpecjalneSelectedInovys II Evo',
                'Inovys II EVO',
                '',
            ],
            [
                'https://vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupSpecjalneSelected9300',
                '',
                '9300',
            ],
        ])),
        $childUrl => Http::response(vermeirenProductListFixture([
            [
                '/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupŚwiat dzieckaSubGroupFotelikiSelectedGemini 2',
                '',
                '',
            ],
            [
                '/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupWózki manualneSubGroupSpecjalneSelectedInovys II Evo',
                'Inovys II EVO duplicate',
                '',
            ],
        ])),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'vermeiren',
            'categories' => [
                [
                    'external_category_id' => 'manual-special',
                    'name' => 'Specjalne',
                    'url' => $manualUrl,
                    'path' => ['Wózki manualne', 'Specjalne'],
                    'is_product_category' => true,
                ],
                [
                    'external_category_id' => 'child-seats',
                    'name' => 'Foteliki',
                    'url' => $childUrl,
                    'path' => ['Świat dziecka', 'Foteliki'],
                    'is_product_category' => true,
                ],
            ],
            'product_category_urls' => [$manualUrl, $childUrl],
            'failed_urls' => [],
        ]);

    expect($result['source'])->toBe('vermeiren')
        ->and($result['source_categories'])->toHaveCount(2)
        ->and($result['category_results'])->toHaveCount(2)
        ->and($result['visited_urls'])->toBe([$manualUrl, $childUrl])
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toHaveCount(3);

    expect($result['category_results'][0])->toMatchArray([
        'external_category_id' => 'manual-special',
        'category_path' => ['Wózki manualne', 'Specjalne'],
        'pages_scraped' => 1,
        'failed_page_count' => 0,
        'product_count' => 2,
    ]);

    $inovys = collect($result['products'])->firstWhere('selected_name', 'Inovys II Evo');

    expect($inovys)->toMatchArray([
        'source' => 'vermeiren',
        'product_group' => 'Wózki manualne',
        'sub_group' => 'Specjalne',
        'selected_name' => 'Inovys II Evo',
        'name' => 'Inovys II EVO',
        'category_urls' => [$manualUrl, $childUrl],
        'category_paths' => [
            ['Wózki manualne', 'Specjalne'],
            ['Świat dziecka', 'Foteliki'],
        ],
    ])->and($inovys['external_id'])->toHaveLength(64);

    $gemini = collect($result['products'])->firstWhere('selected_name', 'Gemini 2');

    expect($gemini['name'])->toBe('Gemini 2');
});

it('ignores external and non-product Vermeiren links', function (): void {
    $categoryUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroupSkuterySubGroup';

    Http::fake([
        $categoryUrl => Http::response(<<<'HTML'
            <html><body>
                <a href="https://example.com/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedExternal">External</a>
                <a href="mainproduct.xsp?CountryPLPLProductGroupSkuterySubGroup">Category</a>
                <a href="detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedEon#details"><img alt="Eon"></a>
                <a href="javascript:void(0)">Ignore</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['http://vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroupSkuterySubGroup']);

    expect($result['product_urls'])->toBe([
        'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedEon',
    ])->and($result['products'][0])->toMatchArray([
        'name' => 'Eon',
        'product_group' => 'Skutery',
        'sub_group' => '',
        'selected_name' => 'Eon',
    ]);
});

it('records failed Vermeiren product-list pages after retries are exhausted', function (): void {
    $categoryUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroup%C5%81%C3%B3%C5%BCkaSubGroup';

    Http::fake([
        $categoryUrl => Http::response('', 503),
    ]);

    $result = app(VermeirenProductUrlScraper::class)
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

it('runs the Vermeiren product-link command from saved categories and saves JSON', function (): void {
    $categoriesPath = 'scrapers/vermeiren/tests/categories-'.uniqid('', true).'.json';
    $resultPath = 'scrapers/vermeiren/tests/product-links-'.uniqid('', true).'.json';
    $absoluteCategoriesPath = storage_path('app/'.$categoriesPath);
    $absoluteResultPath = storage_path('app/'.$resultPath);
    $categoryUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct.xsp?CountryPLPLProductGroupSkuterySubGroup';

    if (! is_dir(dirname($absoluteCategoriesPath))) {
        mkdir(dirname($absoluteCategoriesPath), 0755, true);
    }

    file_put_contents($absoluteCategoriesPath, json_encode([
        'source' => 'vermeiren',
        'categories' => [[
            'external_category_id' => 'skutery',
            'name' => 'Skutery',
            'url' => $categoryUrl,
            'path' => ['Skutery'],
            'is_product_category' => true,
        ]],
        'product_category_urls' => [$categoryUrl],
        'failed_urls' => [],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        $categoryUrl => Http::response(vermeirenProductListFixture([
            [
                '/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedEon',
                'Eon',
                '',
            ],
            [
                '/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedSync',
                'Sync',
                '',
            ],
        ])),
        '*' => Http::response('', 404),
    ]);

    try {
        $exitCode = Artisan::call('vermeiren:product-links', [
            '--categories-from' => $categoriesPath,
            '--save' => $resultPath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
            '--insecure' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('TLS certificate verification is disabled for this Vermeiren run.')
            ->and($output)->toContain('Source product categories: 1')
            ->and($output)->toContain('Discovered product URLs: 2')
            ->and($output)->toContain('Skutery: 2 products, 1 page(s), 0 failed page(s)')
            ->and($output)->toContain('Saved product-link discovery result to storage/app/'.$resultPath)
            ->and(is_file($absoluteResultPath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absoluteResultPath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('vermeiren')
            ->and($saved['source_categories'])->toHaveCount(1)
            ->and($saved['product_urls'])->toHaveCount(2)
            ->and($saved['products'])->toHaveCount(2);
    } finally {
        @unlink($absoluteCategoriesPath);
        @unlink($absoluteResultPath);
    }
});

/**
 * @param  array<int, array{0: string, 1: string, 2: string}>  $products
 */
function vermeirenProductListFixture(array $products): string
{
    $html = '<html><body><div class="products">';

    foreach ($products as [$url, $name, $imageAlt]) {
        $html .= '<article class="product"><a href="'.htmlspecialchars($url, ENT_QUOTES).'">';

        if ($imageAlt !== '') {
            $html .= '<img alt="'.htmlspecialchars($imageAlt, ENT_QUOTES).'">';
        }

        if ($name !== '') {
            $html .= '<span>'.htmlspecialchars($name, ENT_QUOTES).'</span>';
        }

        $html .= '</a></article>';
    }

    return $html.'</div></body></html>';
}

it('recursively discovers Neoflex sub-subcategories and their products', function (): void {
    $parentUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct_sub.xsp?CountryPLPLProductGroupStabilizacjaSubGroupNeoflex';
    $childUrl = 'https://www.vermeiren.pl/web/web.nsf/mainproduct_subsub.xsp?CountryPLPLProductGroupStabilizacjaSubGroupNeoflexSubSubGroupG%C3%B3rne%20cz%C4%99%C5%9Bci%20cia%C5%82a';
    $productUrl = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupStabilizacjaSubGroupNeoflexSubSubGroupG%C3%B3rne%20cz%C4%99%C5%9Bci%20cia%C5%82aSelectedU01';

    Http::fake([
        $parentUrl => Http::response(<<<'HTML'
            <html><body>
                <a href="//www.vermeiren.pl/web/web.nsf/mainproduct_subsub.xsp?CountryPLPLProductGroupStabilizacjaSubGroupNeoflexSubSubGroupGórne części ciała">
                    <img alt="Górne części ciała">
                </a>
            </body></html>
            HTML),
        $childUrl => Http::response(<<<'HTML'
            <html><body>
                <a href="detailproduct.xsp?CountryPLPLProductGroupStabilizacjaSubGroupNeoflexSubSubGroupGórne części ciałaSelectedU01">Pas piersiowy U01</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'vermeiren',
            'categories' => [[
                'external_category_id' => 'stabilizacja-neoflex',
                'name' => 'Neoflex',
                'url' => $parentUrl,
                'path' => ['Stabilizacja', 'Neoflex'],
                'level' => 2,
                'top_category_external_id' => 'stabilizacja',
                'top_category_name' => 'Stabilizacja',
                'is_product_category' => true,
            ]],
            'product_category_urls' => [$parentUrl],
            'failed_urls' => [],
        ]);

    expect($result['source_categories'])->toHaveCount(2)
        ->and($result['category_results'])->toHaveCount(2)
        ->and($result['visited_urls'])->toBe([$parentUrl, $childUrl])
        ->and($result['product_urls'])->toBe([$productUrl])
        ->and($result['category_results'][0]['child_category_count'])->toBe(1)
        ->and($result['category_results'][1]['category_path'])->toBe([
            'Stabilizacja',
            'Neoflex',
            'Górne części ciała',
        ]);

    expect($result['products'][0])->toMatchArray([
        'name' => 'Pas piersiowy U01',
        'product_group' => 'Stabilizacja',
        'sub_group' => 'Neoflex',
        'sub_sub_group' => 'Górne części ciała',
        'selected_name' => 'U01',
        'category_paths' => [[
            'Stabilizacja',
            'Neoflex',
            'Górne części ciała',
        ]],
    ]);
});
