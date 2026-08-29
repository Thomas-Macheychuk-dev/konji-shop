<?php

use App\Services\Neoxmed\NeoxmedProductDataCrawler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('crawls NeoxMed category pages and merges products exposed in more than one category', function (): void {
    Http::fake([
        'https://neoxmed.com/ortezy-barku/' => Http::response(neoxmedCrawlerCategoryFixture(
            category: 'Ortezy barku',
            products: [
                ['B-01', 'Kamizelka stawu barkowego'],
                ['T-03', 'Neurotemblak'],
            ],
        )),
        'https://neoxmed.com/temblaki/' => Http::response(neoxmedCrawlerCategoryFixture(
            category: 'Temblaki',
            products: [
                ['T-01', 'Temblak kończyny górnej'],
                ['T-03', 'Neurotemblak'],
            ],
        )),
        '*' => Http::response('', 404),
    ]);

    $result = app(NeoxmedProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlCategoryUrls([
            'https://neoxmed.com/ortezy-barku/',
            'https://neoxmed.com/temblaki/',
        ]);

    expect($result['source'])->toBe('neoxmed')
        ->and($result['discovered_product_count'])->toBe(3)
        ->and($result['product_count'])->toBe(3)
        ->and($result['duplicate_products'])->toHaveCount(1)
        ->and($result['failed_urls'])->toBe([]);

    $t03 = collect($result['products'])->firstWhere('external_product_id', 'T-03');

    expect($t03)->not->toBeNull()
        ->and($t03['categories'])->toContain('Ortezy barku', 'Temblaki')
        ->and($t03['source_category_paths'])->toContain(['Ortezy barku'], ['Temblaki']);
});

it('runs the NeoxMed product-data command from saved category discovery JSON', function (): void {
    $categoriesPath = storage_path('app/scrapers/neoxmed/categories-command-test.json');
    $outputPath = storage_path('app/scrapers/neoxmed/product-data-command-test.json');
    @mkdir(dirname($categoriesPath), 0755, true);
    @unlink($categoriesPath);
    @unlink($outputPath);

    file_put_contents($categoriesPath, json_encode([
        'source' => 'neoxmed',
        'categories' => [
            [
                'name' => 'Ortezy barku',
                'slug' => 'ortezy-barku',
                'url' => 'https://neoxmed.com/ortezy-barku/',
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        'https://neoxmed.com/ortezy-barku/' => Http::response(neoxmedCrawlerCategoryFixture(
            category: 'Ortezy barku',
            products: [['B-01', 'Kamizelka stawu barkowego']],
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('neoxmed:crawl-product-data', [
        '--from' => 'scrapers/neoxmed/categories-command-test.json',
        '--save' => 'scrapers/neoxmed/product-data-command-test.json',
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Discovered unique products: 1')
        ->and(is_file($outputPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);

    expect($saved['product_count'])->toBe(1)
        ->and($saved['products'][0]['sku'])->toBe('B-01');

    @unlink($categoriesPath);
    @unlink($outputPath);
});

/**
 * @param  array<int,array{0:string,1:string}>  $products
 */
function neoxmedCrawlerCategoryFixture(string $category, array $products): string
{
    $html = '<!doctype html><html lang="pl"><body><main><h1>'.$category.'</h1>';

    foreach ($products as [$code, $name]) {
        $fileCode = str_replace('-', '_', $code);
        $html .= '<img src="/wp2015/wp-content/uploads/'.$code.'.jpg" alt="'.$code.'">';
        $html .= '<h2>'.$code.' '.$name.'</h2>';
        $html .= '<p>– opis produktu '.$code.'</p>';
        $html .= '<p>Rozmiar uniwersalny</p>';
        $html .= '<img src="/wp2015/wp-content/uploads/'.$fileCode.'.jpg" alt="'.$fileCode.'">';
    }

    return $html.'</main><footer><h4>Kontakt</h4></footer></body></html>';
}
