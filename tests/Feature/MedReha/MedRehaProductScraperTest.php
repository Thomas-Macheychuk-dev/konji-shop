<?php

use App\Services\MedReha\MedRehaProductScraper;

it('extracts MedReha product details from a Shoper product page', function (): void {
    $html = medRehaProductPageDataFixture(
        canonicalUrl: 'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
        externalProductId: '670',
        name: 'Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
        producer: 'JOYTECH',
        sku: 'DBP-2253',
        price: '52,30 zł',
        categoryTrail: ['SPRZĘT MEDYCZNY', 'CIŚNIENIOMIERZE'],
    );

    $result = app(MedRehaProductScraper::class)->extract(
        $html,
        'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
    );

    expect($result)->toMatchArray([
        'source' => 'medreha',
        'source_url' => 'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
        'canonical_url' => 'https://sklep.medreha.pl/pl/p/Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui/670',
        'external_product_id' => '670',
        'slug' => 'Cisnieniomierz-Nadgarstkowy-Elektroniczny-Nadgarstek-Zgrabny-Dokladny-Etui',
        'name' => 'Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
        'brand' => [
            'name' => 'JOYTECH',
            'slug' => 'joytech',
        ],
        'category' => 'CIŚNIENIOMIERZE',
        'categories' => ['SPRZĘT MEDYCZNY', 'CIŚNIENIOMIERZE'],
        'seo_title' => 'MedReha | Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
        'seo_description' => 'Ciśnieniomierz nadgarstkowy MedReha opis SEO.',
        'short_description' => 'Ciśnieniomierz nadgarstkowy MedReha opis SEO.',
        'price_gross_amount' => 52.30,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Towar dostępny od ręki',
        'shipping_time' => '24 godziny',
        'sku' => 'DBP-2253',
        'ean' => '5900000000001',
        'is_medical_device' => true,
        'failed_urls' => [],
    ])
        ->and($result['description_html'])->toContain('<h2>SPECYFIKACJA PRODUKTU</h2>')
        ->and($result['description'])->toContain('Ciśnieniomierz nadgarstkowy to urządzenie medyczne')
        ->and($result['images'])->toHaveCount(2)
        ->and($result['images'][0])->toBe([
            'url' => 'https://sklep.medreha.pl/userdata/public/gfx/670/cisnieniomierz.webp',
            'alt' => 'Ciśnieniomierz Nadgarstkowy Elektroniczny Nadgarstek Zgrabny Dokładny Etui',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'kod-produktu',
            'label' => 'Kod produktu',
            'value' => 'DBP-2253',
            'slug' => 'dbp-2253',
        ])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => 'S',
            'label' => 'S',
            'price_gross_amount' => 52.30,
        ])
        ->and($result['warnings'])->toBe([]);
});


it('prefers the visible MedReha availability label over hidden unavailable page text', function (): void {
    $html = medRehaProductPageDataFixture(
        canonicalUrl: 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        externalProductId: '94',
        name: 'Pas Lędźwiowy Gorset Ortopedyczny',
        producer: 'TYNOR',
        sku: 'A-05',
        price: '78,00 zł',
        categoryTrail: ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
    );

    $html = str_replace(
        '<p class="availability">Dostępność: Towar dostępny od ręki</p>',
        '<p class="availability">Dostępność: duża ilość Wysyłka w: 24 godziny</p><div class="notify-availability-modal">Powiadom mnie, gdy produkt będzie niedostępny.</div>',
        $html,
    );

    $result = app(MedRehaProductScraper::class)->extract(
        $html,
        'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
    );

    expect($result['availability'])->toBe('in_stock')
        ->and($result['availability_label'])->toBe('duża ilość Wysyłka w: 24 godziny');
});

it('keeps MedReha product-link category context on scraped product payloads', function (): void {
    $html = medRehaProductPageDataFixture(
        canonicalUrl: 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        externalProductId: '501',
        name: 'Pas Lędźwiowy Gorset Ortopedyczny',
        producer: 'MedReha',
        sku: 'PAS-001',
        price: '79,90 zł',
        categoryTrail: ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
    );

    $result = app(MedRehaProductScraper::class)->extract(
        $html,
        'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
        [
            'url' => 'https://sklep.medreha.pl/ortezy-stabilizatory/pas-ledzwiowy-gorset',
            'name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
            'category_name' => 'PASY NA KRĘGOSŁUP',
            'category_url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
            'top_category_name' => 'ORTEZY I STABILIZATORY',
            'top_category_url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
            'category_path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
        ],
    );

    expect($result)->toMatchArray([
        'category' => 'PASY NA KRĘGOSŁUP',
        'source_category_name' => 'PASY NA KRĘGOSŁUP',
        'source_category_url' => 'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
        'source_top_category_name' => 'ORTEZY I STABILIZATORY',
        'source_top_category_url' => 'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
        'source_category_path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
        'source_product_list_name' => 'Pas Lędźwiowy Gorset Ortopedyczny',
    ]);
});

it('returns a failed MedReha product payload when the product request fails', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/p/Missing/999' => Http::response('', 404),
        '*' => Http::response('', 500),
    ]);

    $result = app(MedRehaProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://sklep.medreha.pl/pl/p/Missing/999');

    expect($result)->toMatchArray([
        'source' => 'medreha',
        'source_url' => 'https://sklep.medreha.pl/pl/p/Missing/999',
        'external_product_id' => '999',
        'name' => '',
        'failed_urls' => [
            'https://sklep.medreha.pl/pl/p/Missing/999' => 'HTTP 404',
        ],
    ])
        ->and($result['warnings'])->toContain('Unable to fetch MedReha product page.');
});

function medRehaProductPageDataFixture(
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
                <title>MedReha | {$name}</title>
                <meta name="description" content="Ciśnieniomierz nadgarstkowy MedReha opis SEO.">
                <meta itemprop="priceCurrency" content="PLN">
                <meta property="og:image" content="/userdata/public/gfx/670/cisnieniomierz.webp">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body class="shop_product product-{$externalProductId}">
                <div class="breadcrumbs">{$breadcrumbs}</div>
                <div id="box_productfull">
                    <div class="productimg">
                        <img itemprop="image" src="/userdata/public/gfx/670/cisnieniomierz.webp" alt="{$name}">
                    </div>
                    <div class="gallery">
                        <a href="/userdata/public/gfx/670/cisnieniomierz-2.webp"><img src="/userdata/public/gfx/670/cisnieniomierz-2.webp" alt="{$name} drugi widok"></a>
                    </div>
                    <h1 itemprop="name">{$name}</h1>
                    <p class="producer">Producent: <a href="/pl/producer/JOYTECH/1">{$producer}</a></p>
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
                        <p>Ciśnieniomierz nadgarstkowy to urządzenie medyczne przeznaczone do pomiaru ciśnienia krwi.</p>
                    </div>
                </div>
                <script>window.product_id = {$externalProductId};</script>
            </body>
        </html>
    HTML;
}
