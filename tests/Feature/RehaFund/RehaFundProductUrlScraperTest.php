<?php

use App\Services\RehaFund\RehaFundProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers RehaFund product links from discovered categories with category context', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => Http::response(<<<'HTML'
            <html><body>
                <main class="main-content-ui">
                    <section class="products-grid-ui">
                        <div class="minibox-product-ui">
                            <a class="product-link-ui" href="krzeslo-toaletowe-bruno-801/3-302-581" aria-label="product-link"></a>
                            <div class="product-caption-ui"><h3 class="product-name-ui">Krzesło toaletowe Bruno</h3></div>
                        </div>
                        <div class="minibox-product-ui">
                            <a class="product-link-ui" href="https://sklep.rehafund.pl/wozek-elektryczny-nanogo/3-463-2189" aria-label="product-link"></a>
                            <div class="product-caption-ui"><h3 class="product-name-ui">Wózek elektryczny inwalidzki NanoGo</h3></div>
                        </div>
                    </section>
                </main>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $categoryDiscovery = [
        'source' => 'rehafund',
        'visited_urls' => ['https://sklep.rehafund.pl/'],
        'failed_urls' => [],
        'product_category_urls' => ['https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284'],
        'categories' => [[
            'name' => 'Rehabilitacja',
            'url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'path' => ['Rehabilitacja'],
        ]],
    ];

    $result = app(RehaFundProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories($categoryDiscovery);

    expect($result['source'])->toBe('rehafund')
        ->and($result['product_urls'])->toBe([
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            'https://sklep.rehafund.pl/wozek-elektryczny-nanogo/3-463-2189',
        ])
        ->and($result['products'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            'name' => 'Krzesło toaletowe Bruno',
            'category_name' => 'Rehabilitacja',
            'category_url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'top_category_name' => 'Rehabilitacja',
            'category_path' => ['Rehabilitacja'],
        ])
        ->and($result['category_results'][0]['product_count'])->toBe(2)
        ->and($result['failed_urls'])->toBe([]);
});

it('ignores RehaFund category, header, footer, and utility links while extracting products', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => Http::response(<<<'HTML'
            <html><body>
                <header><a class="product-link-ui" href="fake-header-product/3-1-1">Header Product</a></header>
                <nav class="category-column-ui">
                    <a class="category-label-ui" href="produkty/rhf-rehabilitacja/2-284">Rehabilitacja</a>
                    <a class="product-link-ui" href="fake-category-product/3-2-2">Category Product</a>
                </nav>
                <main class="main-content-ui">
                    <section class="products-grid-ui">
                        <div class="minibox-product-ui">
                            <a class="product-link-ui" href="podklad-higieniczny-wielorazowy-kolor-niebieski/3-412-1532"></a>
                            <div class="product-caption-ui"><h3 class="product-name-ui">Podkład higieniczny</h3></div>
                        </div>
                        <a href="produkty/rhf-rehabilitacja/2-284">Category</a>
                        <a href="logowanie/7">Login</a>
                        <a href="mailto:sklep@rehafund.pl">Email</a>
                        <a href="https://example.com/product/3-1-1">External</a>
                    </section>
                </main>
                <footer><a class="product-link-ui" href="fake-footer-product/3-3-3">Footer Product</a></footer>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284']);

    expect($result['product_urls'])->toBe([
        'https://sklep.rehafund.pl/podklad-higieniczny-wielorazowy-kolor-niebieski/3-412-1532',
    ]);
});

it('follows RehaFund category pagination links', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => Http::response(<<<'HTML'
            <html><body>
                <main class="main-content-ui">
                    <section class="products-grid-ui">
                        <div class="minibox-product-ui">
                            <a class="product-link-ui" href="krzeslo-toaletowe-bruno-801/3-302-581"></a>
                            <div class="product-caption-ui"><h3 class="product-name-ui">Krzesło toaletowe Bruno</h3></div>
                        </div>
                    </section>
                    <div class="pagination-ui"><a href="produkty/rhf-rehabilitacja/2-284?pageId=2">następna</a></div>
                </main>
            </body></html>
            HTML),
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284?pageId=2' => Http::response(<<<'HTML'
            <html><body>
                <main class="main-content-ui">
                    <section class="products-grid-ui">
                        <div class="minibox-product-ui">
                            <a class="product-link-ui" href="wozek-elektryczny-nanogo/3-463-2189"></a>
                            <div class="product-caption-ui"><h3 class="product-name-ui">Wózek elektryczny inwalidzki NanoGo</h3></div>
                        </div>
                    </section>
                </main>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284']);

    expect($result['product_urls'])->toBe([
        'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
        'https://sklep.rehafund.pl/wozek-elektryczny-nanogo/3-463-2189',
    ])
        ->and($result['category_results'][0]['visited_urls'])->toBe([
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284?pageId=2',
        ]);
});

it('can save RehaFund product link discovery output from a saved category discovery file', function (): void {
    $categoryJsonPath = storage_path('app/scrapers/rehafund/test-categories.json');
    $productLinksJsonPath = storage_path('app/scrapers/rehafund/test-product-links.json');

    if (! is_dir(dirname($categoryJsonPath))) {
        mkdir(dirname($categoryJsonPath), 0755, true);
    }

    @unlink($productLinksJsonPath);

    file_put_contents($categoryJsonPath, json_encode([
        'source' => 'rehafund',
        'visited_urls' => ['https://sklep.rehafund.pl/'],
        'failed_urls' => [],
        'product_category_urls' => ['https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284'],
        'categories' => [[
            'name' => 'Rehabilitacja',
            'url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'path' => ['Rehabilitacja'],
        ]],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => Http::response(<<<'HTML'
            <html><body>
                <main class="main-content-ui">
                    <section class="products-grid-ui">
                        <div class="minibox-product-ui">
                            <a class="product-link-ui" href="krzeslo-toaletowe-bruno-801/3-302-581"></a>
                            <div class="product-caption-ui"><h3 class="product-name-ui">Krzesło toaletowe Bruno</h3></div>
                        </div>
                    </section>
                </main>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('rehafund:product-links', [
            '--categories-from' => 'scrapers/rehafund/test-categories.json',
            '--save' => 'scrapers/rehafund/test-product-links.json',
            '--no-progress' => true,
            '--request-delay-ms' => '0',
        ])
        ->assertExitCode(0);

    expect(is_file($productLinksJsonPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($productLinksJsonPath), true, flags: JSON_THROW_ON_ERROR);

    @unlink($categoryJsonPath);
    @unlink($productLinksJsonPath);

    expect($saved['source'])->toBe('rehafund')
        ->and($saved['product_urls'])->toBe([
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
        ]);
});

it('records failed RehaFund product link category requests', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284']);

    expect($result['product_urls'])->toBe([])
        ->and($result['category_results'][0]['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => 'HTTP 500',
        ]);
});
