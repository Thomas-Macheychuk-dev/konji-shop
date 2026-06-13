<?php

use App\Services\Mobilex\MobilexProductScraper;
use Illuminate\Support\Facades\Http;

it('follows the Mobilex buy-online link and normalizes the Galeria Zdrowia product page', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/' => Http::response(<<<'HTML'
            <html lang="pl-PL">
                <body>
                    <main id="tbp_content" class="post-30369 produkty type-produkty producent-mobilex">
                        <h1 class="tbp_title">Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR</h1>
                        <div class="acf-wrap acf-link_do_produktu">
                            <a href="https://galeriazdrowia.pl/produkt/flipper/" class="btn btn-primary" target="_blank" rel="noopener">Kup Online</a>
                        </div>
                    </main>
                </body>
            </html>
            HTML),
        'https://galeriazdrowia.pl/produkt/flipper/' => Http::response(<<<'HTML'
            <html lang="pl-PL">
                <head>
                    <title>Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR - GaleriaZdrowia.pl</title>
                    <meta name="description" content="Lekki aluminiowy wózek inwalidzki Flipper Mobilex z szybkim demontażem kół." />
                    <link rel="canonical" href="https://galeriazdrowia.pl/produkt/flipper/" />
                    <link rel="alternate" title="JSON" type="application/json" href="https://galeriazdrowia.pl/wp-json/wp/v2/product/19042" />
                    <meta property="og:image" content="https://galeriazdrowia.pl/wp-content/uploads/2017/09/flipper.jpg" />
                </head>
                <body>
                    <h1 class="product_title entry-title">Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR</h1>

                    <div class="woocommerce-product-gallery">
                        <div class="woocommerce-product-gallery__image">
                            <a href="https://galeriazdrowia.pl/wp-content/uploads/2017/09/flipper.jpg">
                                <img src="https://galeriazdrowia.pl/wp-content/uploads/2017/09/flipper-150x150.jpg" data-large_image="https://galeriazdrowia.pl/wp-content/uploads/2017/09/flipper.jpg" alt="Flipper" />
                            </a>
                        </div>
                    </div>

                    <form class="variations_form cart" data-product_variations='[{"variation_id":271940,"sku":"271940","attributes":{"attribute_pa_typ":"nr-art-271940"},"display_price":10170,"display_regular_price":10300}]'>
                        <label for="pa_typ">Typ</label>
                        <select id="pa_typ" name="attribute_pa_typ">
                            <option value="">Wybierz opcję</option>
                            <option value="nr-art-271940">nr art. 271940</option>
                        </select>
                    </form>

                    <div class="product_meta">
                        <span class="sku_wrapper"><strong>SKU:</strong> <span class="sku">1016</span></span>
                        <span class="posted_in"><strong>Kategoria:</strong> <a href="https://galeriazdrowia.pl/kategoria-produktu/wozki-inwalidzkie-dla-dzieci/">Wózki inwalidzkie dla dzieci</a></span>
                    </div>

                    <div class="nasa-tabs-content woocommerce-tabs nasa-vertical-notabs">
                        <div class="nasa-content nasa-content-description" id="nasa-scroll-description">
                            <h3 class="nasa-title nasa-crazy-box">Opis</h3>
                            <div class="nasa-content-panel">
                                <p>Opis Flippera z Galeria Zdrowia.</p>
                                <h4>PARAMETRY TECHNICZNE</h4>
                                <table><tbody><tr><th></th><th>MINI</th></tr><tr><td>szerokość siedziska</td><td>40 cm</td></tr></tbody></table>
                            </div>
                        </div>
                    </div>

                    <div class="row"><div class="large-12 columns"><div style="font-size: 0.8em; margin-top: 2em;">
                        <strong>Produkt jest wyrobem medycznym. Używaj go zgodnie z instrukcją używania lub etykietą. Producentem tego typu wózków inwalidzkich jest firma Mobilex A/S, której dystrybutorem w Polsce jest Firma Mobilex Sp. z o.o.</strong>
                    </div></div></div>
                </body>
            </html>
            HTML),
    ]);

    $result = app(MobilexProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://mobilex.pl/produkty/wozek-inwalidzki-flipper/', [
            'category_name' => 'wózki podstawowe',
            'category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
            'top_category_name' => 'Wózki inwalidzkie',
            'top_category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        ]);

    expect($result)->toMatchArray([
        'source' => 'mobilex',
        'external_product_id' => '19042',
        'source_url' => 'https://galeriazdrowia.pl/produkt/flipper/',
        'canonical_url' => 'https://galeriazdrowia.pl/produkt/flipper/',
        'mobilex_source_url' => 'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/',
        'slug' => 'flipper',
        'name' => 'Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR',
        'seo_description' => 'Lekki aluminiowy wózek inwalidzki Flipper Mobilex z szybkim demontażem kół.',
        'brand' => [
            'name' => 'Mobilex',
            'url' => null,
            'logo_url' => null,
        ],
        'category' => [
            'top_name' => 'Wózki inwalidzkie',
            'top_url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
            'name' => 'wózki podstawowe',
            'url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        ],
    ]);

    expect($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['url'])->toBe('https://galeriazdrowia.pl/wp-content/uploads/2017/09/flipper.jpg')
        ->and($result['description_html'])->toContain('Opis Flippera z Galeria Zdrowia.')
        ->and($result['specification_html'])->toContain('szerokość siedziska')
        ->and($result['medical_info_html'])->toContain('Produkt jest wyrobem medycznym')
        ->and($result['attributes'])->toContain([
            'code' => 'producent',
            'label' => 'Producent',
            'value' => 'Mobilex',
            'slug' => 'mobilex',
        ])
        ->and($result['variant_candidates'])->toHaveCount(1)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '271940',
            'sku' => '271940',
            'label' => 'nr art. 271940',
            'attributes' => [
                [
                    'label' => 'Typ',
                    'value' => 'nr art. 271940',
                ],
            ],
            'price_gross_amount' => 1017000,
            'regular_price_gross_amount' => 1030000,
            'currency' => 'PLN',
        ])
        ->and($result['warnings'])->toBe([]);
});

it('uses data product variations as the authoritative Galeria variant list when present', function (): void {
    $html = <<<'HTML'
        <html lang="pl-PL">
            <head>
                <title>SEAL WHEELCHAIR - GaleriaZdrowia.pl</title>
                <link rel="canonical" href="https://galeriazdrowia.pl/produkt/wozek-inwalidzki-seal-wheelchair/" />
                <link rel="alternate" title="JSON" type="application/json" href="https://galeriazdrowia.pl/wp-json/wp/v2/product/32416" />
            </head>
            <body>
                <h1 class="product_title entry-title">Wózek inwalidzki SEAL WHEELCHAIR</h1>
                <form class="variations_form cart" data-product_variations='[
                    {"variation_id":36178,"sku":"01.A.MOB.16.40.M","attributes":{"attribute_pa_typ":"wozek-inwalidzki-seal-wheelchair-z-hamulcami-recznymi-i-szybkozlaczami-rozmiar-40"},"display_price":650,"display_regular_price":650},
                    {"variation_id":32430,"sku":"01.A.MOB.13.40.M","attributes":{"attribute_pa_typ":"wozek-inwalidzki-seal-wheelchair-z-szybkozlaczami-rozmiar-40-cm"},"display_price":650,"display_regular_price":650}
                ]'>
                    <label for="pa_typ">Typ</label>
                    <select id="pa_typ" name="attribute_pa_typ" data-attribute_name="attribute_pa_typ" data-show_option_none="yes">
                        <option value="">Wybierz opcję</option>
                        <option value="wozek-inwalidzki-seal-wheelchair-rozmiar-40-cm">Wózek inwalidzki SEAL WHEELCHAIR rozmiar 40 cm</option>
                        <option value="wozek-inwalidzki-seal-wheelchair-z-hamulcami-recznymi-i-szybkozlaczami-rozmiar-40">Wózek inwalidzki SEAL WHEELCHAIR z hamulcami ręcznymi i szybkozłączami rozmiar 40</option>
                        <option value="wozek-inwalidzki-seal-wheelchair-z-szybkozlaczami-rozmiar-40-cm">Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 40 cm</option>
                    </select>
                </form>
                <script type="application/javascript">
                    window.pysWooProductData = window.pysWooProductData || [];
                    window.pysWooProductData[ 32417 ] = {"facebook":{"params":{"content_name":"Wózek inwalidzki SEAL WHEELCHAIR - Wózek inwalidzki SEAL WHEELCHAIR rozmiar 40 cm","value":"650","currency":"PLN"}}};
                    window.pysWooProductData[ 36178 ] = {"facebook":{"params":{"content_name":"Wózek inwalidzki SEAL WHEELCHAIR - Wózek inwalidzki SEAL WHEELCHAIR z hamulcami ręcznymi i szybkozłączami rozmiar 40","value":"650","currency":"PLN"}}};
                    window.pysWooProductData[ 32430 ] = {"facebook":{"params":{"content_name":"Wózek inwalidzki SEAL WHEELCHAIR - Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 40 cm","value":"650","currency":"PLN"}}};
                </script>
                <div class="nasa-content nasa-content-description" id="nasa-scroll-description">
                    <div class="nasa-content-panel"><p>Opis produktu.</p></div>
                </div>
            </body>
        </html>
        HTML;

    $result = app(MobilexProductScraper::class)->extract(
        $html,
        'https://galeriazdrowia.pl/produkt/wozek-inwalidzki-seal-wheelchair/',
        [
            'category_name' => 'wózki podstawowe',
            'category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
            'top_category_name' => 'Wózki inwalidzkie',
            'top_category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        ],
    );

    expect($result['variant_candidates'])->toHaveCount(2)
        ->and(collect($result['variant_candidates'])->pluck('label')->all())->toBe([
            'Wózek inwalidzki SEAL WHEELCHAIR z hamulcami ręcznymi i szybkozłączami rozmiar 40',
            'Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 40 cm',
        ])
        ->and(collect($result['variant_candidates'])->pluck('label')->all())->not->toContain('Wózek inwalidzki SEAL WHEELCHAIR rozmiar 40 cm');
});

it('falls back to Galeria select options when data product variations are unavailable', function (): void {
    $html = <<<'HTML'
        <html lang="pl-PL">
            <head>
                <title>SEAL WHEELCHAIR - GaleriaZdrowia.pl</title>
                <link rel="canonical" href="https://galeriazdrowia.pl/produkt/wozek-inwalidzki-seal-wheelchair/" />
                <link rel="alternate" title="JSON" type="application/json" href="https://galeriazdrowia.pl/wp-json/wp/v2/product/18999" />
            </head>
            <body>
                <h1 class="product_title entry-title">Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami</h1>
                <form class="variations_form cart" data-product_variations="[]">
                    <label for="pa_typ">Typ</label>
                    <select id="pa_typ" name="attribute_pa_typ" data-attribute_name="attribute_pa_typ" data-show_option_none="yes">
                        <option value="">Wybierz opcję</option>
                        <option value="wozek-inwalidzki-seal-wheelchair-z-szybkozlaczami-rozmiar-40-cm">Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 40 cm</option>
                        <option value="wozek-inwalidzki-seal-wheelchair-z-szybkozlaczami-rozmiar-45-cm">Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 45 cm</option>
                    </select>
                </form>
                <script type="application/javascript">
                    window.pysWooProductData = window.pysWooProductData || [];
                    window.pysWooProductData[ 302 ] = {"facebook":{"params":{"content_name":"Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami - Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 40 cm","value":"2100","currency":"PLN"}}};
                    window.pysWooProductData[ 303 ] = {"facebook":{"params":{"content_name":"Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami - Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 45 cm","value":"2200","currency":"PLN"}}};
                </script>
                <div class="nasa-content nasa-content-description" id="nasa-scroll-description">
                    <div class="nasa-content-panel"><p>Opis produktu.</p></div>
                </div>
            </body>
        </html>
        HTML;

    $result = app(MobilexProductScraper::class)->extract(
        $html,
        'https://galeriazdrowia.pl/produkt/wozek-inwalidzki-seal-wheelchair/',
        [
            'category_name' => 'wózki podstawowe',
            'category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
            'top_category_name' => 'Wózki inwalidzkie',
            'top_category_url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        ],
    );

    expect($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '302',
            'label' => 'Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 40 cm',
            'price_gross_amount' => 210000,
        ])
        ->and($result['variant_candidates'][1])->toMatchArray([
            'external_variant_id' => '303',
            'label' => 'Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami rozmiar 45 cm',
            'price_gross_amount' => 220000,
        ]);
});

it('falls back to breadcrumb categories when product-link context is not provided', function (): void {
    $html = <<<'HTML'
        <html><body>
            <main class="post-30836 produkty type-produkty producent-scholl">
                <div id="breadcrumbs">
                    <a href="https://mobilex.pl/kategoria-produktu/dla-kogo/">Dla Kogo</a>
                    <a href="https://mobilex.pl/kategoria-produktu/dla-firm-i-instytucji/">Dla firm i instytucji</a>
                    <a href="https://mobilex.pl/kategoria-produktu/scholl-obuwie-profesjonalne-do-pracy/">Scholl obuwie profesjonalne do pracy</a>
                </div>
                <h1 class="tbp_title">CLOG ULTRAGRIP zielone</h1>
            </main>
        </body></html>
        HTML;

    $result = app(MobilexProductScraper::class)->extract($html, 'https://mobilex.pl/produkty/clog-ultragrip-zielony/');

    expect($result['brand']['name'])->toBe('Scholl')
        ->and($result['category'])->toBe([
            'top_name' => 'Dla Kogo',
            'top_url' => 'https://mobilex.pl/kategoria-produktu/dla-kogo/',
            'name' => 'Scholl obuwie profesjonalne do pracy',
            'url' => 'https://mobilex.pl/kategoria-produktu/scholl-obuwie-profesjonalne-do-pracy/',
        ])->and($result['warnings'])->toContain('Category resolved from product breadcrumbs. Pass --links=mobilex/product-links.json to use scraper hierarchy context.');
});

it('filters generated thumbnails and extracts structured attributes and variant candidates', function (): void {
    $html = <<<'HTML'
        <html><body>
            <main class="post-30369 produkty type-produkty producent-mobilex">
                <h1 class="tbp_title">Wózek inwalidzki ręczny aluminiowy FLIPPER WHEELCHAIR</h1>
                <div class="producent-medical-info">
                    <div class="acf-logo_producenta"><a href="http://www.mobilex.pl"><img src="http://www.galeriazdrowia.pl/grafika/logo/logo_mobilex.jpg" alt="Logo producenta"></a></div>
                    <p>Producentem tego typu wózków inwalidzkich jest firma Mobilex A/S.</p>
                </div>
                <div class="slick-gallery-container">
                    <a href="https://mobilex.pl/wp-content/uploads/2025/07/Flipper.jpg"><img src="https://mobilex.pl/wp-content/uploads/2025/07/Flipper-150x150.jpg" alt="thumb"></a>
                    <img src="https://mobilex.pl/wp-content/uploads/2025/07/Flipper-150x150.jpg" alt="thumb">
                </div>
                <div class="tabs">
                    <div class="tabs-nav">
                        <label>Opis produktu</label>
                        <label>Specyfikacja</label>
                    </div>
                    <div class="tabs-content">
                        <div class="tab">
                            <h2>Specyfikacja techniczna</h2>
                            <ul>
                                <li>Kod: F319451065</li>
                                <li>Kolor: zielony</li>
                                <li>Materiały: EVA</li>
                                <li>ESD: Certyfikat EN 61340</li>
                            </ul>
                        </div>
                        <div class="tab">
                            <table>
                                <tbody>
                                    <tr><th></th><th>nr art. 271940</th><th>nr art. 271944</th></tr>
                                    <tr><td>szerokość siedziska</td><td>40 cm</td><td>44 cm</td></tr>
                                    <tr><td>maksymalne obciążenie</td><td>136 kg</td><td>136 kg</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </body></html>
        HTML;

    $result = app(MobilexProductScraper::class)->extract($html, 'https://mobilex.pl/produkty/wozek-inwalidzki-flipper/');

    expect($result['brand']['name'])->toBe('Mobilex')
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['url'])->toBe('https://mobilex.pl/wp-content/uploads/2025/07/Flipper.jpg')
        ->and($result['attributes'])->toContain([
            'code' => 'kolor',
            'label' => 'Kolor',
            'value' => 'zielony',
            'slug' => 'zielony',
        ])->and($result['attributes'])->toContain([
            'code' => 'maksymalne_obciazenie',
            'label' => 'Maksymalne obciążenie',
            'value' => '136 kg',
            'slug' => '136_kg',
        ])->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0]['sku'])->toBe('271940')
        ->and($result['variant_candidates'][0]['attributes'][0])->toBe([
            'label' => 'Szerokość siedziska',
            'value' => '40 cm',
        ]);
});


it('ignores internal producer medical-info classes and infers variants from tables with empty headers', function (): void {
    $html = <<<'HTML'
        <html><body>
            <main class="post-30753 produkty type-produkty producent-mobilex">
                <h1 class="tbp_title">Wózek inwalidzki SEAL WHEELCHAIR z szybkozłączami</h1>
                <div class="producent-medical-info acf-medical_info">
                    <p>Producentem tego typu wózków inwalidzkich jest firma Mobilex A/S.</p>
                </div>
                <div class="tabs">
                    <div class="tabs-nav"><label>Opis produktu</label><label>Specyfikacja</label></div>
                    <div class="tabs-content">
                        <div class="tab"><p>Opis</p></div>
                        <div class="tab">
                            <table>
                                <tbody>
                                    <tr><th></th><th></th><th></th><th></th></tr>
                                    <tr><td>szerokość siedziska</td><td>40 cm</td><td>45 cm</td><td>50 cm</td></tr>
                                    <tr><td>szerokość całkowita wózka</td><td>58 cm</td><td>63 cm</td><td>68 cm</td></tr>
                                    <tr><td>maksymalne obciążenie</td><td>120 kg</td><td>120 kg</td><td>135 kg</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </body></html>
        HTML;

    $result = app(MobilexProductScraper::class)->extract($html, 'https://mobilex.pl/produkty/wozek-inwalidzki-seal/');

    expect($result['attributes'])->toContain([
        'code' => 'producent',
        'label' => 'Producent',
        'value' => 'Mobilex',
        'slug' => 'mobilex',
    ])->and(collect($result['attributes'])->where('value', 'Medical Info')->all())->toBe([])
        ->and($result['attributes'])->not->toContain([
            'code' => 'producent',
            'label' => 'Producent',
            'value' => 'Medical Info',
            'slug' => 'medical-info',
        ])->and($result['variant_candidates'])->toHaveCount(3)
        ->and($result['variant_candidates'][0]['label'])->toBe('Szerokość siedziska 40 cm')
        ->and($result['variant_candidates'][1]['label'])->toBe('Szerokość siedziska 45 cm')
        ->and($result['variant_candidates'][2]['label'])->toBe('Szerokość siedziska 50 cm')
        ->and($result['variant_candidates'][2]['attributes'])->toContain([
            'label' => 'Maksymalne obciążenie',
            'value' => '135 kg',
        ]);
});

it('prefers the real medical device producer over the Mobilex distributor for Levabo products', function (): void {
    $html = <<<'HTML'
        <html><body>
            <main class="post-41073 produkty type-produkty producent-mobilex">
                <h1 class="tbp_title">Heel Up FIX short – przeciwodleżynowy ochraniacz pięty</h1>
                <div class="producent-medical-info">
                    <div class="acf-logo_producenta">
                        <a href="https://www.levabo.com/">
                            <img src="data:image/svg+xml,%3Csvg%3E%3C/svg%3E" data-tf-src="https://mobilex.pl/wp-content/uploads/2026/04/logo-LEVABO-160x60-na-produkt.png" alt="Logo producenta">
                        </a>
                    </div>
                    <div class="acf-medical_info">
                        <p>Produkt jest wyrobem medycznym. Producentem przeciwodleżynowych ochraniaczy na pięty jest firma Levabo ApS, której dystrybutorem w Polsce jest Firma Mobilex Sp. z o.o.</p>
                    </div>
                </div>
                <div class="tabs">
                    <div class="tabs-nav"><label>Opis produktu</label><label>Specyfikacja</label></div>
                    <div class="tabs-content">
                        <div class="tab">
                            <h2>Specyfikacja techniczna Heel Up® FIX Short</h2>
                            <ul>
                                <li><strong>Producent:</strong> Levabo ApS, Sverigesvej 20A, DK-8660 Skanderborg, Dania</li>
                                <li><strong>Materiał:</strong> 100% PE (nonwoven), hipoalergiczny</li>
                            </ul>
                        </div>
                        <div class="tab">
                            <table>
                                <tbody>
                                    <tr><th>model</th><th>wymiary</th></tr>
                                    <tr><td>Heel Up FIX short</td><td>18 x 32 x 25 cm</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </body></html>
        HTML;

    $result = app(MobilexProductScraper::class)->extract($html, 'https://mobilex.pl/produkty/heel-up-fix-short-ochraniacz-piety/', [
        'category_name' => 'Profilaktyka przeciwodleżynowa',
        'category_url' => 'https://mobilex.pl/kategoria-produktu/profilaktyka-przeciwodlezynowa/',
        'top_category_name' => 'Profilaktyka przeciwodleżynowa',
        'top_category_url' => 'https://mobilex.pl/kategoria-produktu/profilaktyka-przeciwodlezynowa/',
    ]);

    expect($result['brand'])->toBe([
        'name' => 'Levabo',
        'url' => 'https://www.levabo.com/',
        'logo_url' => 'https://mobilex.pl/wp-content/uploads/2026/04/logo-LEVABO-160x60-na-produkt.png',
    ])->and($result['attributes'])->toContain([
        'code' => 'producent',
        'label' => 'Producent',
        'value' => 'Mobilex',
        'slug' => 'mobilex',
    ])->and(collect($result['attributes'])->where('value', 'Levabo ApS, Sverigesvej 20A, DK-8660 Skanderborg, Dania')->all())->toBe([])
        ->and($result['attributes'])->toContain([
            'code' => 'material',
            'label' => 'Materiał',
            'value' => '100% PE (nonwoven), hipoalergiczny',
            'slug' => '100_pe_nonwoven_hipoalergiczny',
        ])->and($result['variant_candidates'])->toBe([]);
});
