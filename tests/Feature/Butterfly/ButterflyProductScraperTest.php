<?php

use App\Services\Butterfly\ButterflyProductScraper;

it('extracts Butterfly product details from a Shoper product page', function (): void {
    $html = butterflyProductPageDataFixture(
        canonicalUrl: 'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
        externalProductId: '78',
        name: 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
        producer: 'Butterfly Bio Magnetic System',
        sku: 'ORT-BUTTERFLY',
        price: '162,00 zł',
        categoryTrail: ['Magnetoterapia', 'Magnetyczne poduszki ortopedyczne'],
    );

    $result = app(ButterflyProductScraper::class)->extract(
        $html,
        'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
    );

    expect($result)->toMatchArray([
        'source' => 'butterfly',
        'source_url' => 'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
        'canonical_url' => 'https://butterfly-mag.com/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78',
        'external_product_id' => '78',
        'slug' => 'Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly',
        'name' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
        'brand' => [
            'name' => 'Butterfly Bio Magnetic System',
            'slug' => 'butterfly-bio-magnetic-system',
        ],
        'category' => 'Magnetyczne poduszki ortopedyczne',
        'categories' => ['Magnetoterapia', 'Magnetyczne poduszki ortopedyczne'],
        'seo_title' => 'Butterfly | Magnetyczna poduszka ortopedyczna Ort Butterfly',
        'seo_description' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly opis SEO.',
        'short_description' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly opis SEO.',
        'price_gross_amount' => 162.00,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Towar dostępny od ręki',
        'shipping_time' => '24 godziny',
        'sku' => 'ORT-BUTTERFLY',
        'ean' => '5900000000001',
        'is_medical_device' => true,
        'failed_urls' => [],
    ])
        ->and($result['description_html'])->toContain('<h2>SPECYFIKACJA PRODUKTU</h2>')
        ->and($result['description'])->toContain('Magnetyczna poduszka ortopedyczna Ort Butterfly to wyrób medyczny')
        ->and($result['images'])->toHaveCount(2)
        ->and($result['images'][0])->toBe([
            'url' => 'https://butterfly-mag.com/userdata/public/gfx/78/poduszka-ort-butterfly.webp',
            'alt' => 'Magnetyczna poduszka ortopedyczna Ort Butterfly',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'kod-produktu',
            'label' => 'Kod produktu',
            'value' => 'ORT-BUTTERFLY',
            'slug' => 'ort-butterfly',
        ])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => 'S',
            'label' => 'S',
            'price_gross_amount' => 162.00,
        ])
        ->and($result['warnings'])->toBe([]);
});

it('prefers original Butterfly image files over generated Shoper cache thumbnails', function (): void {
    $html = butterflyProductPageDataFixture(
        canonicalUrl: 'https://butterfly-mag.com/pl/p/Magnetyczna-opaska-na-kolano-wciagana/85',
        externalProductId: '85',
        name: 'Magnetyczna opaska na kolano wciągana',
        producer: 'Butterfly Bio Magnetic System',
        sku: 'KOLANO-85',
        price: '125,00 zł',
        categoryTrail: ['Magnetyczne opaski na kolana'],
    );

    $html = str_replace(
        '<meta property="og:image" content="/userdata/public/gfx/78/poduszka-ort-butterfly.webp">',
        '<meta property="og:image" content="/environment/cache/images/500_500_productGfx_ed30fb7ed0f4a47cbc22cc10274b57fd.jpg?overlay=1">',
        $html,
    );

    $html = str_replace(
        '<img itemprop="image" src="/userdata/public/gfx/78/poduszka-ort-butterfly.webp" alt="Magnetyczna opaska na kolano wciągana">',
        '<img itemprop="image" src="/environment/cache/images/productGfx_713_300_300/KOLANO.jpg?overlay=1" alt="Magnetyczna opaska na kolano wciągana">',
        $html,
    );

    $html = str_replace(
        '<a href="/userdata/public/gfx/78/poduszka-ort-butterfly-2.webp"><img src="/userdata/public/gfx/78/poduszka-ort-butterfly-2.webp" alt="Magnetyczna opaska na kolano wciągana drugi widok"></a>',
        '<a href="/environment/cache/images/productGfx_696_300_300/DSC_1211.jpg"><img src="/environment/cache/images/productGfx_a332152089ebff26d1276e2a13aac88b_300_300.jpg" alt="Magnetyczna opaska na kolano wciągana drugi widok"></a>',
        $html,
    );

    $result = app(ButterflyProductScraper::class)->extract(
        $html,
        'https://butterfly-mag.com/pl/p/Magnetyczna-opaska-na-kolano-wciagana/85',
    );

    expect(collect($result['images'])->pluck('url')->all())->toBe([
        'https://butterfly-mag.com/userdata/public/gfx/ed30fb7ed0f4a47cbc22cc10274b57fd.jpg',
        'https://butterfly-mag.com/userdata/public/gfx/713/KOLANO.jpg',
        'https://butterfly-mag.com/userdata/public/gfx/a332152089ebff26d1276e2a13aac88b.jpg',
        'https://butterfly-mag.com/userdata/public/gfx/696/DSC_1211.jpg',
    ]);
});

it('prefers the visible Butterfly availability label over hidden unavailable page text', function (): void {
    $html = butterflyProductPageDataFixture(
        canonicalUrl: 'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        externalProductId: '94',
        name: 'Pas Lędźwiowy Gorset Ortopedyczny',
        producer: 'TYNOR',
        sku: 'A-05',
        price: '78,00 zł',
        categoryTrail: ['Magnetyczne pasy na kręgosłup', 'Pasy Harmonium'],
    );

    $html = str_replace(
        '<p class="availability">Dostępność: Towar dostępny od ręki</p>',
        '<p class="availability">Dostępność: duża ilość Wysyłka w: 24 godziny</p><div class="notify-availability-modal">Powiadom mnie, gdy produkt będzie niedostępny.</div>',
        $html,
    );

    $result = app(ButterflyProductScraper::class)->extract(
        $html,
        'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
    );

    expect($result['availability'])->toBe('in_stock')
        ->and($result['availability_label'])->toBe('duża ilość Wysyłka w: 24 godziny');
});

it('keeps Butterfly product-link category context on scraped product payloads', function (): void {
    $html = butterflyProductPageDataFixture(
        canonicalUrl: 'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        externalProductId: '501',
        name: 'Pas Lędźwiowy Gorset Ortopedyczny',
        producer: 'Butterfly',
        sku: 'PAS-001',
        price: '79,90 zł',
        categoryTrail: ['Magnetyczne pasy na kręgosłup', 'Pasy Harmonium'],
    );

    $result = app(ButterflyProductScraper::class)->extract(
        $html,
        'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        [
            'url' => 'https://butterfly-mag.com/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            'name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
            'category_name' => 'Pasy Harmonium',
            'category_url' => 'https://butterfly-mag.com/pl/c/Pasy-Harmonium/22',
            'top_category_name' => 'Magnetyczne pasy na kręgosłup',
            'top_category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19',
            'category_path' => ['Magnetyczne pasy na kręgosłup', 'Pasy Harmonium'],
        ],
    );

    expect($result)->toMatchArray([
        'category' => 'Pasy Harmonium',
        'source_category_name' => 'Pasy Harmonium',
        'source_category_url' => 'https://butterfly-mag.com/pl/c/Pasy-Harmonium/22',
        'source_top_category_name' => 'Magnetyczne pasy na kręgosłup',
        'source_top_category_url' => 'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19',
        'source_category_path' => ['Magnetyczne pasy na kręgosłup', 'Pasy Harmonium'],
        'source_product_list_name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
    ]);
});

it('returns a failed Butterfly product payload when the product request fails', function (): void {
    Http::fake([
        'https://butterfly-mag.com/pl/p/Missing/999' => Http::response('', 404),
        '*' => Http::response('', 500),
    ]);

    $result = app(ButterflyProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://butterfly-mag.com/pl/p/Missing/999');

    expect($result)->toMatchArray([
        'source' => 'butterfly',
        'source_url' => 'https://butterfly-mag.com/pl/p/Missing/999',
        'external_product_id' => '999',
        'name' => '',
        'failed_urls' => [
            'https://butterfly-mag.com/pl/p/Missing/999' => 'HTTP 404',
        ],
    ])
        ->and($result['warnings'])->toContain('Unable to fetch Butterfly product page.');
});

function butterflyProductPageDataFixture(
    string $canonicalUrl,
    string $externalProductId,
    string $name,
    string $producer,
    string $sku,
    string $price,
    array $categoryTrail,
): string {
    $breadcrumbs = '<a href="/">Strona główna</a>';

    foreach ($categoryTrail as $index => $label) {
        $breadcrumbs .= '<a href="/pl/c/'.str_replace(' ', '-', $label).'/'.($index + 10).'">'.$label.'</a>';
    }

    $breadcrumbs .= '<span>'.$name.'</span>';

    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Butterfly | {$name}</title>
                <meta name="description" content="Magnetyczna poduszka ortopedyczna Ort Butterfly opis SEO.">
                <meta itemprop="priceCurrency" content="PLN">
                <meta property="og:image" content="/userdata/public/gfx/78/poduszka-ort-butterfly.webp">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body class="shop_product product-{$externalProductId}">
                <div class="breadcrumbs">{$breadcrumbs}</div>
                <div id="box_productfull">
                    <div class="productimg">
                        <img itemprop="image" src="/userdata/public/gfx/78/poduszka-ort-butterfly.webp" alt="{$name}">
                    </div>
                    <div class="gallery">
                        <a href="/userdata/public/gfx/78/poduszka-ort-butterfly-2.webp"><img src="/userdata/public/gfx/78/poduszka-ort-butterfly-2.webp" alt="{$name} drugi widok"></a>
                    </div>
                    <h1 itemprop="name">{$name}</h1>
                    <p class="producer">Producent: <a href="/pl/producer/Butterfly Bio Magnetic System/1">{$producer}</a></p>
                    <p class="availability">Dostępność: Towar dostępny od ręki</p>
                    <p class="shipping-time">Wysyłka w: 24 godziny</p>
                    <div class="price"><em class="main-price">{$price}</em></div>
                    <p class="code">Kod produktu: {$sku}</p>
                    <table class="product-params">
                        <tr><th>Kod produktu</th><td>{$sku}</td></tr>
                        <tr><th>Kod EAN</th><td>5900000000001</td></tr>
                        <tr><th>Producent</th><td>{$producer}</td></tr>
                    </table>
                    <label for="option_size">Rozmiar</label>
                    <select id="option_size" name="option_size">
                        <option value="">Wybierz opcję</option>
                        <option value="S">S</option>
                        <option value="M">M</option>
                    </select>
                </div>
                <div id="box_description">
                    <div class="innerbox">
                        <h2>SPECYFIKACJA PRODUKTU</h2>
                        <p>Magnetyczna poduszka ortopedyczna Ort Butterfly to wyrób medyczny przeznaczone do pomiaru ciśnienia krwi.</p>
                    </div>
                </div>
                <script>window.product_id = {$externalProductId};</script>
            </body>
        </html>
    HTML;
}
