<?php

use App\Services\Medi\MediProductUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers medi Magento product links across p pagination', function (): void {
    Http::fake([
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html' => Http::response(<<<'HTML'
            <html><body>
                <div class="products wrapper grid products-grid">
                    <ol class="products list items product-items">
                        <li class="item product product-item">
                            <div class="product-item-info">
                                <a class="product photo product-item-photo" href="/shop/mediven-thrombexin-18-po-czochy-przeciwzakrzepowe.html?utm_source=test">Image</a>
                                <strong class="product name product-item-name">
                                    <a class="product-item-link" href="https://www.medi-polska.pl/shop/mediven-thrombexin-18-po-czochy-przeciwzakrzepowe.html">mediven Thrombexin</a>
                                </strong>
                            </div>
                        </li>
                        <li class="item product product-item">
                            <div class="product-item-info">
                                <a class="product-item-link" href="https://www.medi-polska.pl/shop/medi-travel-dla-mezczyzn.html#details">medi travel</a>
                                <a href="https://example.com/shop/external.html" class="product-item-link">External</a>
                            </div>
                        </li>
                    </ol>
                </div>
                <div class="toolbar toolbar-products">
                    <div class="pages">
                        <ul class="items pages-items">
                            <li class="item pages-item-next">
                                <a class="action next" href="?p=2&amp;product_list_order=name">Next</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </body></html>
            HTML),
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html?p=2' => Http::response(<<<'HTML'
            <html><body>
                <div class="products wrapper grid products-grid">
                    <ol class="products list items product-items">
                        <li class="item product product-item">
                            <div class="product-item-info">
                                <a class="product-item-link" href="/shop/duomed-smooth-podkolanowki.html">duomed smooth</a>
                            </div>
                        </li>
                    </ol>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MediProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeCategories(['https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html']);

    expect($result['source'])->toBe('medi')
        ->and($result['visited_urls'])->toBe([
            'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html?p=2',
        ])
        ->and($result['product_urls'])->toBe([
            'https://www.medi-polska.pl/shop/duomed-smooth-podkolanowki.html',
            'https://www.medi-polska.pl/shop/medi-travel-dla-mezczyzn.html',
            'https://www.medi-polska.pl/shop/mediven-thrombexin-18-po-czochy-przeciwzakrzepowe.html',
        ])
        ->and($result['products'][0])->toMatchArray([
            'source' => 'medi',
            'url' => 'https://www.medi-polska.pl/shop/duomed-smooth-podkolanowki.html',
            'external_id' => 'duomed-smooth-podkolanowki',
            'slug' => 'duomed-smooth-podkolanowki',
        ])
        ->and($result['category_results'][0]['product_count'])->toBe(3)
        ->and($result['category_results'][0]['pages_scraped'])->toBe(2)
        ->and($result['failed_urls'])->toBe([]);
});

it('deduplicates medi products found in overlapping categories', function (): void {
    Http::fake([
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html' => Http::response(<<<'HTML'
            <html><body>
                <div class="products-grid">
                    <div class="product-item-info">
                        <a class="product-item-link" href="/shop/duomed-podkolanowki.html">duomed</a>
                    </div>
                </div>
            </body></html>
            HTML),
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/podkolanowki-uciskowe.html' => Http::response(<<<'HTML'
            <html><body>
                <div class="products-grid">
                    <div class="product-item-info">
                        <a class="product-item-link" href="/shop/duomed-podkolanowki.html">duomed</a>
                        <a class="product-item-link" href="/shop/mediven-comfort-podkolanowki.html">mediven comfort</a>
                    </div>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MediProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'medi',
            'categories' => [
                [
                    'source' => 'medi',
                    'external_category_id' => 'kompresja',
                    'name' => 'Kompresja',
                    'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
                    'path' => ['Kompresja'],
                    'level' => 1,
                ],
                [
                    'source' => 'medi',
                    'external_category_id' => 'kompresja/podkolanowki-uciskowe',
                    'name' => 'Podkolanówki uciskowe',
                    'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/podkolanowki-uciskowe.html',
                    'path' => ['Kompresja', 'Podkolanówki uciskowe'],
                    'level' => 2,
                ],
            ],
        ]);

    expect($result['source_categories'])->toHaveCount(2)
        ->and($result['category_results'])->toHaveCount(2)
        ->and($result['category_results'][0]['product_count'])->toBe(1)
        ->and($result['category_results'][1]['product_count'])->toBe(2)
        ->and($result['product_urls'])->toBe([
            'https://www.medi-polska.pl/shop/duomed-podkolanowki.html',
            'https://www.medi-polska.pl/shop/mediven-comfort-podkolanowki.html',
        ]);
});

it('retries transient medi product-list page failures', function (): void {
    $pageAttempts = 0;

    Http::fake([
        'https://www.medi-polska.pl/shop/kategoria-produktu/ortopedia.html' => function () use (&$pageAttempts) {
            $pageAttempts++;

            if ($pageAttempts === 1) {
                throw new RuntimeException('cURL error 28: Operation timed out');
            }

            return Http::response(<<<'HTML'
                <html><body>
                    <div class="products-grid">
                        <div class="product-item-info">
                            <a class="product-item-link" href="/shop/genumedi-stabilizator-kolana.html">Genumedi</a>
                        </div>
                    </div>
                </body></html>
                HTML);
        },
        '*' => Http::response('', 404),
    ]);

    $result = app(MediProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrapeCategories(['https://www.medi-polska.pl/shop/kategoria-produktu/ortopedia.html']);

    expect($pageAttempts)->toBe(2)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toBe([
            'https://www.medi-polska.pl/shop/genumedi-stabilizator-kolana.html',
        ]);
});

it('skips the known medi redirect-loop category without carrying it as a failure', function (): void {
    Http::fake([
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/rajstopy-uciskowe.html' => Http::response(<<<'HTML'
            <html><body>
                <div class="products-grid">
                    <div class="product-item-info">
                        <a class="product-item-link" href="/shop/mediven-plus-rajstopy.html">mediven plus</a>
                    </div>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 500),
    ]);

    $brokenUrl = 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/rajstopy-uciskowe-meskie.html';

    $result = app(MediProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeFromDiscoveredCategories([
            'source' => 'medi',
            'categories' => [
                [
                    'external_category_id' => 'kompresja/rajstopy-uciskowe',
                    'name' => 'Rajstopy uciskowe',
                    'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/rajstopy-uciskowe.html',
                    'path' => ['Kompresja', 'Rajstopy uciskowe'],
                    'level' => 2,
                ],
                [
                    'external_category_id' => 'kompresja/rajstopy-uciskowe-meskie',
                    'name' => 'Rajstopy Uciskowe Męskie',
                    'url' => $brokenUrl,
                    'path' => ['Kompresja', 'Rajstopy Uciskowe Męskie'],
                    'level' => 2,
                ],
            ],
            'failed_urls' => [
                $brokenUrl => 'Will not follow more than 5 redirects',
            ],
        ]);

    expect($result['source_categories'])->toHaveCount(2)
        ->and($result['skipped_categories'])->toHaveCount(1)
        ->and($result['skipped_categories'][0])->toMatchArray([
            'url' => $brokenUrl,
            'name' => 'Rajstopy Uciskowe Męskie',
        ])
        ->and($result['category_results'][1]['skipped'])->toBeTrue()
        ->and($result['category_results'][1]['pages_scraped'])->toBe(0)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['product_urls'])->toBe([
            'https://www.medi-polska.pl/shop/mediven-plus-rajstopy.html',
        ]);

    Http::assertNotSent(fn ($request): bool => $request->url() === $brokenUrl);
});

it('saves medi product-link discovery JSON from the command', function (): void {
    Http::fake([
        'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html' => Http::response(<<<'HTML'
            <html><body>
                <div class="products-grid">
                    <div class="product-item-info">
                        <a class="product-item-link" href="/shop/medi-rekawiczki-gumowe.html">Rękawiczki</a>
                    </div>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $categoryPath = storage_path('app/scrapers/medi/categories-product-links-test.json');
    $resultPath = storage_path('app/scrapers/medi/product-links-test.json');

    if (! is_dir(dirname($categoryPath))) {
        mkdir(dirname($categoryPath), 0755, true);
    }

    file_put_contents($categoryPath, json_encode([
        'source' => 'medi',
        'categories' => [
            [
                'external_category_id' => 'akcesoria',
                'name' => 'Akcesoria',
                'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html',
                'path' => ['Akcesoria'],
                'level' => 1,
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    if (is_file($resultPath)) {
        unlink($resultPath);
    }

    Artisan::call('medi:product-links', [
        '--categories-from' => 'scrapers/medi/categories-product-links-test.json',
        '--save' => 'scrapers/medi/product-links-test.json',
        '--request-delay-ms' => '0',
        '--no-progress' => true,
    ]);

    expect(Artisan::output())->toContain('Saved product-link discovery result to storage/app/scrapers/medi/product-links-test.json')
        ->and(is_file($resultPath))->toBeTrue();

    $data = json_decode((string) file_get_contents($resultPath), true, flags: JSON_THROW_ON_ERROR);

    expect($data['product_urls'])->toBe([
        'https://www.medi-polska.pl/shop/medi-rekawiczki-gumowe.html',
    ]);

    unlink($categoryPath);
    unlink($resultPath);
});
