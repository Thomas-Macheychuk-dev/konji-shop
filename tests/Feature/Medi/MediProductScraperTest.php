<?php

use App\Services\Medi\MediProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('extracts medi Magento configurable product data and exact child variants', function (): void {
    $html = mediMagentoProductFixture();

    $result = app(MediProductScraper::class)->extract(
        $html,
        'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
    );

    expect($result)->toMatchArray([
        'source' => 'medi',
        'source_url' => 'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
        'canonical_url' => 'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
        'external_product_id' => '1029449',
        'slug' => 'mediven-elegance-ponczochy-uciskowe',
        'name' => 'mediven® elegance',
        'subtitle' => 'pończochy uciskowe',
        'brand' => [
            'name' => 'medi',
            'slug' => 'medi',
        ],
        'price_gross_amount' => 330.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Wysyłka w ciągu 1-3 dni roboczych',
        'shipping_time' => '1-3 dni roboczych',
        'stock_quantity' => 3,
        'sku' => '010111007',
        'variant_count' => 2,
        'available_variant_count' => 1,
        'is_medical_device' => true,
        'failed_urls' => [],
    ])
        ->and($result['description_html'])->toContain('To jest wyrób medyczny')
        ->and($result['images'])->toBe([
            [
                'url' => 'https://s7e5a.scene7.com/is/image/medi/elegance-main?$Product-medical-2to3$',
                'alt' => 'main product photo',
            ],
            [
                'url' => 'https://s7e5a.scene7.com/is/image/medi/elegance-navy?$Product-medical-2to3$',
                'alt' => 'granatowy',
            ],
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'color',
            'label' => 'Kolor',
            'value' => 'granatowy | beżowy',
            'slug' => 'granatowy-bezowy',
        ])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '101',
            'sku' => 'MEDI-NAVY-S',
            'label' => 'Kolor: granatowy / Rozmiar: S',
            'price_gross_amount' => 330.0,
            'currency' => 'PLN',
            'availability' => 'in_stock',
            'stock_quantity' => 3,
        ])
        ->and($result['variant_candidates'][0]['attributes'])->toBe([
            [
                'code' => 'color',
                'external_attribute_id' => '454',
                'external_option_id' => '1997',
                'label' => 'Kolor',
                'value' => 'granatowy',
            ],
            [
                'code' => 'size',
                'external_attribute_id' => '506',
                'external_option_id' => '3751',
                'label' => 'Rozmiar',
                'value' => 'S',
            ],
        ])
        ->and($result['variant_candidates'][1])->toMatchArray([
            'external_variant_id' => '102',
            'sku' => 'MEDI-BEIGE-M',
            'availability' => 'out_of_stock',
            'stock_quantity' => 0,
        ])
        ->and($result['warnings'])->toBe([]);
});

it('extracts a simple medi accessory without inventing variants or medical status', function (): void {
    $html = mediMagentoProductFixture(
        configurable: false,
        name: 'medi Butler Off',
        subtitle: 'pomoc do zdejmowania pończoch',
        productId: '900001',
        sku: 'BUTLER-OFF',
        price: 119.0,
        description: 'Praktyczna pomoc do zdejmowania produktów uciskowych.',
    );

    $result = app(MediProductScraper::class)->extract(
        $html,
        'https://www.medi-polska.pl/shop/medi-butler-off.html',
    );

    expect($result)->toMatchArray([
        'external_product_id' => '900001',
        'name' => 'medi Butler Off',
        'sku' => 'BUTLER-OFF',
        'price_gross_amount' => 119.0,
        'variant_candidates' => [],
        'variant_count' => 0,
        'is_medical_device' => false,
    ])->and($result['warnings'])->toBe([]);
});

it('keeps medi product-link category context on scraped products', function (): void {
    $result = app(MediProductScraper::class)->extract(
        mediMagentoProductFixture(),
        'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
        [
            'url' => 'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
            'external_id' => 'mediven-elegance-ponczochy-uciskowe',
            'source_category_name' => 'Pończochy uciskowe',
            'source_category_url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/ponczochy-uciskowe.html',
            'source_top_category_name' => 'Kompresja',
            'source_top_category_url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
            'source_category_path' => ['Kompresja', 'Pończochy uciskowe'],
        ],
    );

    expect($result)->toMatchArray([
        'category' => 'Pończochy uciskowe',
        'source_category_name' => 'Pończochy uciskowe',
        'source_category_url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/ponczochy-uciskowe.html',
        'source_top_category_name' => 'Kompresja',
        'source_top_category_url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
        'source_category_path' => ['Kompresja', 'Pończochy uciskowe'],
    ]);
});

it('retries temporary medi product failures and records terminal failures', function (): void {
    $attempts = 0;

    Http::fake([
        'https://www.medi-polska.pl/shop/retry-product.html' => function () use (&$attempts) {
            $attempts++;

            if ($attempts === 1) {
                throw new RuntimeException('cURL error 28: Operation timed out');
            }

            return Http::response(mediMagentoProductFixture());
        },
        'https://www.medi-polska.pl/shop/missing-product.html' => Http::response('', 404),
        '*' => Http::response('', 500),
    ]);

    $scraper = app(MediProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetry(2, 0);

    $retried = $scraper->scrape('https://www.medi-polska.pl/shop/retry-product.html');
    $missing = $scraper->scrape('https://www.medi-polska.pl/shop/missing-product.html');

    expect($attempts)->toBe(2)
        ->and($retried['name'])->toBe('mediven® elegance')
        ->and($retried['failed_urls'])->toBe([])
        ->and($missing['name'])->toBe('')
        ->and($missing['failed_urls'])->toBe([
            'https://www.medi-polska.pl/shop/missing-product.html' => 'HTTP 404',
        ]);
});

it('crawls saved medi product links with category context and saves product data JSON', function (): void {
    $sourcePath = storage_path('app/scrapers/medi/product-links-product-data-test.json');
    $resultPath = storage_path('app/scrapers/medi/product-data-test.json');

    if (! is_dir(dirname($sourcePath))) {
        mkdir(dirname($sourcePath), 0755, true);
    }

    file_put_contents($sourcePath, json_encode([
        'source' => 'medi',
        'product_urls' => [
            'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
        ],
        'products' => [
            [
                'source' => 'medi',
                'url' => 'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
                'external_id' => 'mediven-elegance-ponczochy-uciskowe',
            ],
        ],
        'category_results' => [
            [
                'name' => 'Pończochy uciskowe',
                'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/ponczochy-uciskowe.html',
                'category_path' => ['Kompresja', 'Pończochy uciskowe'],
                'product_urls' => [
                    'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    Http::fake([
        'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html' => Http::response(mediMagentoProductFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('medi:crawl-product-data', [
        '--from' => 'scrapers/medi/product-links-product-data-test.json',
        '--save' => 'scrapers/medi/product-data-test.json',
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
        '--no-progress' => true,
    ]);

    expect($exitCode)->toBe(0);

    expect(is_file($resultPath))->toBeTrue();

    $saved = json_decode(
        (string) file_get_contents($resultPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($saved)->toMatchArray([
        'source' => 'medi',
        'product_count' => 1,
        'source_product_url_count' => 1,
        'failed_urls' => [],
    ])
        ->and($saved['products'][0]['source_category_path'])->toBe(['Kompresja', 'Pończochy uciskowe'])
        ->and($saved['products'][0]['variant_count'])->toBe(2);

    unlink($sourcePath);
    unlink($resultPath);
});

function mediMagentoProductFixture(
    bool $configurable = true,
    string $name = 'mediven® elegance',
    string $subtitle = 'pończochy uciskowe',
    string $productId = '1029449',
    string $sku = '010111007',
    float $price = 330.0,
    string $description = 'Okrągłodziany medyczny produkt kompresyjny. To jest wyrób medyczny.',
): string {
    $canonical = $sku === '010111007'
        ? 'https://www.medi-polska.pl/shop/mediven-elegance-ponczochy-uciskowe.html'
        : 'https://www.medi-polska.pl/shop/medi-butler-off.html';

    $config = $configurable ? [
        'attributes' => [
            '454' => [
                'id' => '454',
                'code' => 'color',
                'label' => 'Kolor',
                'position' => '0',
                'options' => [
                    ['id' => '1997', 'label' => 'granatowy', 'products' => ['101']],
                    ['id' => '2000', 'label' => 'beżowy', 'products' => ['102']],
                ],
            ],
            '506' => [
                'id' => '506',
                'code' => 'size',
                'label' => 'Rozmiar',
                'position' => '1',
                'options' => [
                    ['id' => '3751', 'label' => 'S', 'products' => ['101']],
                    ['id' => '3755', 'label' => 'M', 'products' => ['102']],
                ],
            ],
        ],
        'optionPrices' => [
            '101' => [
                'oldPrice' => ['amount' => 330],
                'finalPrice' => ['amount' => 330],
            ],
            '102' => [
                'oldPrice' => ['amount' => 340],
                'finalPrice' => ['amount' => 340],
            ],
        ],
        'prices' => [
            'finalPrice' => ['amount' => $price],
        ],
        'productId' => $productId,
        'images' => [
            '101' => [
                [
                    'full' => 'https://s7e5a.scene7.com/is/image/medi/elegance-navy?$Product-medical-2to3$',
                    'caption' => 'granatowy',
                    'isMain' => true,
                ],
            ],
        ],
        'index' => [
            '101' => ['454' => '1997', '506' => '3751'],
            '102' => ['454' => '2000', '506' => '3755'],
        ],
        'salable' => [
            '454' => ['1997' => ['101']],
            '506' => ['3751' => ['101']],
        ],
        'sku' => [
            '101' => 'MEDI-NAVY-S',
            '102' => 'MEDI-BEIGE-M',
        ],
        'quantities' => [
            '101' => 3,
            '102' => 0,
        ],
    ] : [];

    $magentoInit = $configurable ? json_encode([
        '[data-role=swatch-options]' => [
            'Magento_Swatches/js/swatch-renderer' => [
                'jsonConfig' => $config,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '';

    $structuredData = json_encode([
        '@context' => 'https://schema.org/',
        '@type' => 'ItemPage',
        'mainEntity' => [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $name,
            'sku' => $sku,
            'description' => $description,
            'image' => 'https://s7e5a.scene7.com/is/image/medi/elegance-main?$Product-medical-2to3$',
            'brand' => ['@type' => 'Brand', 'name' => 'medi'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $bodyClass = $configurable ? 'page-product-configurable catalog-product-view' : 'page-product-simple catalog-product-view';

    return <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>{$name} | medi sklep internetowy</title>
            <link rel="canonical" href="{$canonical}">
            <meta name="description" content="Opis SEO produktu medi.">
            <meta property="og:description" content="{$subtitle}">
            <meta property="og:image" content="https://s7e5a.scene7.com/is/image/medi/elegance-main?\$Product-medical-2to3\$">
            <meta property="product:price:amount" content="{$price}">
            <meta property="product:price:currency" content="PLN">
            <script type="application/ld+json">{$structuredData}</script>
        </head>
        <body class="{$bodyClass}">
            <div class="breadcrumbs"><ul class="items"><li class="item home">Strona główna</li><li class="item">Kompresja</li></ul></div>
            <div class="gallery-placeholder" data-gallery-role="gallery-placeholder">
                <img class="gallery-placeholder__image" alt="main product photo" src="https://s7e5a.scene7.com/is/image/medi/elegance-main?\$Product-medical-2to3\$&amp;wid=420&amp;hei=630">
            </div>
            <div class="product-info-main">
                <h1 class="product-title">{$name}<span class="subtitle">{$subtitle}</span></h1>
                <div class="product-info-price"><span data-price-type="finalPrice" data-price-amount="{$price}"></span></div>
                <form id="product_addtocart_form" data-product-sku="{$sku}">
                    <input type="hidden" name="product" value="{$productId}">
                </form>
                <div class="stock available inStock"><span class="text">Wysyłka w ciągu 1-3 dni roboczych</span></div>
            </div>
            <div class="product info detailed">
                <div class="product data items">
                    <h2 id="tab-label-description"><a id="tab-label-description-title">Opis</a></h2>
                    <div class="data item content" id="description">
                        <div class="product attribute description"><div class="value"><p>{$description}</p></div></div>
                    </div>
                </div>
            </div>
            <script type="text/x-magento-init">{$magentoInit}</script>
        </body>
        </html>
        HTML;
}
