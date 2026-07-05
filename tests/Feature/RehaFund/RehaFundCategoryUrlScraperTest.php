<?php

use App\Services\RehaFund\RehaFundCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts RehaFund categories from the Comarch category sidebar', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/' => Http::response(<<<'HTML'
            <html><body>
                <nav class="category-column-ui">
                    <div class="category-content-ui">
                        <div class="category-links-ui clear-after-ui first-level-category-js has-nodes-js">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/rhf-rehabilitacja/2-284">
                                <span class="category-name-ui">Rehabilitacja</span>
                                <small class="category-amount-ui">(103)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-js has-nodes-js">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/rhf-rehabilitacja/materace-przeciwodlezynowe-pneumatyczne/2-292">
                                <span class="category-name-ui">Materace przeciwodleżynowe pneumatyczne</span>
                                <small class="category-amount-ui">(7)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-js">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="https://sklep.rehafund.pl/produkty/tlenoterapia/2-286">
                                <span class="category-name-ui">Tlenoterapia</span>
                                <small class="category-amount-ui">(69)</small>
                            </a>
                        </div>
                        <a class="product-link-ui" href="standardowy-wozek-inwalidzki-elektryczny/3-463-2110">Product</a>
                        <a href="produkty/2">Category root</a>
                        <a href="logowanie/7">Login</a>
                        <a href="https://example.com/produkty/test/2-1">External</a>
                    </div>
                </nav>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['source'])->toBe('rehafund')
        ->and($result['category_urls'])->toBe([
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/materace-przeciwodlezynowe-pneumatyczne/2-292',
            'https://sklep.rehafund.pl/produkty/tlenoterapia/2-286',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['top_categories'])->toHaveCount(2)
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'rehafund',
        'external_category_id' => '284',
        'name' => 'Rehabilitacja',
        'source_name' => 'Rehabilitacja',
        'url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
        'slug' => 'rehabilitacja',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Rehabilitacja'],
        'product_count' => 103,
    ]);

    expect($result['categories'][1])->toMatchArray([
        'external_category_id' => '292',
        'name' => 'Materace przeciwodleżynowe pneumatyczne',
        'level' => 2,
        'parent_external_category_id' => '284',
        'path' => ['Rehabilitacja', 'Materace przeciwodleżynowe pneumatyczne'],
        'product_count' => 7,
    ]);
});

it('follows discovered RehaFund category pages to discover nested categories', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/' => Http::response(<<<'HTML'
            <html><body>
                <nav class="category-column-ui">
                    <a class="category-label-ui" href="produkty/rhf-rehabilitacja/2-284">
                        <span class="category-name-ui">Rehabilitacja</span>
                    </a>
                </nav>
            </body></html>
            HTML),
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284' => Http::response(<<<'HTML'
            <html><body>
                <nav class="category-column-ui">
                    <a class="category-label-ui" href="produkty/rhf-rehabilitacja/2-284">
                        <span class="category-name-ui">Rehabilitacja</span>
                    </a>
                    <a class="category-label-ui" href="produkty/rhf-rehabilitacja/wozki-inwalidzkie/2-296">
                        <span class="category-name-ui">Wózki inwalidzkie</span>
                    </a>
                </nav>
            </body></html>
            HTML),
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/wozki-inwalidzkie/2-296' => Http::response(<<<'HTML'
            <html><body>
                <nav class="category-column-ui">
                    <a class="category-label-ui" href="produkty/rhf-rehabilitacja/wozki-inwalidzkie/2-296">
                        <span class="category-name-ui">Wózki inwalidzkie</span>
                    </a>
                </nav>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundCategoryUrlScraper::class)
        ->withMaxPages(5)
        ->scrape();

    expect($result['visited_urls'])->toBe([
        'https://sklep.rehafund.pl/',
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
        'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/wozki-inwalidzkie/2-296',
    ])
        ->and($result['category_urls'])->toBe([
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/wozki-inwalidzkie/2-296',
        ])
        ->and($result['categories'][1])->toMatchArray([
            'name' => 'Wózki inwalidzkie',
            'level' => 2,
            'parent_external_category_id' => '284',
            'path' => ['Rehabilitacja', 'Wózki inwalidzkie'],
        ]);
});

it('records failed RehaFund category discovery URLs', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://sklep.rehafund.pl/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://sklep.rehafund.pl/' => 'HTTP 500',
        ]);
});
