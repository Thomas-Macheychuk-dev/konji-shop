<?php

use App\Services\Antar\AntarProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers Antar WooCommerce product links across product-page pagination', function (): void {
    Http::fake([
        'https://antar.net/produkty/rehabilitacja/chodziki/' => Http::response(<<<'HTML'
            <html><body>
                <div class="elementor-products-grid elementor-wc-products">
                    <a href="https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">
                        <h2 class="woocommerce-loop-product__title">AT51004 Chodzik stalowy, trzykołowy</h2>
                    </a>
                    <div class="woocommerce-loop-product__buttons">
                        <a href="https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/" class="button product_type_simple" data-product_id="3593" data-product_sku="AT51004">ZOBACZ</a>
                    </div>
                    <a href="https://antar.net/produkt/chodzik-aluminiowy-czterokolowy-at51017/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Chodzik aluminiowy</a>
                    <a href="https://antar.net/produkty/rehabilitacja/chodziki/">Category self-link</a>
                    <a href="https://example.com/produkt/external/">External product</a>
                </div>
                <nav class="woocommerce-pagination">
                    <a class="next page-numbers" href="/produkty/rehabilitacja/chodziki/?product-page=2">→</a>
                </nav>
            </body></html>
            HTML),
        'https://antar.net/produkty/rehabilitacja/chodziki/?product-page=2' => Http::response(<<<'HTML'
            <html><body>
                <ul class="products">
                    <li class="product">
                        <a href="/produkt/chodzik-lekki-aluminiowy-at51034/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Chodzik lekki</a>
                    </li>
                </ul>
                <nav class="woocommerce-pagination"></nav>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://antar.net/produkty/rehabilitacja/chodziki/']);

    expect($result['source'])->toBe('antar')
        ->and($result['visited_urls'])->toBe([
            'https://antar.net/produkty/rehabilitacja/chodziki/',
            'https://antar.net/produkty/rehabilitacja/chodziki/?product-page=2',
        ])
        ->and($result['product_urls'])->toBe([
            'https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/',
            'https://antar.net/produkt/chodzik-aluminiowy-czterokolowy-at51017/',
            'https://antar.net/produkt/chodzik-lekki-aluminiowy-at51034/',
        ])
        ->and($result['products'][0])->toMatchArray([
            'source' => 'antar',
            'url' => 'https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/',
            'external_id' => 'at51004-chodzik-stalowy-trzykolowy',
            'slug' => 'at51004-chodzik-stalowy-trzykolowy',
        ])
        ->and($result['category_results'][0]['product_count'])->toBe(3)
        ->and($result['category_results'][0]['pages_scraped'])->toBe(2)
        ->and($result['failed_urls'])->toBe([]);
});

it('retries transient Antar product-list page failures', function (): void {
    $paginatedPageAttempts = 0;

    Http::fake([
        'https://antar.net/produkty/rehabilitacja/chodziki/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Chodzik</a>
                <nav class="woocommerce-pagination">
                    <a class="next page-numbers" href="/produkty/rehabilitacja/chodziki/page/2/">→</a>
                </nav>
            </body></html>
            HTML),
        'https://antar.net/produkty/rehabilitacja/chodziki/page/2/' => function () use (&$paginatedPageAttempts) {
            $paginatedPageAttempts++;

            if ($paginatedPageAttempts === 1) {
                throw new RuntimeException('cURL error 28: Operation timed out');
            }

            return Http::response(<<<'HTML'
                <html><body>
                    <a href="https://antar.net/produkt/chodzik-lekki-aluminiowy-at51034/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Chodzik lekki</a>
                </body></html>
                HTML);
        },
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrapeCategories(['https://antar.net/produkty/rehabilitacja/chodziki/']);

    expect($paginatedPageAttempts)->toBe(2)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toBe([
            'https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/',
            'https://antar.net/produkt/chodzik-lekki-aluminiowy-at51034/',
        ])
        ->and($result['category_results'][0]['failed_page_count'])->toBe(0);
});

it('discovers Antar product links from a saved category discovery payload', function (): void {
    Http::fake([
        'https://antar.net/produkty/ortopedia/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://antar.net/produkt/orteza-stawu-skokowego-at53001/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Orteza</a>
            </body></html>
            HTML),
        'https://antar.net/produkty/rehabilitacja/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://antar.net/produkt/wozek-inwalidzki-at52301/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Wózek</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'antar',
            'categories' => [
                [
                    'source' => 'antar',
                    'external_category_id' => 'ortopedia',
                    'name' => 'Ortopedia',
                    'url' => 'https://antar.net/produkty/ortopedia/',
                    'path' => ['Ortopedia'],
                    'level' => 1,
                ],
                [
                    'source' => 'antar',
                    'external_category_id' => 'rehabilitacja',
                    'name' => 'Rehabilitacja',
                    'url' => 'https://antar.net/produkty/rehabilitacja/',
                    'path' => ['Rehabilitacja'],
                    'level' => 1,
                ],
            ],
            'product_category_urls' => [
                'https://antar.net/produkty/ortopedia/',
                'https://antar.net/produkty/rehabilitacja/',
            ],
        ], categoryLimit: 1);

    expect($result['source_categories'])->toHaveCount(1)
        ->and($result['source_categories'][0]['name'])->toBe('Ortopedia')
        ->and($result['product_urls'])->toBe([
            'https://antar.net/produkt/orteza-stawu-skokowego-at53001/',
        ]);
});

it('saves Antar product-link discovery JSON from the command', function (): void {
    Http::fake([
        'https://antar.net/produkty/rehabilitacja/chodziki/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/" class="woocommerce-LoopProduct-link woocommerce-loop-product__link">Chodzik</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $path = storage_path('app/scrapers/antar/product-links-test.json');

    if (is_file($path)) {
        unlink($path);
    }

    Artisan::call('antar:product-links', [
        '--category-url' => ['https://antar.net/produkty/rehabilitacja/chodziki/'],
        '--save' => 'scrapers/antar/product-links-test.json',
        '--request-delay-ms' => '0',
        '--no-progress' => true,
    ]);

    expect(Artisan::output())->toContain('Saved product-link discovery result to storage/app/scrapers/antar/product-links-test.json')
        ->and(is_file($path))->toBeTrue();

    $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($data['product_urls'])->toBe([
        'https://antar.net/produkt/at51004-chodzik-stalowy-trzykolowy/',
    ]);
});
