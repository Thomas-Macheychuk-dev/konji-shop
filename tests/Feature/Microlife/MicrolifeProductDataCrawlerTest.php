<?php

declare(strict_types=1);

use App\Services\Microlife\MicrolifeProductDataCrawler;
use App\Services\Microlife\MicrolifeProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('extracts a complete consumer Microlife product record', function (): void {
    $url = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-a7-touch';
    $html = microlifeConsumerProductDetailFixture($url);

    $product = app(MicrolifeProductScraper::class)->extract($html, $url, [
        'external_id' => hash('sha256', $url),
        'catalogue_type' => 'consumer',
        'name' => 'BP A7 Touch',
        'category_paths' => [
            ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
        ],
    ]);

    expect($product)->toMatchArray([
        'source' => 'microlife',
        'source_url' => $url,
        'canonical_url' => $url,
        'catalogue_type' => 'consumer',
        'name' => 'BP A7 Touch',
        'product_code' => 'BP A7 Touch',
        'brand' => 'Microlife',
        'category' => 'Ciśnieniomierze automatyczne',
        'categories' => ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
        'headline' => 'Zaawansowany technologicznie ciśnieniomierz naramienny',
        'buy_now_url' => 'https://microlifestore.com/bp-a7-touch',
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ])->and($product['description_items'])->toContain(
        'BP A7 Touch łączy ekran dotykowy z technologią AFIBsens.',
    )->and($product['features'])->toHaveCount(1)
        ->and($product['features'][0])->toMatchArray([
            'title' => 'Technologia AFIBsens',
            'description' => 'Wykrywanie migotania przedsionków.',
            'image_url' => 'https://www.microlife.pl/uploads/media/160x160/icon-afib.png',
        ])->and($product['specification_items'])->toContain(
            'Model: BP A7 Touch',
            'Wymiary: 160 x 82 x 35 mm',
            'Wyrób medyczny',
        )->and($product['attributes'])->toContainEqual([
            'code' => 'model',
            'label' => 'Model',
            'value' => 'BP A7 Touch',
            'slug' => 'bp-a7-touch',
        ])->and($product['downloads'])->toBe([
            [
                'name' => 'Instrukcja obsługi BP A7 Touch',
                'url' => 'https://www.microlife.pl/uploads/manuals/bp-a7-touch.pdf',
                'file_type' => 'PDF',
                'file_size' => '1.3 MB',
            ],
        ])->and($product['videos'])->toBe([
            [
                'title' => 'BP A7 Touch video',
                'url' => 'https://www.youtube.com/embed/example',
            ],
        ])->and($product['images'])->toBe([
            [
                'url' => 'https://www.microlife.pl/uploads/media/460x460/bp-a7-touch.png?v=2-0',
                'source_url' => 'https://www.microlife.pl/uploads/media/460x460/bp-a7-touch.png?v=2-0',
                'alt' => 'BP A7 Touch',
                'position' => 0,
                'role' => 'primary',
            ],
        ])->and($product['feature_images'])->toHaveCount(1)
        ->and($product['related_products'])->toBe([
            [
                'name' => 'BP B3 AFIB',
                'url' => 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b3-afib',
                'external_id' => hash(
                    'sha256',
                    'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b3-afib',
                ),
            ],
        ])->and($product['variant_candidates'])->toBe([]);
});

it('extracts Microlife live-layout images, feature cards and plural specifications', function (): void {
    $url = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b1-standard';

    $product = app(MicrolifeProductScraper::class)->extract(
        microlifeLiveLayoutProductFixture($url),
        $url,
        microlifeProductLinkContext(
            $url,
            'consumer',
            'BP B1 Standard',
            ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
        ),
    );

    expect($product['images'])->toBe([
        [
            'url' => 'https://www.microlife.pl/uploads/media/460x460/bp-b1-standard.png?v=1-0',
            'source_url' => 'https://www.microlife.pl/uploads/media/460x460/bp-b1-standard.png?v=1-0',
            'alt' => 'BP B1 Standard half',
            'position' => 0,
            'role' => 'primary',
        ],
    ])->and($product['feature_images'])->toHaveCount(2)
        ->and($product['features'])->toContainEqual([
            'title' => 'Wykrywanie nieregularnego bicia serca (IHB)',
            'description' => 'Dla wczesnego ostrzegania o możliwych zaburzeniach rytmu serca.',
            'image_url' => 'https://www.microlife.pl/uploads/media/160x160/icon-ihb.png?v=2-0',
        ])->and($product['features'])->toContainEqual([
            'title' => 'Gentle+',
            'description' => 'Optymalna kontrola szybkości pompowania i wysokości ciśnienia.',
            'image_url' => 'https://www.microlife.pl/uploads/media/160x160/gentle.png?v=1-0',
        ])->and($product['specification_items'])->toContain(
            'Model no.: BP B1 Standard',
            'Wymiary: 130 x 93.5 x 52 mm',
        )->and($product['warnings'])->toBe([]);
});

it('extracts Microlife hero, feature-content and product-background images while excluding app-store badges', function (): void {
    $url = 'https://www.microlife.pl/produkty/drogi-oddechowe/pulsoksymetry/oxy-500-bt';
    $html = <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>OXY 500 BT - Microlife AG</title>
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <div class="sticky-footer js-sticky-footer">
                <div class="product-header">
                    <img
                        class="product-hidden js-product-image"
                        src="/uploads/media/600x600/oxy-500-bt-new-icon.png?v=1-0"
                        alt="OXY 500 BT - new icon"
                    >
                    <div class="product-number-type">OXY 500 BT</div>
                    <h1 property="pagetitle">Pulsoksymetr napalcowy z Bluetooth®</h1>
                </div>
                <section>
                    <h2>Funkcje</h2>
                    <p>Przenośne urządzenie do pomiaru saturacji i tętna.</p>
                    <div class="product-features-image" property="product_features_image">
                        <img
                            src="/uploads/media/600x/oxy-500-bt-content.jpg?v=1-0"
                            alt="OXY 500 BT content"
                        >
                    </div>
                </section>
                <div
                    class="product-parts-image"
                    property="product_parts_image"
                    style="background-image: url('/uploads/media/1920x1080/oxy-500-bt-in-use.png?v=2-0');"
                ></div>
                <section class="connected-health-app">
                    <img
                        src="/uploads/media/190x/en-play-badge.png?v=1"
                        alt="Google Play badge"
                    >
                    <img
                        src="/uploads/media/190x/en-app-store.png?v=1"
                        alt="App Store badge"
                    >
                </section>
                <section>
                    <h2>Specyfikacja</h2>
                    <ul><li>Model: OXY 500 BT</li></ul>
                </section>
            </div>
        </body>
        </html>
        HTML;

    $product = app(MicrolifeProductScraper::class)->extract(
        $html,
        $url,
        microlifeProductLinkContext(
            $url,
            'consumer',
            'OXY 500 BT',
            ['Drogi oddechowe', 'Pulsoksymetry'],
        ),
    );

    expect($product['images'])->toBe([
        [
            'url' => 'https://www.microlife.pl/uploads/media/600x600/oxy-500-bt-new-icon.png?v=1-0',
            'source_url' => 'https://www.microlife.pl/uploads/media/600x600/oxy-500-bt-new-icon.png?v=1-0',
            'alt' => 'OXY 500 BT - new icon',
            'position' => 0,
            'role' => 'primary',
        ],
        [
            'url' => 'https://www.microlife.pl/uploads/media/600x/oxy-500-bt-content.jpg?v=1-0',
            'source_url' => 'https://www.microlife.pl/uploads/media/600x/oxy-500-bt-content.jpg?v=1-0',
            'alt' => 'OXY 500 BT content',
            'position' => 1,
            'role' => 'gallery',
        ],
        [
            'url' => 'https://www.microlife.pl/uploads/media/1920x1080/oxy-500-bt-in-use.png?v=2-0',
            'source_url' => 'https://www.microlife.pl/uploads/media/1920x1080/oxy-500-bt-in-use.png?v=2-0',
            'alt' => null,
            'position' => 2,
            'role' => 'gallery',
        ],
    ])->and($product['feature_images'])->toBe([])
        ->and($product['warnings'])->toBe([]);
});

it('extracts Polish Cechy descriptions, plural specifications and picture sources', function (): void {
    $url = 'https://www.microlife.pl/produkty/drogi-oddechowe/inhalatory/neb-nano-basic';
    $html = <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>NEB NANO basic - Microlife AG</title>
            <meta name="description" content="Przenośny nebulizator kompresorowy.">
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <div class="sticky-footer js-sticky-footer">
                <div class="product-header">
                    <picture>
                        <source data-srcset="/uploads/media/160x160/neb-nano.png 160w, /uploads/media/800x800/neb-nano.png 800w">
                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" alt="NEB NANO basic">
                    </picture>
                    <div class="product-number-type">NEB NANO basic&#65532;</div>
                    <h1 property="pagetitle">Przenośny nebulizator kompresorowy</h1>
                </div>
                <section>
                    <h2>Cechy</h2>
                    <p>Mały, lekki i bardzo cichy nebulizator do domu i podróży.</p>
                </section>
                <section>
                    <h2>Specyfkacja</h2>
                    <ul>
                        <li>Model: NEB NANO basic</li>
                        <li>Waga: 180 g</li>
                    </ul>
                </section>
            </div>
        </body>
        </html>
        HTML;

    $product = app(MicrolifeProductScraper::class)->extract(
        $html,
        $url,
        microlifeProductLinkContext(
            $url,
            'consumer',
            'NEB NANO basic',
            ['Drogi oddechowe', 'Inhalatory'],
        ),
    );

    expect($product['name'])->toBe('NEB NANO basic')
        ->and($product['description_items'])->toContain(
            'Mały, lekki i bardzo cichy nebulizator do domu i podróży.',
        )->and($product['specification_items'])->toContain(
            'Model: NEB NANO basic',
            'Waga: 180 g',
        )->and($product['images'])->toBe([
            [
                'url' => 'https://www.microlife.pl/uploads/media/800x800/neb-nano.png',
                'source_url' => 'https://www.microlife.pl/uploads/media/800x800/neb-nano.png',
                'alt' => 'NEB NANO basic',
                'position' => 0,
                'role' => 'primary',
            ],
        ])->and($product['warnings'])->toBe([]);
});

it('uses loose product bullet lists when a specification heading is absent', function (): void {
    $url = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b3-afib';
    $html = <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>BP B3 AFIB - Microlife AG</title>
            <meta property="og:image" content="/uploads/media/800x800/bp-b3-afib.png">
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <main>
                <div class="product-number-type">BP B3 AFIB</div>
                <h1 property="pagetitle">Ciśnieniomierz automatyczny</h1>
                <section>
                    <h2>Funkcje</h2>
                    <p>Ciśnieniomierz wykrywa migotanie przedsionków.</p>
                </section>
                <ul>
                    <li>W pełni automatyczny ciśnieniomierz naramienny</li>
                    <li>Model: BP B3 AFIB</li>
                    <li>Wymiary: 138 x 94.5 x 62.5 mm</li>
                </ul>
            </main>
        </body>
        </html>
        HTML;

    $product = app(MicrolifeProductScraper::class)->extract(
        $html,
        $url,
        microlifeProductLinkContext(
            $url,
            'consumer',
            'BP B3 AFIB',
            ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
        ),
    );

    expect($product['specification_items'])->toContain(
        'W pełni automatyczny ciśnieniomierz naramienny',
        'Model: BP B3 AFIB',
        'Wymiary: 138 x 94.5 x 62.5 mm',
    )->and($product['warnings'])->toBe([]);
});

it('falls back to metadata descriptions, Open Graph images and product tables', function (): void {
    $url = 'https://www.microlife.pl/produkty-profesjonalne/watchbp-o3/watchbp-o3-ambulatory-2g';
    $html = <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>WatchBP O3 Ambulatory 2G - Microlife AG</title>
            <meta name="description" content="Profesjonalny 24-godzinny holter ciśnieniowy.">
            <meta property="og:image" content="/uploads/media/800x800/watchbp-o3.png?v=1-0">
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <main>
                <div class="product-number-type">WatchBP O3 Ambulatory 2G</div>
                <h1 property="pagetitle">Zaawansowany holter ciśnieniowy</h1>
                <table class="available-models">
                    <tr><th>Model</th><th>Bluetooth</th><th>AFIB</th></tr>
                    <tr><td>WatchBP O3 Ambulatory 2G</td><td>Tak</td><td>Opcjonalnie</td></tr>
                </table>
            </main>
        </body>
        </html>
        HTML;

    $product = app(MicrolifeProductScraper::class)->extract(
        $html,
        $url,
        microlifeProductLinkContext(
            $url,
            'professional',
            'WatchBP O3 Ambulatory 2G',
            ['WatchBP O3'],
        ),
    );

    expect($product['description_items'])->toBe([
        'Profesjonalny 24-godzinny holter ciśnieniowy.',
    ])->and($product['images'])->toBe([
        [
            'url' => 'https://www.microlife.pl/uploads/media/800x800/watchbp-o3.png?v=1-0',
            'source_url' => 'https://www.microlife.pl/uploads/media/800x800/watchbp-o3.png?v=1-0',
            'alt' => null,
            'position' => 0,
            'role' => 'primary',
        ],
    ])->and($product['specification_items'])->toContain(
        'Model | Bluetooth | AFIB',
        'WatchBP O3 Ambulatory 2G | Tak | Opcjonalnie',
    )->and($product['warnings'])->toBe([]);
});

it('extracts explicit size variants from a professional Microlife cuff', function (): void {
    $url = 'https://www.microlife.pl/produkty-profesjonalne/mankiety-i-wyposazenie/mankiet-kostkowy-watchbp-office';
    $html = microlifeProfessionalCuffDetailFixture($url);

    $product = app(MicrolifeProductScraper::class)->extract($html, $url, [
        'external_id' => hash('sha256', $url),
        'catalogue_type' => 'professional',
        'name' => 'Mankiet kostkowy WatchBP Office',
        'category_paths' => [
            ['Mankiety i wyposażenie'],
        ],
    ]);

    expect($product)->toMatchArray([
        'catalogue_type' => 'professional',
        'name' => 'Mankiet kostkowy WatchBP Office',
        'category' => 'Mankiety i wyposażenie',
        'is_medical_device' => true,
        'warnings' => [],
    ])->and($product['description_items'])->toContain(
        'Dostępne w rozmiarze M (22-32 cm) lub rozmiarze L (32-42 cm)',
    )->and($product['variant_candidates'])->toHaveCount(2)
        ->and($product['variant_candidates'][0])->toMatchArray([
            'size' => 'M',
            'name' => 'Mankiet kostkowy WatchBP Office – M',
            'measurements' => ['Obwód' => '22-32 cm'],
            'measurement_label' => 'Obwód',
            'measurement' => '22-32 cm',
        ])->and($product['variant_candidates'][1])->toMatchArray([
            'size' => 'L',
            'measurements' => ['Obwód' => '32-42 cm'],
        ]);
});

it('crawls consumer and professional Microlife products and records failures', function (): void {
    $consumerUrl = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-a7-touch';
    $professionalUrl = 'https://www.microlife.pl/produkty-profesjonalne/mankiety-i-wyposazenie/mankiet-kostkowy-watchbp-office';
    $failedUrl = 'https://www.microlife.pl/produkty/drogi-oddechowe/inhalatory/failed-product';

    Http::fake([
        $consumerUrl => Http::response(microlifeConsumerProductDetailFixture($consumerUrl)),
        $professionalUrl => Http::response(microlifeProfessionalCuffDetailFixture($professionalUrl)),
        $failedUrl => Http::response('', 503),
        '*' => Http::response('', 404),
    ]);

    $result = app(MicrolifeProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(1, 0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'microlife',
            'product_urls' => [$consumerUrl, $professionalUrl, $failedUrl],
            'products' => [
                microlifeProductLinkContext(
                    $consumerUrl,
                    'consumer',
                    'BP A7 Touch',
                    ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
                ),
                microlifeProductLinkContext(
                    $professionalUrl,
                    'professional',
                    'Mankiet kostkowy WatchBP Office',
                    ['Mankiety i wyposażenie'],
                ),
                microlifeProductLinkContext(
                    $failedUrl,
                    'consumer',
                    'Failed product',
                    ['Drogi oddechowe', 'Inhalatory'],
                ),
            ],
        ]);

    expect($result)->toMatchArray([
        'source' => 'microlife',
        'product_count' => 2,
        'consumer_product_count' => 1,
        'professional_product_count' => 1,
        'source_product_url_count' => 3,
        'total_product_url_count' => 3,
        'stopped_early' => false,
        'stop_reason' => null,
    ])->and($result['products'])->toHaveCount(2)
        ->and($result['skipped_failed_products'])->toBe([
            [
                'url' => $failedUrl,
                'reason' => 'HTTP 503',
            ],
        ])->and($result['failed_urls'])->toBe([
            $failedUrl => 'HTTP 503',
        ])->and($result['failed_url_counts'])->toBe([
            'HTTP 503' => 1,
        ]);
});

it('runs the Microlife product-data command and saves JSON', function (): void {
    $url = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-a7-touch';
    $sourcePath = 'scrapers/microlife/tests/product-links-'.uniqid('', true).'.json';
    $resultPath = 'scrapers/microlife/tests/product-data-'.uniqid('', true).'.json';
    $absoluteSourcePath = storage_path('app/'.$sourcePath);
    $absoluteResultPath = storage_path('app/'.$resultPath);

    if (! is_dir(dirname($absoluteSourcePath))) {
        mkdir(dirname($absoluteSourcePath), 0755, true);
    }

    file_put_contents($absoluteSourcePath, json_encode([
        'source' => 'microlife',
        'product_urls' => [$url],
        'products' => [
            microlifeProductLinkContext(
                $url,
                'consumer',
                'BP A7 Touch',
                ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
            ),
        ],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        $url => Http::response(microlifeConsumerProductDetailFixture($url)),
        '*' => Http::response('', 404),
    ]);

    try {
        $exitCode = Artisan::call('microlife:crawl-product-data', [
            '--from' => $sourcePath,
            '--save' => $resultPath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Source product URLs: 1')
            ->and($output)->toContain('Scraped products: 1')
            ->and($output)->toContain('- Consumer products: 1')
            ->and($output)->toContain('- Professional products: 0')
            ->and($output)->toContain('Saved full product data to storage/app/'.$resultPath)
            ->and(is_file($absoluteResultPath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absoluteResultPath), true, flags: JSON_THROW_ON_ERROR);

        expect($saved['product_count'])->toBe(1)
            ->and($saved['products'][0]['name'])->toBe('BP A7 Touch')
            ->and($saved['products'][0]['catalogue_type'])->toBe('consumer');
    } finally {
        @unlink($absoluteSourcePath);
        @unlink($absoluteResultPath);
    }
});

/**
 * @return array<string, mixed>
 */
function microlifeProductLinkContext(
    string $url,
    string $catalogueType,
    string $name,
    array $categoryPath,
): array {
    return [
        'source' => 'microlife',
        'external_id' => hash('sha256', $url),
        'catalogue_type' => $catalogueType,
        'slug' => basename($url),
        'name' => $name,
        'url' => $url,
        'source_url' => $url,
        'canonical_url' => $url,
        'category_paths' => [$categoryPath],
    ];
}

function microlifeConsumerProductDetailFixture(string $url): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>BP A7 Touch - Microlife AG</title>
            <meta name="description" content="Zaawansowany ciśnieniomierz Microlife.">
            <meta property="og:title" content="BP A7 Touch - Microlife AG">
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <main>
                <div class="product-number-type"><span>BP</span> A7 Touch</div>
                <h1 property="pagetitle">Zaawansowany technologicznie ciśnieniomierz naramienny</h1>
                <a href="https://microlifestore.com/bp-a7-touch">Kup teraz</a>
                <img class="product-main" src="/uploads/media/460x460/bp-a7-touch.png?v=2-0" alt="BP A7 Touch">

                <section>
                    <h2>Funkcje</h2>
                    <p>BP A7 Touch łączy ekran dotykowy z technologią AFIBsens.</p>
                    <article class="product-feature">
                        <img class="icon" src="/uploads/media/160x160/icon-afib.png" alt="icon AFIB">
                        <h3>Technologia AFIBsens</h3>
                        <p>Wykrywanie migotania przedsionków.</p>
                    </article>
                </section>

                <section>
                    <h2>Instrukcja obsługi</h2>
                    <p><a href="/uploads/manuals/bp-a7-touch.pdf">Instrukcja obsługi BP A7 Touch</a> PDF, (1.3 MB)</p>
                </section>

                <section>
                    <h2>Wideo produktowe</h2>
                    <iframe src="https://www.youtube.com/embed/example" title="BP A7 Touch video"></iframe>
                </section>

                <section>
                    <h2>Specyfikacja</h2>
                    <ul>
                        <li>Model: BP A7 Touch</li>
                        <li>Wymiary: 160 x 82 x 35 mm</li>
                        <li>Wyrób medyczny</li>
                    </ul>
                </section>

                <section>
                    <h2>Podobne produkty</h2>
                    <article>
                        <h3><a href="/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b3-afib">BP B3 AFIB</a></h3>
                        <a href="/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/bp-b3-afib">Pokaż produkt</a>
                    </article>
                </section>
            </main>
            <footer>To jest wyrób medyczny. Używaj go zgodnie z instrukcją.</footer>
        </body>
        </html>
        HTML;
}

function microlifeLiveLayoutProductFixture(string $url): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>BP B1 Standard - Microlife AG</title>
            <meta name="description" content="Automatyczny ciśnieniomierz Microlife.">
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <div class="sticky-footer js-sticky-footer">
            <div class="product-highlight">
                <img data-srcset="/uploads/media/160x160/bp-b1-standard.png?v=1-0 160w, /uploads/media/460x460/bp-b1-standard.png?v=1-0 460w" alt="BP B1 Standard half">
                <div class="product-number-type">BP B1 Standard</div>
                <h1 property="pagetitle">Ciśnieniomierz automatyczny</h1>
            </div>

            <section class="product-functions">
                <h2>Funkcje</h2>
                <p>Podstawowe urządzenie oferujące niezawodną jakość firmy Microlife.</p>
                <div class="grid">
                    <div class="grid__item product-function">
                        <img src="/uploads/media/160x160/icon-ihb.png?v=2-0" alt="icon IHB">
                        <span class="medium">Wykrywanie nieregularnego bicia serca (IHB)</span>
                        <p>Dla wczesnego ostrzegania o możliwych zaburzeniach rytmu serca.</p>
                    </div>
                    <div class="grid__item product-function">
                        <img src="/uploads/media/160x160/gentle.png?v=1-0" alt="Gentle+">
                        <span class="medium">Gentle+</span>
                        <p>Optymalna kontrola szybkości pompowania i wysokości ciśnienia.</p>
                    </div>
                </div>
            </section>

            <section>
                <h2>Specifications</h2>
                <ul>
                    <li>Model no.: BP B1 Standard</li>
                    <li>Wymiary: 130 x 93.5 x 52 mm</li>
                </ul>
            </section>
            </div>
            <footer class="footer">
                <img src="/uploads/media/160x160/footer-promo.png" alt="Footer promo">
            </footer>
        </body>
        </html>
        HTML;
}

function microlifeProfessionalCuffDetailFixture(string $url): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>Mankiet kostkowy WatchBP Office - Microlife AG</title>
            <meta name="description" content="Profesjonalny mankiet kostkowy WatchBP Office.">
            <link rel="canonical" href="{$url}">
        </head>
        <body>
            <main>
                <h1>Miękki mankiet stożkowy na kostkę</h1>
                <img class="product-main" src="/uploads/media/460x460/cuff-ankle.png" alt="Cuff-Ankle">
                <section>
                    <h2>Właściwości</h2>
                    <p>Mankiet umożliwia wykonywanie pomiarów w obrębie kostki i ramienia.</p>
                    <ul>
                        <li>Wszystkie pęcherze mankietów nie zawierają lateksu ani PCV</li>
                        <li>Dostępne w rozmiarze M (22-32 cm) lub rozmiarze L (32-42 cm)</li>
                    </ul>
                </section>
            </main>
            <footer>To jest wyrób medyczny. Używaj go zgodnie z instrukcją.</footer>
        </body>
        </html>
        HTML;
}
