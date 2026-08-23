<?php

declare(strict_types=1);

use App\Services\Zamst\ZamstProductDataCrawler;
use App\Services\Zamst\ZamstProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('extracts Zamst WooCommerce product data and only concrete variation records', function (): void {
    $url = 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/';
    $result = app(ZamstProductScraper::class)->extract(
        zamstProductFixture($url),
        $url,
        [
            'source_categories' => [[
                'external_category_id' => 'stabilizator-kolana-zamst/stabilizator-na-rzepke',
                'name' => 'Stabilizator na rzepkę',
                'url' => 'https://zamst.com.pl/kategoria-produktu/stabilizator-kolana-zamst/stabilizator-na-rzepke/',
                'path' => ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'],
            ]],
            'category_paths' => [
                ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'],
            ],
        ],
    );

    expect($result)->toMatchArray([
        'source' => 'zamst',
        'canonical_url' => $url,
        'external_product_id' => '2164',
        'name' => 'Stabilizator rzepki Zamst JK-2',
        'price_gross_amount' => 289.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'category' => 'Stabilizator na rzepkę',
        'variant_count' => 2,
    ])->and($result['attributes'])->toHaveCount(1)
        ->and($result['attributes'][0]['options'])->toHaveCount(3)
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '2169',
            'price_gross_amount' => 289.0,
            'availability' => 'in_stock',
        ])
        ->and($result['variant_candidates'][0]['attributes'][0])->toMatchArray([
            'code' => 'rozmiar',
            'value' => 's',
            'value_label' => 'S',
        ])
        ->and($result['variant_candidates'][1]['external_variant_id'])->toBe('2168')
        ->and(collect($result['variant_candidates'])->pluck('attributes.0.value')->all())->toBe(['s', 'm'])
        ->and($result['gallery_images'])->toHaveCount(2)
        ->and($result['downloads'][0]['url'])->toBe('https://zamst.com.pl/wp-content/uploads/2020/08/JK-2_PL.pdf')
        ->and($result['videos'][0]['url'])->toBe('https://www.youtube.com/watch?v=jk2-demo')
        ->and($result['warnings'])->toBe([]);

    expect(collect($result['variant_candidates'])->pluck('attributes.0.value')->all())
        ->not->toContain('2xl');
});

it('crawls saved Zamst product links with limit and preserves category context', function (): void {
    $url = 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/';

    Http::fake([
        $url => Http::response(zamstProductFixture($url)),
        '*' => Http::response('', 404),
    ]);

    $result = app(ZamstProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'zamst',
            'product_urls' => [$url],
            'products' => [[
                'url' => $url,
                'source_categories' => [[
                    'external_category_id' => 'ortezy-dla-siatkarzy',
                    'name' => 'Ortezy dla siatkarzy',
                    'url' => 'https://zamst.com.pl/kategoria-produktu/ortezy-dla-siatkarzy/',
                    'path' => ['Ortezy dla siatkarzy'],
                ]],
                'category_paths' => [['Ortezy dla siatkarzy']],
            ]],
        ], limit: 1);

    expect($result['product_count'])->toBe(1)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['products'][0]['external_product_id'])->toBe('2164')
        ->and($result['products'][0]['categories'])->toContain('Ortezy dla siatkarzy');
});

it('saves Zamst product data JSON from the command without importing anything', function (): void {
    $url = 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/';
    $sourceRelativePath = 'scrapers/zamst/product-links-test.json';
    $saveRelativePath = 'scrapers/zamst/product-data-test.json';
    $sourcePath = storage_path('app/'.$sourceRelativePath);
    $savePath = storage_path('app/'.$saveRelativePath);
    @mkdir(dirname($sourcePath), 0755, true);
    @unlink($sourcePath);
    @unlink($savePath);

    file_put_contents($sourcePath, json_encode([
        'source' => 'zamst',
        'product_urls' => [$url],
        'products' => [['url' => $url]],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        $url => Http::response(zamstProductFixture($url)),
        '*' => Http::response('', 404),
    ]);

    $exit = Artisan::call('zamst:crawl-product-data', [
        '--from' => $sourceRelativePath,
        '--limit' => '1',
        '--request-delay-ms' => '0',
        '--no-progress' => true,
        '--save' => $saveRelativePath,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Scraped products: 1')
        ->and(is_file($savePath))->toBeTrue();

    @unlink($sourcePath);
    @unlink($savePath);
});

function zamstProductFixture(string $url): string
{
    $variations = htmlspecialchars(json_encode([
        [
            'attributes' => ['attribute_pa_rozmiar' => 's'],
            'display_price' => 289,
            'display_regular_price' => 289,
            'image' => [
                'full_src' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp',
                'alt' => 'Stabilizator rzepki Zamst JK-2',
                'title' => 'Stabilizator rzepki Zamst JK-2',
            ],
            'is_in_stock' => true,
            'is_purchasable' => true,
            'min_qty' => 1,
            'max_qty' => '',
            'sku' => '',
            'variation_id' => 2169,
            'variation_is_active' => true,
            'variation_is_visible' => true,
        ],
        [
            'attributes' => ['attribute_pa_rozmiar' => 'm'],
            'display_price' => 289,
            'display_regular_price' => 289,
            'image' => [
                'full_src' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01/01-jk-2.webp',
                'alt' => 'JK-2 M',
            ],
            'is_in_stock' => true,
            'is_purchasable' => true,
            'min_qty' => 1,
            'max_qty' => '',
            'sku' => '',
            'variation_id' => 2168,
            'variation_is_active' => true,
            'variation_is_visible' => true,
        ],
    ], JSON_THROW_ON_ERROR), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return <<<HTML
        <!doctype html>
        <html lang="pl-PL">
            <head>
                <title>Stabilizator rzepki Zamst JK-2 - Zamst Polska</title>
                <link rel="canonical" href="{$url}">
                <meta name="description" content="Zamst JK-2 – stabilizator kolana dla sportowców.">
                <script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Stabilizator rzepki Zamst JK-2","sku":2164,"offers":{"@type":"Offer","price":"289.00","priceCurrency":"PLN","availability":"https://schema.org/InStock"}}</script>
            </head>
            <body>
                <div id="product-2164">
                    <div class="woocommerce-product-gallery">
                        <figure class="woocommerce-product-gallery__wrapper">
                            <a href="https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp"><img class="wp-post-image" data-large_image="https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp" alt="Stabilizator rzepki Zamst JK-2"></a>
                            <a href="https://zamst.com.pl/wp-content/uploads/2020/08/02-jk-2.webp"><img data-large_image="https://zamst.com.pl/wp-content/uploads/2020/08/02-jk-2.webp" alt="JK-2 detal"></a>
                        </figure>
                    </div>
                    <div class="summary entry-summary">
                        <h1 class="uk-h2 uk-text-bold">Stabilizator rzepki Zamst JK-2</h1>
                        <p class="price">289,00 zł</p>
                        <form class="uk-form variations_form cart" data-product_id="2164" data-product_variations="{$variations}">
                            <label for="pa_rozmiar">Rozmiar</label>
                            <select id="pa_rozmiar" name="attribute_pa_rozmiar">
                                <option value="">Wybierz opcję</option>
                                <option value="s">S</option>
                                <option value="m">M</option>
                                <option value="2xl">2XL</option>
                            </select>
                            <input type="hidden" name="product_id" value="2164">
                            <input type="hidden" name="variation_id" value="0">
                        </form>
                        <div class="product_meta">
                            <span class="posted_in">Kategorie:
                                <a href="https://zamst.com.pl/kategoria-produktu/ortezy-dla-siatkarzy/" rel="tag">Ortezy dla siatkarzy</a>,
                                <a href="https://zamst.com.pl/kategoria-produktu/stabilizator-kolana-zamst/stabilizator-na-rzepke/" rel="tag">Stabilizator na rzepkę</a>
                            </span>
                        </div>
                    </div>
                    <div class="woocommerce-product-details__short-description">
                        <ul class="uk-switcher">
                            <li>
                                <p>Stabilizator przeznaczony dla aktywnych sportowców.</p>
                                <img src="https://zamst.com.pl/wp-content/uploads/2020/08/jk-2-feature.webp" alt="Technologia JK-2">
                                <a href="https://zamst.com.pl/wp-content/uploads/2020/08/JK-2_PL.pdf">Instrukcja JK-2</a>
                                <a href="https://www.youtube.com/watch?v=jk2-demo">Film JK-2</a>
                            </li>
                            <li><p>Tabela rozmiarów</p></li>
                        </ul>
                    </div>
                </div>
            </body>
        </html>
    HTML;
}
