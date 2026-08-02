<?php

declare(strict_types=1);

use App\Services\Microlife\MicrolifeProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers and deduplicates consumer and professional Microlife product links', function (): void {
    $consumerCategory = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne';
    $professionalCategory = 'https://www.microlife.pl/produkty-profesjonalne/watchbp-office';
    $directProductCategory = 'https://www.microlife.pl/produkty/testpage/mankiet-m-l';

    Http::fake([
        $consumerCategory => Http::response(microlifeProductListFixture([
            ['/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-a7-touch', 'BP A7 Touch'],
            ['/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b6-connect', 'BP B6 Connect'],
            ['/produkty/cisnienie-krwi/nadgarstkowe/bp-w3-comfort', 'Other category product'],
        ])),
        $professionalCategory => Http::response(microlifeProductListFixture([
            ['/professional-products/watchbp-office/watchbp-office-vascular', 'WatchBP Office Vascular'],
            ['/produkty-profesjonalne/watchbp-office/watchbp-office-afib', 'WatchBP Office AFIB'],
            ['/produkty-profesjonalne/walidacje-i-badania-kliniczne/krogager-2017', 'Clinical study'],
        ])),
        $directProductCategory => Http::response(<<<'HTML'
            <html><body>
                <main class="product-detail">
                    <div class="product-number-type">Mankiet M-L</div>
                    <a class="button">Kup teraz</a>
                    <h2>Instrukcja obsługi</h2>
                </main>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MicrolifeProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'microlife',
            'categories' => [
                microlifeTestCategory(
                    'consumer:cisnienie-krwi/cisnieniomierze-automatyczne',
                    'consumer',
                    'cisnieniomierze-automatyczne',
                    'Ciśnieniomierze automatyczne',
                    $consumerCategory,
                    ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
                ),
                microlifeTestCategory(
                    'professional:watchbp-office',
                    'professional',
                    'watchbp-office',
                    'WatchBP Office',
                    $professionalCategory,
                    ['WatchBP Office'],
                ),
                microlifeTestCategory(
                    'consumer:testpage/mankiet-m-l',
                    'consumer',
                    'mankiet-m-l',
                    'Mankiet M-L',
                    $directProductCategory,
                    ['Części zamienne', 'Mankiet M-L'],
                ),
            ],
        ]);

    expect($result['source'])->toBe('microlife')
        ->and($result['source_categories'])->toHaveCount(3)
        ->and($result['category_results'])->toHaveCount(3)
        ->and($result['visited_urls'])->toBe([
            $consumerCategory,
            $professionalCategory,
            $directProductCategory,
        ])
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toHaveCount(5)
        ->and($result['product_urls'])->toContain(
            'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-a7-touch',
            'https://www.microlife.pl/produkty-profesjonalne/watchbp-office/watchbp-office-vascular',
            $directProductCategory,
        )
        ->and($result['product_urls'])->not->toContain(
            'https://www.microlife.pl/produkty/cisnienie-krwi/nadgarstkowe/bp-w3-comfort',
            'https://www.microlife.pl/produkty-profesjonalne/walidacje-i-badania-kliniczne/krogager-2017',
        );

    expect($result['category_results'][0])->toMatchArray([
        'catalogue_type' => 'consumer',
        'name' => 'Ciśnieniomierze automatyczne',
        'product_count' => 2,
        'failed_page_count' => 0,
    ])->and($result['category_results'][1])->toMatchArray([
        'catalogue_type' => 'professional',
        'name' => 'WatchBP Office',
        'product_count' => 2,
    ])->and($result['category_results'][2])->toMatchArray([
        'name' => 'Mankiet M-L',
        'product_count' => 1,
    ]);

    $vascular = collect($result['products'])
        ->firstWhere('slug', 'watchbp-office-vascular');

    expect($vascular)->toMatchArray([
        'source' => 'microlife',
        'catalogue_type' => 'professional',
        'name' => 'WatchBP Office Vascular',
        'url' => 'https://www.microlife.pl/produkty-profesjonalne/watchbp-office/watchbp-office-vascular',
        'source_url' => 'https://www.microlife.pl/produkty-profesjonalne/watchbp-office/watchbp-office-vascular',
        'canonical_url' => 'https://www.microlife.pl/produkty-profesjonalne/watchbp-office/watchbp-office-vascular',
        'category_external_id' => 'professional:watchbp-office',
        'category_urls' => [$professionalCategory],
        'category_paths' => [['WatchBP Office']],
    ])->and($vascular['external_id'])->toHaveLength(64);
});

it('filters a saved Microlife category discovery by catalogue branch', function (): void {
    $consumerCategory = 'https://www.microlife.pl/produkty/temperatura/termometry-elektroniczne';
    $professionalCategory = 'https://www.microlife.pl/produkty-profesjonalne/watchbp-o3';

    Http::fake([
        $consumerCategory => Http::response(microlifeProductListFixture([
            ['/produkty/temperatura/termometry-elektroniczne/mt-700', 'MT 700'],
        ])),
        $professionalCategory => Http::response(microlifeProductListFixture([
            ['/produkty-profesjonalne/watchbp-o3/watchbp-o3-ambulatory-1', 'WatchBP O3 Ambulatory'],
        ])),
        '*' => Http::response('', 404),
    ]);

    $discovery = [
        'categories' => [
            microlifeTestCategory(
                'consumer:temperatura/termometry-elektroniczne',
                'consumer',
                'termometry-elektroniczne',
                'Termometry elektroniczne',
                $consumerCategory,
                ['Temperatura', 'Termometry elektroniczne'],
            ),
            microlifeTestCategory(
                'professional:watchbp-o3',
                'professional',
                'watchbp-o3',
                'WatchBP O3',
                $professionalCategory,
                ['WatchBP O3'],
            ),
        ],
    ];

    $result = app(MicrolifeProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($discovery, catalogueType: 'professional');

    expect($result['source_categories'])->toHaveCount(1)
        ->and($result['source_categories'][0]['catalogue_type'])->toBe('professional')
        ->and($result['visited_urls'])->toBe([$professionalCategory])
        ->and($result['product_urls'])->toBe([
            'https://www.microlife.pl/produkty-profesjonalne/watchbp-o3/watchbp-o3-ambulatory-1',
        ]);
});

it('discovers direct baby products from a parent category while excluding its nested category', function (): void {
    $babyCategory = 'https://www.microlife.pl/produkty/opieka-nad-dzieckiem';
    $accessoriesCategory = 'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/akcesoria';

    Http::fake([
        $babyCategory => Http::response(microlifeProductListFixture([
            ['/produkty/opieka-nad-dzieckiem/bc-50', 'BC 50'],
            ['/produkty/opieka-nad-dzieckiem/bc-100-soft', 'BC 100 Soft'],
            ['/produkty/opieka-nad-dzieckiem/akcesoria', 'Akcesoria'],
        ])),
        '*' => Http::response('', 404),
    ]);

    $result = app(MicrolifeProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'categories' => [
                microlifeTestCategory(
                    'consumer:opieka-nad-dzieckiem',
                    'consumer',
                    'opieka-nad-dzieckiem',
                    'Opieka nad dzieckiem',
                    $babyCategory,
                    ['Opieka nad dzieckiem'],
                ),
                microlifeTestCategory(
                    'consumer:opieka-nad-dzieckiem/akcesoria',
                    'consumer',
                    'akcesoria',
                    'Akcesoria',
                    $accessoriesCategory,
                    ['Opieka nad dzieckiem', 'Akcesoria'],
                ),
            ],
        ], categoryLimit: 1);

    expect($result['product_urls'])->toBe([
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/bc-50',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/bc-100-soft',
    ])->and($result['product_urls'])->not->toContain($accessoriesCategory)
        ->and($result['category_results'][0]['product_count'])->toBe(2);
});

it('records failed Microlife product-list pages after retries are exhausted', function (): void {
    $categoryUrl = 'https://www.microlife.pl/produkty/drogi-oddechowe/inhalatory';

    Http::fake([
        $categoryUrl => Http::response('', 503),
    ]);

    $result = app(MicrolifeProductUrlScraper::class)
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

it('runs the Microlife product-link command from saved categories and saves JSON', function (): void {
    $categoriesPath = 'scrapers/microlife/tests/categories-'.uniqid('', true).'.json';
    $resultPath = 'scrapers/microlife/tests/product-links-'.uniqid('', true).'.json';
    $absoluteCategoriesPath = storage_path('app/'.$categoriesPath);
    $absoluteResultPath = storage_path('app/'.$resultPath);
    $categoryUrl = 'https://www.microlife.pl/produkty-profesjonalne/watchbp-office';

    if (! is_dir(dirname($absoluteCategoriesPath))) {
        mkdir(dirname($absoluteCategoriesPath), 0755, true);
    }

    file_put_contents($absoluteCategoriesPath, json_encode([
        'source' => 'microlife',
        'categories' => [
            microlifeTestCategory(
                'professional:watchbp-office',
                'professional',
                'watchbp-office',
                'WatchBP Office',
                $categoryUrl,
                ['WatchBP Office'],
            ),
        ],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        $categoryUrl => Http::response(microlifeProductListFixture([
            ['/produkty-profesjonalne/watchbp-office/watchbp-office-vascular', 'WatchBP Office Vascular'],
            ['/produkty-profesjonalne/watchbp-office/watchbp-office-afib', 'WatchBP Office AFIB'],
        ])),
        '*' => Http::response('', 404),
    ]);

    try {
        $exitCode = Artisan::call('microlife:product-links', [
            '--categories-from' => $categoriesPath,
            '--catalogue' => 'professional',
            '--save' => $resultPath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Source product categories: 1')
            ->and($output)->toContain('Discovered product URLs: 2')
            ->and($output)->toContain('[professional] WatchBP Office: 2 products, 0 failed page(s)')
            ->and($output)->toContain('Saved product-link discovery result to storage/app/'.$resultPath)
            ->and(is_file($absoluteResultPath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absoluteResultPath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('microlife')
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
 */
function microlifeProductListFixture(array $products): string
{
    $html = '';

    foreach ($products as [$url, $name]) {
        $html .= sprintf(
            '<article class="product-slide" data-href="%s">'.
            '<a href="%s">View product</a><h1 property="ext_title">%s</h1>'.
            '</article>',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
        );
    }

    return '<html><body><main>'.$html.'</main></body></html>';
}

/**
 * @param  array<int, string>  $path
 * @return array<string, mixed>
 */
function microlifeTestCategory(
    string $externalId,
    string $catalogueType,
    string $slug,
    string $name,
    string $url,
    array $path,
): array {
    return [
        'source' => 'microlife',
        'external_category_id' => $externalId,
        'catalogue_type' => $catalogueType,
        'slug' => $slug,
        'name' => $name,
        'url' => $url,
        'path' => $path,
        'is_product_category' => true,
    ];
}

it('normalizes legacy heating product links to the canonical Polish catalogue path', function (): void {
    $categoryUrl = 'https://www.microlife.pl/produkty/termoterapia-2/poduszki-grzewcze';
    $productUrl = 'https://www.microlife.pl/produkty/termoterapia-2/poduszki-grzewcze/fh-80';

    Http::fake([
        $categoryUrl => Http::response(<<<'HTML'
            <html><body>
                <article data-href="/consumer-products/flexible-heating/heating-pads/fh-80">
                    <h2>FH 80</h2>
                </article>
            </body></html>
            HTML),
    ]);

    $result = app(MicrolifeProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories([
            [
                'external_category_id' => 'consumer:termoterapia-2/poduszki-grzewcze',
                'catalogue_type' => 'consumer',
                'slug' => 'poduszki-grzewcze',
                'name' => 'Poduszki grzewcze',
                'url' => $categoryUrl,
                'path' => ['Termoterapia', 'Poduszki grzewcze'],
                'is_product_category' => true,
            ],
        ]);

    expect($result['product_urls'])->toBe([
        $productUrl,
    ])->and($result['products'][0])->toMatchArray([
        'name' => 'FH 80',
        'slug' => 'fh-80',
        'canonical_url' => $productUrl,
    ]);
});
