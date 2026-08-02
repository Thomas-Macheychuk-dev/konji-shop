<?php

declare(strict_types=1);

use App\Services\Microlife\MicrolifeCategoryUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers consumer and professional Microlife category branches', function (): void {
    Http::fake([
        MicrolifeCategoryUrlScraper::CONSUMER_URL => Http::response(microlifeConsumerRootFixture()),
        'https://www.microlife.pl/produkty/cisnienie-krwi' => Http::response(microlifeCategoryFixture([
            ['/produkty/cisnienie-krwi/cisnieniomierze-automatyczne', 'Ciśnieniomierze automatyczne'],
            ['/produkty/cisnienie-krwi/nadgarstkowe', 'Nadgarstkowe'],
        ])),
        'https://www.microlife.pl/produkty/temperatura' => Http::response(microlifeCategoryFixture([
            ['/produkty/temperatura/termometry-elektroniczne', 'Termometry elektroniczne'],
        ])),
        'https://www.microlife.pl/produkty/testpage' => Http::response(microlifeCategoryFixture([
            ['/produkty/testpage/mankiet-m-l', 'Mankiet M-L'],
        ])),
        MicrolifeCategoryUrlScraper::PROFESSIONAL_URL => Http::response(microlifeProfessionalRootFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(MicrolifeCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('microlife')
        ->and($result['start_urls'])->toBe([
            MicrolifeCategoryUrlScraper::CONSUMER_URL,
            MicrolifeCategoryUrlScraper::PROFESSIONAL_URL,
        ])
        ->and($result['catalogues'])->toHaveCount(2)
        ->and($result['top_categories'])->toHaveCount(6)
        ->and($result['categories'])->toHaveCount(10)
        ->and($result['product_category_urls'])->toHaveCount(7)
        ->and($result['visited_urls'])->toBe([
            MicrolifeCategoryUrlScraper::CONSUMER_URL,
            'https://www.microlife.pl/produkty/cisnienie-krwi',
            'https://www.microlife.pl/produkty/temperatura',
            'https://www.microlife.pl/produkty/testpage',
            MicrolifeCategoryUrlScraper::PROFESSIONAL_URL,
        ])
        ->and($result['failed_urls'])->toBe([]);

    expect($result['category_urls'])->toContain(
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne',
        'https://www.microlife.pl/produkty-profesjonalne/watchbp-office',
        'https://www.microlife.pl/produkty-profesjonalne/watchbp-o3',
        'https://www.microlife.pl/produkty-profesjonalne/mankiety-i-wyposazenie',
    )->not->toContain(
        'https://www.microlife.pl/produkty-profesjonalne/walidacje-i-badania-kliniczne',
        'https://www.microlife.pl/wsparcie/oprogramowanie-profesjonalne',
    );

    $bloodPressure = collect($result['categories'])
        ->firstWhere('url', 'https://www.microlife.pl/produkty/cisnienie-krwi');
    $automatic = collect($result['categories'])
        ->firstWhere('url', 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne');
    $professional = collect($result['categories'])
        ->firstWhere('url', 'https://www.microlife.pl/produkty-profesjonalne/watchbp-office');

    expect($bloodPressure)->toMatchArray([
        'source' => 'microlife',
        'catalogue_type' => 'consumer',
        'external_category_id' => 'consumer:cisnienie-krwi',
        'slug' => 'cisnienie-krwi',
        'name' => 'Ciśnienie krwi',
        'path' => ['Ciśnienie krwi'],
        'level' => 1,
        'has_children' => true,
        'is_product_category' => false,
    ])->and($automatic)->toMatchArray([
        'catalogue_type' => 'consumer',
        'external_category_id' => 'consumer:cisnienie-krwi/cisnieniomierze-automatyczne',
        'parent_external_category_id' => 'consumer:cisnienie-krwi',
        'path' => ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
        'level' => 2,
        'is_product_category' => true,
    ])->and($professional)->toMatchArray([
        'catalogue_type' => 'professional',
        'external_category_id' => 'professional:watchbp-office',
        'path' => ['WatchBP Office'],
        'level' => 1,
        'is_product_category' => true,
    ]);
});

it('keeps direct baby products on the parent category and excludes product finders', function (): void {
    Http::fake([
        MicrolifeCategoryUrlScraper::CONSUMER_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/cisnienie-krwi"><span>Ciśnienie krwi</span></a>
                <a href="/produkty/opieka-nad-dzieckiem"><span>Opieka nad dzieckiem</span></a>
            </body></html>
            HTML),
        'https://www.microlife.pl/produkty/cisnienie-krwi' => Http::response(<<<'HTML'
            <html><body>
                <article data-href="/produkty/cisnienie-krwi/cisnieniomierze-automatyczne">
                    <h2>Ciśnieniomierze automatyczne</h2>
                </article>
                <section>
                    <h2>Ciśnieniomierze automatyczne</h2>
                    <a href="/produkty/cisnienie-krwi/nadgarstkowe">View products</a>
                </section>
                <a href="/produkty/cisnienie-krwi/wyszukiwarka-produktow">Znajdź odpowiedni ciśnieniomierz</a>
            </body></html>
            HTML),
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem' => Http::response(<<<'HTML'
            <html><body>
                <article data-href="/produkty/opieka-nad-dzieckiem/bc-50"><h2>BC 50</h2></article>
                <article data-href="/produkty/opieka-nad-dzieckiem/bc-100-soft"><h2>BC 100 Soft</h2></article>
                <article data-href="/produkty/opieka-nad-dzieckiem/bc-200-comfy"><h2>BC 200 Comfy</h2></article>
                <article data-href="/produkty/opieka-nad-dzieckiem/bc-300-maxi-2w1"><h2>BC 300 Maxi 2 in 1</h2></article>
                <article data-href="/produkty/opieka-nad-dzieckiem/akcesoria"><h2>Akcesoria</h2></article>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MicrolifeCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([MicrolifeCategoryUrlScraper::CONSUMER_URL]);

    expect($result['category_urls'])->toBe([
        'https://www.microlife.pl/produkty/cisnienie-krwi',
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne',
        'https://www.microlife.pl/produkty/cisnienie-krwi/nadgarstkowe',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/akcesoria',
    ])->and($result['product_category_urls'])->toBe([
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne',
        'https://www.microlife.pl/produkty/cisnienie-krwi/nadgarstkowe',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/akcesoria',
    ])->and($result['category_urls'])->not->toContain(
        'https://www.microlife.pl/produkty/cisnienie-krwi/wyszukiwarka-produktow',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/bc-50',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/bc-100-soft',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/bc-200-comfy',
        'https://www.microlife.pl/produkty/opieka-nad-dzieckiem/bc-300-maxi-2w1',
    );

    $babyCare = collect($result['categories'])
        ->firstWhere('url', 'https://www.microlife.pl/produkty/opieka-nad-dzieckiem');
    $wrist = collect($result['categories'])
        ->firstWhere('url', 'https://www.microlife.pl/produkty/cisnienie-krwi/nadgarstkowe');

    expect($babyCare)->toMatchArray([
        'name' => 'Opieka nad dzieckiem',
        'has_children' => true,
        'is_product_category' => true,
    ])->and($wrist)->toMatchArray([
        'name' => 'Ciśnieniomierze nadgarstkowe',
        'path' => ['Ciśnienie krwi', 'Ciśnieniomierze nadgarstkowe'],
    ]);
});

it('uses stable professional category names instead of surrounding marketing headings', function (): void {
    Http::fake([
        MicrolifeCategoryUrlScraper::PROFESSIONAL_URL => Http::response(<<<'HTML'
            <html><body>
                <section>
                    <h2>Microlife WatchBP Bądź częścią wielkiego ruchu na rzecz zapobiegania udarom i chorobom serca</h2>
                    <a href="/professional-products/watchbp-office">View products</a>
                </section>
                <section>
                    <h2>Urządzenia do całodobowego monitorowania</h2>
                    <a href="/professional-products/watchbp-o3">View products</a>
                </section>
                <a href="/produkty-profesjonalne/mankiety-i-wyposazenie">Mankiety i wyposażenie</a>
            </body></html>
            HTML),
    ]);

    $result = app(MicrolifeCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([MicrolifeCategoryUrlScraper::PROFESSIONAL_URL]);

    expect(array_column($result['categories'], 'name'))->toBe([
        'WatchBP Office',
        'WatchBP O3',
        'Mankiety i wyposażenie',
    ]);
});

it('normalizes legacy professional URLs and records failed consumer category pages', function (): void {
    Http::fake([
        MicrolifeCategoryUrlScraper::CONSUMER_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="http://microlife.pl/produkty/cisnienie-krwi/?utm_source=test">Ciśnienie krwi</a>
                <a href="https://example.com/produkty/temperatura">External</a>
            </body></html>
            HTML),
        'https://www.microlife.pl/produkty/cisnienie-krwi' => Http::response('', 503),
        MicrolifeCategoryUrlScraper::PROFESSIONAL_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="https://www.microlife.pl/professional-products/watchbp-office?source=nav">WatchBP Office</a>
                <a href="/produkty-profesjonalne/walidacje-i-badania-kliniczne">Walidacje</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MicrolifeCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->withAttempts(1)
        ->scrape();

    expect($result['category_urls'])->toBe([
        'https://www.microlife.pl/produkty/cisnienie-krwi',
        'https://www.microlife.pl/produkty-profesjonalne/watchbp-office',
    ])->and($result['product_category_urls'])->toBe([
        'https://www.microlife.pl/produkty/cisnienie-krwi',
        'https://www.microlife.pl/produkty-profesjonalne/watchbp-office',
    ])->and($result['failed_urls'])->toBe([
        'https://www.microlife.pl/produkty/cisnienie-krwi' => 'HTTP 503',
    ]);
});

it('runs the Microlife category command and saves JSON', function (): void {
    Http::fake([
        MicrolifeCategoryUrlScraper::PROFESSIONAL_URL => Http::response(microlifeProfessionalRootFixture()),
        '*' => Http::response('', 404),
    ]);

    $relativePath = 'scrapers/microlife/tests/categories-'.uniqid('', true).'.json';
    $absolutePath = storage_path('app/'.$relativePath);

    try {
        $exitCode = Artisan::call('microlife:categories', [
            '--catalogue' => 'professional',
            '--save' => $relativePath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Discovered category URLs: 3')
            ->and($output)->toContain('Product-scraping category URLs: 3')
            ->and($output)->toContain('Professional: 3 categories, 3 product categories')
            ->and($output)->toContain('Saved discovery result to storage/app/'.$relativePath)
            ->and(is_file($absolutePath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('microlife')
            ->and($saved['categories'])->toHaveCount(3)
            ->and($saved['product_category_urls'])->toHaveCount(3);
    } finally {
        @unlink($absolutePath);
    }
});

function microlifeConsumerRootFixture(): string
{
    return <<<'HTML'
        <html><body>
            <nav>
                <a href="/produkty/cisnienie-krwi"><span>Ciśnienie krwi</span></a>
                <a href="/produkty/temperatura"><span>Temperatura</span></a>
                <a href="/produkty/testpage"><span>Części zamienne</span></a>
                <a href="/produkty/wyszukiwarka-produktow-microlife">Wyszukiwarka</a>
                <a href="/wsparcie/pobierz-oprogramowanie">Oprogramowanie</a>
            </nav>
        </body></html>
        HTML;
}

function microlifeProfessionalRootFixture(): string
{
    return <<<'HTML'
        <html><body>
            <nav>
                <a href="/professional-products/watchbp-office"><span>WatchBP Office</span></a>
                <a href="/professional-products/watchbp-o3"><span>WatchBP O3</span></a>
                <a href="/produkty-profesjonalne/mankiety-i-wyposazenie"><span>Mankiety i wyposażenie</span></a>
                <a href="/produkty-profesjonalne/walidacje-i-badania-kliniczne">Walidacje i badania kliniczne</a>
                <a href="/wsparcie/oprogramowanie-profesjonalne">Oprogramowanie</a>
            </nav>
        </body></html>
        HTML;
}

/**
 * @param  array<int, array{0: string, 1: string}>  $children
 */
function microlifeCategoryFixture(array $children): string
{
    $html = '';

    foreach ($children as [$url, $name]) {
        $html .= sprintf(
            '<article class="product-category" data-href="%s"><h2>%s</h2><a href="%s">View products</a></article>',
            htmlspecialchars($url, ENT_QUOTES),
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($url, ENT_QUOTES),
        );
    }

    return '<html><body><main>'.$html.'</main></body></html>';
}

it('normalizes the legacy heating category and excludes the external-only spare-parts landing page', function (): void {
    $termotherapyUrl = 'https://www.microlife.pl/produkty/termoterapia-2';
    $heatingPadsUrl = 'https://www.microlife.pl/produkty/termoterapia-2/poduszki-grzewcze';
    $sparePartsUrl = 'https://www.microlife.pl/produkty/testpage';

    Http::fake([
        MicrolifeCategoryUrlScraper::CONSUMER_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/termoterapia-2"><span>Termoterapia</span></a>
                <a href="/produkty/testpage"><span>Części zamienne</span></a>
            </body></html>
            HTML),
        $termotherapyUrl => Http::response(<<<'HTML'
            <html><body>
                <article data-href="/consumer-products/flexible-heating/heating-pads">
                    <h2>Poduszki grzewcze</h2>
                    <a href="/consumer-products/flexible-heating/heating-pads">Przeczytaj więcej</a>
                </article>
            </body></html>
            HTML),
        $sparePartsUrl => Http::response(<<<'HTML'
            <html><body>
                <h1>Części zamienne</h1>
                <a href="https://microlifestore.com">Kup teraz</a>
            </body></html>
            HTML),
    ]);

    $result = app(MicrolifeCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([MicrolifeCategoryUrlScraper::CONSUMER_URL]);

    $termotherapy = collect($result['categories'])->firstWhere('url', $termotherapyUrl);
    $heatingPads = collect($result['categories'])->firstWhere('url', $heatingPadsUrl);
    $spareParts = collect($result['categories'])->firstWhere('url', $sparePartsUrl);

    expect($result['category_urls'])->toBe([
        $termotherapyUrl,
        $heatingPadsUrl,
        $sparePartsUrl,
    ])->and($result['product_category_urls'])->toBe([
        $heatingPadsUrl,
    ])->and($termotherapy)->toMatchArray([
        'name' => 'Termoterapia',
        'has_children' => true,
        'is_product_category' => false,
    ])->and($heatingPads)->toMatchArray([
        'name' => 'Poduszki grzewcze',
        'path' => ['Termoterapia', 'Poduszki grzewcze'],
        'level' => 2,
        'is_product_category' => true,
    ])->and($spareParts)->toMatchArray([
        'name' => 'Części zamienne',
        'has_children' => false,
        'is_product_category' => false,
    ]);
});
