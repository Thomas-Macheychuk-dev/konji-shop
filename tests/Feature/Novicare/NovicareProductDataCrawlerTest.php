<?php

use App\Services\Novicare\NovicareProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('crawls Novicare product-link discovery data with category context', function (): void {
    $firstUrl = 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';
    $secondUrl = 'https://novicare.pl/produkty/stopa/orteza-stawu-skokowego-dr-a004/';

    Http::fake([
        $firstUrl => Http::response(novicareCrawlerProductPageFixture(
            canonicalUrl: $firstUrl,
            name: 'Orteza stawu kolanowego 6155',
            category: 'Kolano',
            code: '6155',
            size: 'M',
            measurement: '33 – 36',
        )),
        $secondUrl => Http::response(novicareCrawlerProductPageFixture(
            canonicalUrl: $secondUrl,
            name: 'Orteza stawu skokowego DR-A004',
            category: 'Stopa',
            code: 'DR-A004',
            size: 'L',
            measurement: '25 – 28',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'novicare',
            'product_urls' => [$firstUrl, $secondUrl],
            'products' => [
                [
                    'external_id' => hash('sha256', $firstUrl),
                    'url' => $firstUrl,
                    'product_code' => '6155',
                    'category_paths' => [['Kolano']],
                ],
                [
                    'external_id' => hash('sha256', $secondUrl),
                    'url' => $secondUrl,
                    'product_code' => 'DR-A004',
                    'category_paths' => [['Stopa']],
                ],
            ],
        ]);

    expect($result)->toMatchArray([
        'source' => 'novicare',
        'product_count' => 2,
        'source_product_url_count' => 2,
        'total_product_url_count' => 2,
        'offset' => 0,
        'failed_urls' => [],
        'stopped_early' => false,
        'stop_reason' => null,
    ])->and($result['products'][0])->toMatchArray([
        'external_product_id' => hash('sha256', $firstUrl),
        'category' => 'Kolano',
        'category_paths' => [['Kolano']],
        'product_code' => '6155',
    ])->and($result['products'][1])->toMatchArray([
        'external_product_id' => hash('sha256', $secondUrl),
        'category' => 'Stopa',
        'category_paths' => [['Stopa']],
        'product_code' => 'DR-A004',
    ]);
});

it('records failed Novicare requests and stops after rate limiting', function (): void {
    $rateLimitedUrl = 'https://novicare.pl/produkty/kolano/rate-limited-product/';
    $nextUrl = 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';

    Http::fake([
        $rateLimitedUrl => Http::response('', 429),
        $nextUrl => Http::response(novicareCrawlerProductPageFixture(
            canonicalUrl: $nextUrl,
            name: 'Orteza stawu kolanowego 6155',
            category: 'Kolano',
            code: '6155',
            size: 'M',
            measurement: '33 – 36',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(1, 0)
        ->crawlProductUrls([$rateLimitedUrl, $nextUrl]);

    expect($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([$rateLimitedUrl => 'HTTP 429'])
        ->and($result['failed_url_counts'])->toBe(['HTTP 429' => 1])
        ->and($result['stopped_early'])->toBeTrue()
        ->and($result['stop_reason'])
        ->toBe('HTTP 429 rate limit or temporary block from Novicare');

    Http::assertSentCount(1);
});

it('runs the Novicare product-data command from saved links and saves JSON', function (): void {
    Storage::fake('local');

    $url = 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';
    $from = 'scrapers/novicare/tests/product-links.json';
    $save = 'scrapers/novicare/tests/product-data.json';
    $absoluteSave = storage_path('app/'.$save);

    @unlink($absoluteSave);

    Storage::disk('local')->put($from, json_encode([
        'source' => 'novicare',
        'product_urls' => [$url],
        'products' => [
            [
                'external_id' => hash('sha256', $url),
                'url' => $url,
                'name' => 'Orteza stawu kolanowego 6155',
                'product_code' => '6155',
                'category_paths' => [['Kolano']],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    Http::fake([
        $url => Http::response(novicareCrawlerProductPageFixture(
            canonicalUrl: $url,
            name: 'Orteza stawu kolanowego 6155',
            category: 'Kolano',
            code: '6155',
            size: 'M',
            measurement: '33 – 36',
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('novicare:crawl-product-data', [
        '--from' => $from,
        '--save' => $save,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Source product URLs: 1')
        ->and($output)->toContain('Scraped products: 1')
        ->and($output)->toContain('Product code: 6155')
        ->and($output)->toContain('Sizes: 1')
        ->and($output)->toContain('Saved full product data to storage/app/'.$save)
        ->and(is_file($absoluteSave))->toBeTrue();

    $saved = json_decode((string) file_get_contents($absoluteSave), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('novicare')
        ->and($saved['product_count'])->toBe(1)
        ->and($saved['products'][0]['product_code'])->toBe('6155')
        ->and($saved['products'][0]['variant_candidates'][0]['size'])->toBe('M')
        ->and($saved['products'][0]['category_paths'])->toBe([['Kolano']]);

    @unlink($absoluteSave);
});

it('supports limit and offset while crawling Novicare product data', function (): void {
    $firstUrl = 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';
    $secondUrl = 'https://novicare.pl/produkty/stopa/orteza-stawu-skokowego-dr-a004/';

    Http::fake([
        $firstUrl => Http::response(novicareCrawlerProductPageFixture(
            canonicalUrl: $firstUrl,
            name: 'Orteza stawu kolanowego 6155',
            category: 'Kolano',
            code: '6155',
            size: 'M',
            measurement: '33 – 36',
        )),
        $secondUrl => Http::response(novicareCrawlerProductPageFixture(
            canonicalUrl: $secondUrl,
            name: 'Orteza stawu skokowego DR-A004',
            category: 'Stopa',
            code: 'DR-A004',
            size: 'L',
            measurement: '25 – 28',
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$firstUrl, $secondUrl], limit: 1, offset: 1);

    expect($result['source_product_urls'])->toBe([$secondUrl])
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['product_code'])->toBe('DR-A004');
});

function novicareCrawlerProductPageFixture(
    string $canonicalUrl,
    string $name,
    string $category,
    string $code,
    string $size,
    string $measurement,
): string {
    return <<<HTML
        <!doctype html>
        <html lang="pl-PL">
            <head>
                <title>{$name} - NOVICARE - Dystrybutor Specjalistycznych Produktów Ortopedycznych</title>
                <link rel="canonical" href="{$canonicalUrl}">
                <meta property="og:title" content="{$name} - NOVICARE - Dystrybutor Specjalistycznych Produktów Ortopedycznych">
                <meta property="og:description" content="Opis SEO {$name}">
            </head>
            <body>
                <main id="main"><article><div class="entry-content single-content">
                    <a href="/produkty/{$category}/"><h6>{$category}</h6></a>
                    <h2>{$name}</h2>
                    <img src="/wp-content/uploads/2024/09/{$code}.webp">
                    <h2>{$name}</h2>
                    <h3>Opis</h3>
                    <div><span class="kt-svg-icon-list-text">Opis produktu {$code}.</span></div>
                    <h3>Wskazania</h3>
                    <div><span class="kt-svg-icon-list-text">Stabilizacja i ochrona.</span></div>
                    <h3>Dostępne rozmiary</h3>
                    <table><tr><th>Rozmiar</th><th>{$size}</th></tr><tr><td>cm</td><td>{$measurement}</td></tr></table>
                    <h3>Detale produktu</h3>
                    <img data-full-image="/wp-content/uploads/2024/10/{$code}-detail.jpg" src="/wp-content/uploads/2024/10/{$code}-detail-300x300.jpg">
                    <h3>Sposób zakładania</h3>
                    <img src="/wp-content/uploads/2024/10/{$code}-fitting.jpg">
                </div></article></main>
            </body>
        </html>
    HTML;
}
