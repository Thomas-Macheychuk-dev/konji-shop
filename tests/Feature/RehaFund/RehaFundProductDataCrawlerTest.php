<?php

use App\Services\RehaFund\RehaFundProductDataCrawler;
use App\Services\RehaFund\RehaFundProductScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

it('extracts RehaFund product details from a product page', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Siedzisko do podnośnika kąpielowe - Nosidła do podnośnika</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002" />
                <meta name="description" content="Siedzisko kąpielowe do podnośnika rehabilitacyjnego." />
            </head>
            <body>
                <header><img src="logo.png" alt="logo"></header>
                <nav class="category-column-ui">
                    <a href="produkty/rhf-rehabilitacja/2-284">Rehabilitacja</a>
                </nav>
                <main class="main-content-ui">
                    <div class="breadcrumbs-ui">
                        <a href="./">Produkty</a>
                        <a href="produkty/rhf-rehabilitacja/2-284">Rehabilitacja</a>
                        <span>Siedzisko do podnośnika kąpielowe</span>
                    </div>
                    <article class="product-presentation-ui" data-product-id="1002">
                        <h1>Siedzisko do podnośnika kąpielowe</h1>
                        <div class="product-code-ui">RF-1012/M</div>
                        <div class="product-gallery-ui">
                            <img alt="RF-1012-siedzisko" data-src="img/large/4854886/siedzisko-do-podnosnika-kapielowe" />
                            <img alt="RF-1012-siedzisko bok" data-src="img/large/4854887/siedzisko-do-podnosnika-kapielowe-bok" />
                            <img alt="loader" src="css/img/alo.gif" />
                        </div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <div class="stock-ui">W magazynie: bardzo dużo</div>
                        <select name="Rozmiar">
                            <option>M</option>
                            <option>L</option>
                        </select>
                        <table class="product-features-ui">
                            <tr><th>Podatek VAT</th><td>8,00%</td></tr>
                            <tr><th>Kod EAN</th><td>5903940707792</td></tr>
                            <tr><th>Symbol</th><td>RF-1012/M</td></tr>
                            <tr><th>Klasa wyrobu</th><td>I</td></tr>
                        </table>
                        <section class="product-description-ui">
                            <h2>Opis towaru</h2>
                            <p>Siedzisko służy jako wyposażenie pomocnicze przy korzystaniu z podnośnika rehabilitacyjnego.</p>
                            <p>Producent: Reha Fund sp. z o. o.</p>
                            <a href="https://sklep.rehafund.pl/file/1">Pobierz</a>
                        </section>
                    </article>
                </main>
                <footer>Kontakt</footer>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002',
        [
            'category_name' => 'Rehabilitacja',
            'category_url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'category_path' => ['Rehabilitacja'],
        ],
    );

    expect($product)->toMatchArray([
        'source' => 'rehafund',
        'source_url' => 'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002',
        'canonical_url' => 'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002',
        'external_product_id' => '1002',
        'slug' => 'siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012',
        'name' => 'Siedzisko do podnośnika kąpielowe',
        'category' => 'Rehabilitacja',
        'sku' => 'RF-1012/M',
        'ean' => '5903940707792',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępność: Od ręki',
        'is_medical_device' => true,
    ])
        ->and($product['description_html'])->not->toContain('href=')
        ->and($product['attributes'])->toContain([
            'code' => 'kod-ean',
            'label' => 'Kod EAN',
            'value' => '5903940707792',
            'slug' => '5903940707792',
        ])
        ->and($product['images'])->toHaveCount(2)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/large/4854886/siedzisko-do-podnosnika-kapielowe',
            'alt' => 'RF-1012-siedzisko',
            'sort_order' => 0,
        ])
        ->and($product['variant_candidates'])->toHaveCount(2)
        ->and($product['variant_candidates'][0])->toMatchArray([
            'name' => 'M',
            'attributes' => [[
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => 'M',
                'slug' => 'm',
            ]],
        ])
        ->and($product['failed_urls'])->toBe([])
        ->and($product['warnings'])->toBe([]);
});



it('does not treat RehaFund free delivery thresholds as product prices', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Balkonik rehabilitacyjny Luca 803/1</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/balkonik-rehabilitacyjny-luca-803-1/3-323-649" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="649">
                        <h1>Balkonik rehabilitacyjny Luca 803/1</h1>
                        <p class="delivery-info-ui">Darmowa dostawa od 2500 zł</p>
                        <div class="availability-ui">Dostępność: Niedostępny</div>
                        <table><tr><th>Kod EAN</th><td>5903940700649</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/balkonik-rehabilitacyjny-luca-803-1/3-323-649',
    );

    expect($product['price_gross_amount'])->toBeNull();
});

it('does not treat RehaFund delivery fees as product prices', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Wózek inwalidzki Cruiser Areo</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/wozek-inwalidzki-cruiser-areo-szer-42-cm-kola-t/3-337-1118" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="1118">
                        <h1>Wózek inwalidzki Cruiser Areo</h1>
                        <div class="delivery-info-ui">Dostawa już od 5 zł</div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>RF-6/42/PN/CZA-624/PU</td></tr><tr><th>Kod EAN</th><td>5903940710020</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/wozek-inwalidzki-cruiser-areo-szer-42-cm-kola-t/3-337-1118',
    );

    expect($product['price_gross_amount'])->toBeNull();
});

it('extracts explicit RehaFund product prices when they are visible in the product scope', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Balkonik RF-100</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/balkonik-rf-100-kolor-czarny/3-323-1258" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="1258">
                        <h1>Balkonik RF-100</h1>
                        <p class="delivery-info-ui">Darmowa dostawa od 2500 zł</p>
                        <div class="product-price-ui">Cena: 349,99 zł</div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>RF-100/CZA</td></tr><tr><th>Kod EAN</th><td>5903940712581</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/balkonik-rf-100-kolor-czarny/3-323-1258',
    );

    expect($product['price_gross_amount'])->toBe('349.99');
});


it('extracts RehaFund product images from Comarch data-lazy gallery attributes without size folders', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Standardowy wózek inwalidzki elektryczny</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/standardowy-wozek-inwalidzki-elektryczny/3-463-2110" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="2110">
                        <h1>Standardowy wózek inwalidzki elektryczny</h1>
                        <div class="product-code-ui">DH01109/45/PN/N</div>
                        <div class="single-image-slider-ui single-image-slider-js slider-lq">
                            <figure class="open-gallery-lq">
                                <img src="css/img/alo.gif" alt="udzwig 136kg (1)" class="open-gallery-lq" data-lazy="img/9653749/standardowy-wozek-inwalidzki-elektryczny" />
                            </figure>
                            <figure class="open-gallery-lq">
                                <img src="css/img/alo.gif" alt="DH01109-02-C-cien" class="open-gallery-lq" data-lazy="img/9730093/standardowy-wozek-inwalidzki-elektryczny" />
                            </figure>
                        </div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>DH01109/45/PN/N</td></tr><tr><th>Kod EAN</th><td>5903940712970</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/standardowy-wozek-inwalidzki-elektryczny/3-463-2110',
    );

    expect($product['images'])->toHaveCount(2)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/9653749/standardowy-wozek-inwalidzki-elektryczny',
            'alt' => 'udzwig 136kg (1)',
            'sort_order' => 0,
        ])
        ->and($product['images'][1])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/9730093/standardowy-wozek-inwalidzki-elektryczny',
            'alt' => 'DH01109-02-C-cien',
            'sort_order' => 1,
        ])
        ->and($product['warnings'])->toBe([]);
});

it('does not pull unrelated RehaFund data-lazy recommendation images into the product gallery', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Podkład higieniczny</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/podklad-higieniczny-wielorazowy-kolor-niebieski/3-412-1532" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="1532">
                        <h1>Podkład higieniczny</h1>
                        <div class="product-code-ui">3045-1/N</div>
                        <div class="single-image-slider-ui single-image-slider-js slider-lq">
                            <figure class="open-gallery-lq">
                                <img src="css/img/alo.gif" alt="3045" class="open-gallery-lq" data-lazy="img/366732/podklad-higieniczny-wielorazowy-kolor-niebieski" />
                            </figure>
                        </div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>3045-1/N</td></tr><tr><th>Kod EAN</th><td>5903940703213</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                    <section class="recommended-products-ui">
                        <img src="css/img/alo.gif" data-lazy="img/2824701/balkonik-rf-131-kolor-czarny-rf-131-cza" alt="Balkonik RF-131" />
                    </section>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/podklad-higieniczny-wielorazowy-kolor-niebieski/3-412-1532',
    );

    expect($product['images'])->toHaveCount(1)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/366732/podklad-higieniczny-wielorazowy-kolor-niebieski',
            'alt' => '3045',
            'sort_order' => 0,
        ])
        ->and($product['images'][0]['url'])->not->toContain('balkonik-rf-131')
        ->and($product['warnings'])->toBe([]);
});

it('extracts RehaFund product images from gallery anchor hrefs when image tags use placeholders', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Siedzisko do podnośnika kąpielowe</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-342-1002" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="1002">
                        <h1>Siedzisko do podnośnika kąpielowe</h1>
                        <div class="product-code-ui">RF-1012/M</div>
                        <div class="product-gallery-ui">
                            <a href="/img/large/4854886/rf-1012-siedzisko" title="RF-1012-siedzisko">
                                <img src="/css/img/alo.gif" alt="RF-1012-siedzisko" />
                            </a>
                            <a href="/img/large/4854887/rf-1012-siedzisko-bok" title="RF-1012-siedzisko bok">
                                <img src="/css/img/alo.gif" alt="RF-1012-siedzisko bok" />
                            </a>
                        </div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>RF-1012/M</td></tr><tr><th>Kod EAN</th><td>5903940707792</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002',
    );

    expect($product['images'])->toHaveCount(2)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/large/4854886/rf-1012-siedzisko',
            'alt' => 'RF-1012-siedzisko',
            'sort_order' => 0,
        ])
        ->and($product['images'][1])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/large/4854887/rf-1012-siedzisko-bok',
            'alt' => 'RF-1012-siedzisko bok',
            'sort_order' => 1,
        ])
        ->and($product['warnings'])->toBe([]);
});

it('extracts RehaFund product images from full page HTML when product scope only has placeholder images', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Siedzisko do podnośnika kąpielowe</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-342-1002" />
                <script type="application/json">
                    {"images":["https:\/\/sklep.rehafund.pl\/img\/large\/4854886\/rf-1012-siedzisko"]}
                </script>
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="1002">
                        <h1>Siedzisko do podnośnika kąpielowe</h1>
                        <div class="product-code-ui">RF-1012/M</div>
                        <div class="product-gallery-ui">
                            <img src="/css/img/alo.gif" alt="RF-1012-siedzisko" />
                        </div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>RF-1012/M</td></tr><tr><th>Kod EAN</th><td>5903940707792</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/siedzisko-do-podnosnika-kapielowe-roz-m-rf-1012/3-284-1002',
    );

    expect($product['images'])->toHaveCount(1)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/large/4854886/rf-1012-siedzisko',
            'alt' => 'Siedzisko do podnośnika kąpielowe',
            'sort_order' => 0,
        ])
        ->and($product['warnings'])->toBe([]);
});


it('filters unrelated RehaFund recommendation images from full page HTML', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <title>Wózek inwalidzki Cruiser Areo</title>
                <link rel="canonical" href="https://sklep.rehafund.pl/wozek-inwalidzki-cruiser-areo-szer-42-cm-kola-t/3-337-1118" />
                <script type="application/json">
                    {"images":[
                        "https:\/\/sklep.rehafund.pl\/img\/large\/2824701\/balkonik-rf-131-kolor-czarny-rf-131-cza",
                        "https:\/\/sklep.rehafund.pl\/img\/large\/394689\/wozek-inwalidzki-rf-4-cruiser-active-2-szer-42-c",
                        "https:\/\/sklep.rehafund.pl\/img\/large\/1118000\/wozek-inwalidzki-cruiser-areo-szer-42-cm-kola-t"
                    ]}
                </script>
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="1118">
                        <h1>Wózek inwalidzki Cruiser Areo</h1>
                        <div class="product-code-ui">RF-6/42/PN/CZA-624/PU</div>
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>RF-6/42/PN/CZA-624/PU</td></tr><tr><th>Kod EAN</th><td>5903940710020</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu.</p></div>
                    </article>
                    <section class="recommended-products-ui">
                        <img src="/img/large/2824701/balkonik-rf-131-kolor-czarny-rf-131-cza" alt="Balkonik RF-131" />
                        <img src="/img/large/394689/wozek-inwalidzki-rf-4-cruiser-active-2-szer-42-c" alt="Wózek inwalidzki RF-4 Cruiser Active 2" />
                    </section>
                </main>
            </body>
        </html>
        HTML;

    $product = app(RehaFundProductScraper::class)->extract(
        $html,
        'https://sklep.rehafund.pl/wozek-inwalidzki-cruiser-areo-szer-42-cm-kola-t/3-337-1118',
    );

    expect($product['images'])->toHaveCount(1)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://sklep.rehafund.pl/img/large/1118000/wozek-inwalidzki-cruiser-areo-szer-42-cm-kola-t',
            'alt' => 'Wózek inwalidzki Cruiser Areo',
            'sort_order' => 0,
        ])
        ->and($product['images'][0]['url'])->not->toContain('balkonik-rf-131')
        ->and($product['images'][0]['url'])->not->toContain('cruiser-active')
        ->and($product['warnings'])->toBe([]);
});

it('crawls RehaFund product data from product-link discovery JSON and skips duplicate products', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581' => Http::response(rehafundProductFixture(
            'Krzesło toaletowe Bruno',
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            '581',
            'BRUNO-801',
        )),
        'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801-duplicate/3-302-581' => Http::response(rehafundProductFixture(
            'Krzesło toaletowe Bruno duplicate',
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801-duplicate/3-302-581',
            '581',
            'BRUNO-801',
        )),
        '*' => Http::response('', 404),
    ]);

    $productLinkDiscovery = [
        'source' => 'rehafund',
        'product_urls' => [
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801-duplicate/3-302-581',
        ],
        'products' => [[
            'url' => 'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            'category_name' => 'Rehabilitacja',
            'category_url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'category_path' => ['Rehabilitacja'],
        ], [
            'url' => 'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801-duplicate/3-302-581',
            'category_name' => 'Rehabilitacja',
            'category_url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'category_path' => ['Rehabilitacja'],
        ]],
    ];

    $result = app(RehaFundProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery($productLinkDiscovery);

    expect($result['source'])->toBe('rehafund')
        ->and($result['source_product_url_count'])->toBe(2)
        ->and($result['total_product_url_count'])->toBe(2)
        ->and($result['product_count'])->toBe(1)
        ->and($result['products'][0]['name'])->toBe('Krzesło toaletowe Bruno')
        ->and($result['skipped_duplicate_external_ids'])->toHaveCount(1)
        ->and($result['failed_urls'])->toBe([]);
});

it('can save RehaFund full product data from a saved product-link discovery file', function (): void {
    $productLinksPath = storage_path('app/scrapers/rehafund/product-links-crawler-test.json');
    $savedPath = storage_path('app/scrapers/rehafund/full-product-data-crawler-test.json');

    if (! is_dir(dirname($productLinksPath))) {
        mkdir(dirname($productLinksPath), 0755, true);
    }

    @unlink($productLinksPath);
    @unlink($savedPath);

    file_put_contents($productLinksPath, json_encode([
        'source' => 'rehafund',
        'product_urls' => ['https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581'],
        'products' => [[
            'url' => 'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            'category_name' => 'Rehabilitacja',
            'category_url' => 'https://sklep.rehafund.pl/produkty/rhf-rehabilitacja/2-284',
            'category_path' => ['Rehabilitacja'],
        ]],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    Http::fake([
        'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581' => Http::response(rehafundProductFixture(
            'Krzesło toaletowe Bruno',
            'https://sklep.rehafund.pl/krzeslo-toaletowe-bruno-801/3-302-581',
            '581',
            'BRUNO-801',
        )),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('rehafund:crawl-product-data', [
        '--from' => 'scrapers/rehafund/product-links-crawler-test.json',
        '--save' => 'scrapers/rehafund/full-product-data-crawler-test.json',
        '--no-progress' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file($savedPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($savedPath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('rehafund')
        ->and($saved['product_count'])->toBe(1)
        ->and($saved['products'][0]['external_product_id'])->toBe('581');

    @unlink($productLinksPath);
    @unlink($savedPath);
});

it('records failed RehaFund product page requests', function (): void {
    Http::fake([
        'https://sklep.rehafund.pl/missing-product/3-302-9999' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(RehaFundProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls(['https://sklep.rehafund.pl/missing-product/3-302-9999']);

    expect($result['product_count'])->toBe(0)
        ->and($result['skipped_failed_products'])->toHaveCount(1)
        ->and($result['failed_urls'])->toBe([
            'https://sklep.rehafund.pl/missing-product/3-302-9999' => 'HTTP 500',
        ]);
});

function rehafundProductFixture(string $name, string $canonicalUrl, string $productId, string $sku): string
{
    return <<<HTML
        <html>
            <head>
                <title>{$name}</title>
                <link rel="canonical" href="{$canonicalUrl}" />
            </head>
            <body>
                <main class="main-content-ui">
                    <article class="product-presentation-ui" data-product-id="{$productId}">
                        <div class="breadcrumbs-ui"><a href="produkty/2">Produkty</a><a href="produkty/rhf-rehabilitacja/2-284">Rehabilitacja</a><span>{$name}</span></div>
                        <h1>{$name}</h1>
                        <div class="product-code-ui">{$sku}</div>
                        <img alt="{$name}" data-src="img/large/{$productId}/{$sku}" />
                        <div class="availability-ui">Dostępność: Od ręki</div>
                        <table><tr><th>Symbol</th><td>{$sku}</td></tr><tr><th>Kod EAN</th><td>5903940707792</td></tr></table>
                        <div class="product-description-ui"><h2>Opis towaru</h2><p>Opis produktu {$name}.</p></div>
                    </article>
                </main>
            </body>
        </html>
        HTML;
}
