<?php

declare(strict_types=1);

use App\Services\Vermeiren\VermeirenProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('crawls Vermeiren product links and skips duplicate source URLs', function (): void {
    $firstUrl = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedEon';
    $secondUrl = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroup%C5%81%C3%B3%C5%BCkaSubGroupSelectedClub';

    Http::fake([
        $firstUrl => Http::response(vermeirenCrawlerProductFixture('EON', 'Eon.jpg')),
        $secondUrl => Http::response(vermeirenCrawlerProductFixture('CLUB', 'Club.jpg')),
        '*' => Http::response('', 404),
    ]);

    $result = app(VermeirenProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$firstUrl, $firstUrl, $secondUrl]);

    expect($result)->toMatchArray([
        'source' => 'vermeiren',
        'product_count' => 2,
        'source_product_url_count' => 3,
        'total_product_url_count' => 3,
        'failed_urls' => [],
        'warnings' => [],
        'stopped_early' => false,
    ])->and($result['products'])->toHaveCount(2)
        ->and($result['skipped_duplicate_urls'])->toHaveCount(1)
        ->and(array_column($result['products'], 'name'))->toBe(['EON', 'CLUB']);
});

it('runs the Vermeiren product-data command and saves JSON', function (): void {
    $sourcePath = 'scrapers/vermeiren/tests/product-links-'.uniqid('', true).'.json';
    $resultPath = 'scrapers/vermeiren/tests/product-data-'.uniqid('', true).'.json';
    $absoluteSourcePath = storage_path('app/'.$sourcePath);
    $absoluteResultPath = storage_path('app/'.$resultPath);
    $url = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedEon';

    if (! is_dir(dirname($absoluteSourcePath))) {
        mkdir(dirname($absoluteSourcePath), 0755, true);
    }

    file_put_contents($absoluteSourcePath, json_encode([
        'source' => 'vermeiren',
        'product_urls' => [$url],
        'products' => [[
            'external_id' => 'eon-external-id',
            'name' => 'EON',
            'selected_name' => 'Eon',
            'product_group' => 'Skutery',
            'sub_group' => '',
            'sub_sub_group' => '',
            'url' => $url,
            'category_urls' => ['https://www.vermeiren.pl/category/scooters'],
            'category_paths' => [['Skutery']],
        ]],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        $url => Http::response(vermeirenCrawlerProductFixture('EON', 'Eon.jpg')),
        '*' => Http::response('', 404),
    ]);

    try {
        $exitCode = Artisan::call('vermeiren:crawl-product-data', [
            '--from' => $sourcePath,
            '--save' => $resultPath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
            '--insecure' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('TLS certificate verification is disabled for this Vermeiren run.')
            ->and($output)->toContain('Source product URLs: 1')
            ->and($output)->toContain('Scraped products: 1')
            ->and($output)->toContain('Images: 1')
            ->and($output)->toContain('Saved full product data to storage/app/'.$resultPath)
            ->and(is_file($absoluteResultPath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absoluteResultPath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('vermeiren')
            ->and($saved['product_count'])->toBe(1)
            ->and($saved['products'][0])->toMatchArray([
                'external_product_id' => 'eon-external-id',
                'name' => 'EON',
                'category' => 'Skutery',
            ]);
    } finally {
        @unlink($absoluteSourcePath);
        @unlink($absoluteResultPath);
    }
});

function vermeirenCrawlerProductFixture(string $name, string $image): string
{
    return <<<HTML
        <html>
        <head><title>{$name} | Vermeiren Polska</title></head>
        <body>
            <span id="view:_id1:picture"><img src="https://www.vermeiren.pl/product/picture.nsf/O/IMAGE/\$FILE/{$image}" alt="{$name}"></span>
            <h4><span id="view:_id1:prodNaam">{$name}</span></h4>
            <span id="view:_id1:label1">Produkt Vermeiren</span>
            <div id="view:_id1:repeat5"><div class="xspInputFieldRichText"><p>Opis produktu {$name}.</p></div></div>
        </body>
        </html>
        HTML;
}
