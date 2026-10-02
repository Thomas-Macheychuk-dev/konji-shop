<?php

declare(strict_types=1);

use App\Services\Seni24\Seni24ProductScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('extracts Seni24 identity, VAT, price, taxonomy, medical data and selected variant', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630">
            <meta name="description" content="Skarpety medyczne DeoMed Wool">
            <meta property="og:title" content="Skarpety DeoMed Wool">
        </head>
        <body>
            <nav class="breadcrumb">
                <a href="/">Strona główna</a>
                <a href="/rehabilitacja/">Rehabilitacja i likwidacja barier</a>
                <a href="/produkty-przeciwzylakowe/">Produkty przeciwżylakowe i medyczne</a>
                <a href="/skarpety-medyczne-deomed-wool_10858-18630">Skarpety bezuciskowe z wełną merynosową DeoMed Wool</a>
            </nav>

            <main>
                <h1>Skarpety bezuciskowe z wełną merynosową DeoMed Wool</h1>
                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">Rozmiar</span>
                        <label><input data-product-attribute="1" name="group[1]" value="0" data-value="0" checked>43-46</label>
                        <label><input data-product-attribute="1" name="group[1]" value="0" data-value="0">39-42</label>
                    </div>
                    <div class="product-variants-item">
                        <span class="control-label">Kolor</span>
                        <label><input data-product-attribute="2" name="group[2]" value="0" data-value="0" checked>Czarny</label>
                        <label><input data-product-attribute="2" name="group[2]" value="0" data-value="0">Ciemny szary</label>
                    </div>
                </div>

                <div>Cena za 1 opak.</div>
                <div>z VAT 8%</div>
                <div>20,28 zł (20,28 zł / 1 szt.)</div>
                <div>Produkt dostępny więcej Przewidywany czas realizacji 24-48h.</div>

                <section id="description">
                    <div class="product-description"><p>Opis produktu medycznego wystarczająco długi do importu.</p></div>
                </section>

                <section id="product-details">
                    <table class="product-features">
                        <tr><th>Indeks</th><td>NN-SSK-DEOW-004</td></tr>
                        <tr><th>Wyrób medyczny</th><td>Tak</td></tr>
                        <tr><th>Producent</th><td>JJW Sp.J.</td></tr>
                        <tr><th>ean13</th><td>5901050201827</td></tr>
                    </table>
                </section>

                <div class="product-cover">
                    <img src="/img/p/deomed-main.jpg" alt="DeoMed Wool">
                </div>
                <div class="product-images">
                    <img data-image-large-src="/img/p/deomed-2.jpg" alt="DeoMed Wool side">
                </div>
            </main>
        </body></html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630',
        ['listing_roots' => ['https://www.seni24.pl/strona-glowna/']],
    );

    expect($result['external_product_id'])->toBe('10858')
        ->and($result['variant_candidates'][0]['external_variant_id'])->toBe('18630')
        ->and($result['price_gross_amount'])->toBe(20.28)
        ->and($result['vat_rate'])->toBe(8)
        ->and($result['availability'])->toBe('in_stock')
        ->and($result['shipping_time'])->toBe('24-48h')
        ->and($result['catalogue_number'])->toBe('NN-SSK-DEOW-004')
        ->and($result['ean'])->toBe('5901050201827')
        ->and($result['is_medical_device'])->toBeTrue()
        ->and($result['source_category_path'])->toBe([
            'Rehabilitacja i likwidacja barier',
            'Produkty przeciwżylakowe i medyczne',
        ])
        ->and($result['variant_candidates'][0]['attributes'])->toBe([
            ['label' => 'Rozmiar', 'value' => '43-46'],
            ['label' => 'Kolor', 'value' => 'Czarny'],
        ])
        ->and($result['variants_unresolved'])->toBeTrue()
        ->and($result['images'])->toHaveCount(2);
});

it('keeps a single-option Seni24 product eligible for authoritative import', function (): void {
    $html = <<<'HTML'
        <html><body>
            <nav class="breadcrumb">
                <a href="/">Strona główna</a>
                <a href="/pomoce-codzienne/">Pomoce codzienne</a>
                <a href="/akcesoria-kuchenne/">Akcesoria kuchenne</a>
            </nav>
            <h1>Kubek pojnik z ustnikiem 200 ml</h1>
            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">Pojemność</span>
                    <select data-product-attribute="1">
                        <option selected>200 ml</option>
                    </select>
                </div>
            </div>
            <div>Cena za 1 opak. z VAT 8% 9,39 zł (9,39 zł / 1 szt.)</div>
            <div>Produkt dostępny więcej Przewidywany czas realizacji 24-48h.</div>
            <div id="description"><div class="product-description"><p>Opis kubka pojnika do codziennego użytku.</p></div></div>
            <div id="product-details"><table class="product-features">
                <tr><th>Indeks</th><td>NN-SRW-AHK1-001</td></tr>
                <tr><th>Wyrób medyczny</th><td>Tak</td></tr>
                <tr><th>ean13</th><td>5905279578104</td></tr>
            </table></div>
            <div class="product-cover"><img src="/img/p/kubek.jpg" alt="Kubek"></div>
        </body></html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
    );

    expect($result['external_product_id'])->toBe('338')
        ->and($result['variant_candidates'][0]['external_variant_id'])->toBe('16501')
        ->and($result['vat_rate'])->toBe(8)
        ->and($result['variants_unresolved'])->toBeFalse()
        ->and($result['warnings'])->not->toContain(
            'Seni24 product exposes additional variant choices whose authoritative combination prices were not resolved.'
        );
});

it('resolves authoritative Seni24 JSON-LD combinations and selected stock state', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630">

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "10858",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18632",
                  "sku": "NN-SSK-DEOW-002",
                  "gtin13": "5901050202824",
                  "size": "43-46",
                  "color": "Ciemny szary",
                  "offers": {
                    "@type": "Offer",
                    "price": "23.46",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630",
                  "sku": "NN-SSK-DEOW-004",
                  "gtin13": "5901050201827",
                  "size": "43-46",
                  "color": "Czarny",
                  "offers": {
                    "@type": "Offer",
                    "price": "23.46",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/OutOfStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18629",
                  "sku": "NN-SSK-DEOW-003",
                  "gtin13": "5901050201728",
                  "size": "39-42",
                  "color": "Czarny",
                  "offers": {
                    "@type": "Offer",
                    "price": "23.46",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/OutOfStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Skarpety bezuciskowe z wełną merynosową DeoMed Wool</h1>

            <div class="recommendation">Produkt dostępny</div>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">Rozmiar</span>
                    <label><input data-product-attribute="51" name="group[51]" value="1488" data-value="0" checked>43-46</label>
                    <label><input data-product-attribute="51" name="group[51]" value="1487" data-value="0">39-42</label>
                </div>

                <div class="product-variants-item">
                    <span class="control-label">Kolor</span>
                    <label><input data-product-attribute="54" name="group[54]" value="1375" data-value="0" checked>Czarny</label>
                    <label><input data-product-attribute="54" name="group[54]" value="3635" data-value="0">Ciemny szary</label>
                </div>
            </div>

            <div>Cena za 1 opak. z VAT 8% 23,46 zł (23,46 zł / 1 szt.)</div>

            <div id="product-details">
                <table class="product-features">
                    <tr><th>Indeks</th><td>NN-SSK-DEOW-004</td></tr>
                    <tr><th>ean13</th><td>5901050201827</td></tr>
                </table>
            </div>

            <div id="availability-data" data-availability="OutOfStock"></div>

            <a id="availability-text">
                <div class="h6">Produkt niedostępny</div>
            </a>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630',
    );

    expect($result['availability'])->toBe('out_of_stock')
        ->and($result['availability_label'])->toBe('Produkt niedostępny')
        ->and($result['variants_unresolved'])->toBeFalse()
        ->and($result['variant_candidates'])->toHaveCount(3)
        ->and(array_column(
            $result['variant_candidates'],
            'external_variant_id',
        ))->toBe(['18630', '18632', '18629'])
        ->and($result['variant_candidates'][0]['attributes'])->toBe([
            ['label' => 'Rozmiar', 'value' => '43-46'],
            ['label' => 'Kolor', 'value' => 'Czarny'],
        ])
        ->and($result['variant_candidates'][0]['availability'])->toBe('out_of_stock')
        ->and($result['variant_candidates'][1]['availability'])->toBe('in_stock')
        ->and($result['warnings'])->not->toContain(
            'Seni24 product exposes additional variant choices whose authoritative combination prices were not resolved.'
        );
});

it('preserves a single DOM option when Seni24 structured variant data omits that attribute', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501">

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "338",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501",
                  "sku": "NN-SRW-AHK1-001",
                  "gtin13": "5905279578104",
                  "offers": {
                    "@type": "Offer",
                    "price": "9.39",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Kubek pojnik z ustnikiem 200 ml</h1>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">Pojemność</span>
                    <label>
                        <input
                            data-product-attribute="1"
                            name="group[1]"
                            value="123"
                            data-value="0"
                            checked
                        >
                        200 ml
                    </label>
                </div>
            </div>

            <div>Cena za 1 opak. z VAT 8% 9,39 zł (9,39 zł / 1 szt.)</div>
            <div id="availability-data" data-availability="InStock"></div>

            <div id="product-details">
                <table class="product-features">
                    <tr><th>Indeks</th><td>NN-SRW-AHK1-001</td></tr>
                    <tr><th>ean13</th><td>5905279578104</td></tr>
                </table>
            </div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
    );

    expect($result['variants_unresolved'])->toBeFalse()
        ->and($result['variant_candidates'])->toHaveCount(1)
        ->and($result['variant_candidates'][0]['label'])->toBe(
            'Pojemność: 200 ml'
        )
        ->and($result['variant_candidates'][0]['attributes'])->toBe([
            [
                'label' => 'Pojemność',
                'value' => '200 ml',
            ],
        ]);
});

it('does not treat a Seni24 promotional rebate as the selling price', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical"
                href="https://www.seni24.pl/krem-testowy_12006-21158">
        </head>
        <body>
            <h1>Krem testowy</h1>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">Pojemność</span>
                    <label>
                        <input
                            data-product-attribute="1"
                            name="group[1]"
                            value="1"
                            checked
                        >
                        200 ml
                    </label>
                </div>
            </div>

            <div>
                Cena za 1 opak.
                z VAT 23%
                RABAT 0,11 zł
                8,99 zł (8,99 zł / 100 ML)
                Cena regularna: 9,84 zł
                Najniższa cena z 30 dni przed obniżką: 9,10 zł
            </div>

            <div id="availability-data"
                data-availability="InStock"></div>

            <div id="product-details">
                <table>
                    <tr>
                        <th>Indeks</th>
                        <td>SE-TEST-200</td>
                    </tr>
                    <tr>
                        <th>ean13</th>
                        <td>5900000000001</td>
                    </tr>
                </table>
            </div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/krem-testowy_12006-21158',
    );

    expect($result['price_gross_amount'])->toBe(8.99)
        ->and($result['vat_rate'])->toBe(23)
        ->and($result['price_gross_amount'])->not->toBe(0.11);
});

it('backfills selected Seni24 commerce data from structured variant data', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical"
                href="https://www.seni24.pl/pianka-testowa_11917-20370">

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/pianka-testowa_11917-20370",
                  "sku": "SE-PIANKA-500",
                  "gtin13": "5900000000002",
                  "offers": {
                    "@type": "Offer",
                    "price": "17.70",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>
        <body>
            <h1>Pianka testowa</h1>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">Pojemność</span>
                    <label>
                        <input
                            data-product-attribute="1"
                            name="group[1]"
                            value="1"
                            checked
                        >
                        500 ml
                    </label>
                </div>
            </div>

            <div>
                Cena za 1 opak. z VAT 23%
            </div>

            <div id="availability-data"
                data-availability="InStock"></div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/pianka-testowa_11917-20370',
    );

    expect($result['price_gross_amount'])->toBe(17.70)
        ->and($result['vat_rate'])->toBe(23)
        ->and($result['catalogue_number'])->toBe('SE-PIANKA-500')
        ->and($result['ean'])->toBe('5900000000002')
        ->and($result['variants_unresolved'])->toBeFalse();
});

it('prefers the visible one-package price over a lower structured quantity-tier price', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical"
                href="https://www.seni24.pl/eva-dermo-test_1360-3339">

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/eva-dermo-test_1360-3339",
                  "sku": "EO-C04-0050-001",
                  "gtin13": "5900000001360",
                  "capacity": "50 ml",
                  "offers": {
                    "@type": "Offer",
                    "price": "28.23",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Przeciwzmarszczkowy krem na dzień Eva Dermo 50 ml</h1>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">Pojemność</span>

                    <label>
                        <input
                            data-product-attribute="1"
                            name="group[1]"
                            value="1"
                            checked
                        >
                        50 ml
                    </label>
                </div>
            </div>

            <div>
                Cena za 1 opak. z VAT 23%
                RABAT 3,84 zł
                28,55 zł (57,10 zł / 100 ML)
                Cena regularna: 38,55 zł
                Najniższa cena z 30 dni przed obniżką za 1 opak.:
                32,39 zł

                Cena od 2 opak. z VAT 23%
                28,23 zł (56,46 zł / 100 ML)
            </div>

            <div id="availability-data"
                data-availability="InStock"></div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/eva-dermo-test_1360-3339',
    );

    expect($result['price_gross_amount'])->toBe(28.55)
        ->and($result['vat_rate'])->toBe(23)
        ->and($result['variant_candidates'])->toHaveCount(1)
        ->and(
            $result['variant_candidates'][0]['price_gross_amount']
        )->toBe(28.55)
        ->and($result['variants_unresolved'])->toBeFalse();
});

it('extracts a unique VAT rate from a Seni24 variant pricing table', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical"
                href="https://www.seni24.pl/strzykawki-test_22172-25594">
        </head>

        <body>
            <h1>Strzykawki insulinowe testowe</h1>

            <section>
                <h3>Dostępne warianty produktu</h3>

                <div>
                    Cena
                    (z vat 8%)

                    Pojemność 1 ml
                    Rozmiar igły 30G 0.3x8mm
                    Skala U-100

                    od 1 opak. 44,17 zł
                    od 10 opak. 42,63 zł
                </div>
            </section>

            <div id="availability-data"
                data-availability="OutOfStock"></div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/strzykawki-test_22172-25594',
    );

    expect($result['vat_rate'])->toBe(8);
});

it('does not propagate a single DOM option when structured variant urls contradict it', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link
                rel="canonical"
                href="https://www.seni24.pl/test-igly_427-24045"
            >

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "427",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-igly_427-24045#/srednica_i_dlugosc_igly-12x40mm/rozmiar_igly-18g",
                  "sku": "NN-SKD-AI12-001",
                  "gtin13": "4031881909317",
                  "size": "18G",
                  "offers": {
                    "@type": "Offer",
                    "price": "4.83",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-igly_427-24046#/rozmiar_igly-18g/srednica_i_dlugosc_igly-12_x_50_mm",
                  "sku": "NN-SKD-AI12-002",
                  "gtin13": "4031881903440",
                  "size": "18G",
                  "offers": {
                    "@type": "Offer",
                    "price": "13.80",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Test igły</h1>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">
                        Średnica i długość igły
                    </span>

                    <label>
                        <input
                            data-product-attribute="1"
                            name="group[1]"
                            checked
                        >
                        1.2x40mm
                    </label>
                </div>
            </div>

            <div>
                Cena za 1 opak. z VAT 8%
                4,83 zł (4,83 zł / 1 szt.)
            </div>

            <div
                id="availability-data"
                data-availability="InStock"
            ></div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/test-igly_427-24045',
    );

    expect($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variants_unresolved'])->toBeTrue();

    foreach ($result['variant_candidates'] as $candidate) {
        expect(
            array_column(
                $candidate['attributes'],
                'label',
            )
        )->not->toContain(
            'Średnica i długość igły',
        );
    }
});

it('marks structured variants unresolved when visible attributes are indistinguishable', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link
                rel="canonical"
                href="https://www.seni24.pl/test-strzykawki_22172-25594"
            >

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "22172",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-strzykawki_22172-25594#/pojemnosc-1_ml/rozmiar_igly-30g_03x8mm/skala-u_100",
                  "sku": "NN-MCH-I018-001",
                  "gtin13": "8586015044205",
                  "size": "30G 0.3x8mm",
                  "offers": {
                    "@type": "Offer",
                    "price": "44.19",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/OutOfStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-strzykawki_22172-25595#/pojemnosc-05_ml/rozmiar_igly-30g_03x8mm/skala-u_100",
                  "sku": "NN-MCH-I058-001",
                  "gtin13": "8586015046346",
                  "size": "30G 0.3x8mm",
                  "offers": {
                    "@type": "Offer",
                    "price": "45.80",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Test strzykawki</h1>

            <div>
                Cena za 1 opak. z VAT 8%
                44,19 zł (44,19 zł / 1 szt.)
            </div>

            <div
                id="availability-data"
                data-availability="OutOfStock"
            ></div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/test-strzykawki_22172-25594',
    );

    expect($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0]['attributes'])->toBe([
            [
                'label' => 'Rozmiar',
                'value' => '30G 0.3x8mm',
            ],
        ])
        ->and($result['variant_candidates'][1]['attributes'])->toBe([
            [
                'label' => 'Rozmiar',
                'value' => '30G 0.3x8mm',
            ],
        ])
        ->and($result['variants_unresolved'])->toBeTrue();
});

it('hydrates structured Seni24 variants from concrete combination pages', function (): void {
    $productGroup = <<<'JSON'
        {
          "@context": "https://schema.org",
          "@type": "ProductGroup",
          "productID": "427",
          "hasVariant": [
            {
              "@type": "Product",
              "url": "https://www.seni24.pl/test-igly_427-24045#/srednica_i_dlugosc_igly-12x40mm/rozmiar_igly-18g",
              "sku": "NN-SKD-AI12-001",
              "gtin13": "4031881909317",
              "size": "18G",
              "offers": {
                "@type": "Offer",
                "price": "4.83",
                "priceCurrency": "PLN",
                "availability": "https://schema.org/InStock"
              }
            },
            {
              "@type": "Product",
              "url": "https://www.seni24.pl/test-igly_427-24046#/srednica_i_dlugosc_igly-12_x_50_mm/rozmiar_igly-18g",
              "sku": "NN-SKD-AI12-002",
              "gtin13": "4031881903440",
              "size": "18G",
              "offers": {
                "@type": "Offer",
                "price": "13.80",
                "priceCurrency": "PLN",
                "availability": "https://schema.org/InStock"
              }
            }
          ]
        }
    JSON;

    $page = static function (
        string $dimension,
        string $price,
        string $productGroup,
    ): string {
        return <<<HTML
            <html>
            <head>
                <link
                    rel="canonical"
                    href="https://www.seni24.pl/test-igly_427-24045"
                >

                <script type="application/ld+json">
                {$productGroup}
                </script>
            </head>

            <body>
                <h1>Test igły</h1>

                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">
                            Średnica i długość igły
                        </span>

                        <label>
                            <input
                                data-product-attribute="1"
                                name="group[1]"
                                checked
                            >
                            {$dimension}
                        </label>
                    </div>
                </div>

                <div>
                    Cena za 1 opak. z VAT 8%
                    {$price} zł ({$price} zł / 1 szt.)
                </div>

                <div
                    id="availability-data"
                    data-availability="InStock"
                ></div>
            </body>
            </html>
        HTML;
    };

    $initialHtml = $page(
        '1.2x40mm',
        '4,83',
        $productGroup,
    );

    $variant45Html = $page(
        '1.2x40mm',
        '4,83',
        $productGroup,
    );

    $variant46Html = $page(
        '1.2 x 50 mm',
        '13,80',
        $productGroup,
    );

    $calls = [];

    Http::fake(
        function (
            Request $request
        ) use (
            &$calls,
            $initialHtml,
            $variant45Html,
            $variant46Html,
        ) {
            $url = $request->url();

            $calls[$url] = ($calls[$url] ?? 0) + 1;

            if (str_ends_with($url, '_427-24045')) {
                return Http::response(
                    $calls[$url] === 1
                        ? $initialHtml
                        : $variant45Html,
                    200,
                );
            }

            if (str_ends_with($url, '_427-24046')) {
                return Http::response(
                    $variant46Html,
                    200,
                );
            }

            return Http::response(
                'unexpected',
                404,
            );
        }
    );

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            'https://www.seni24.pl/test-igly_427-24045',
        );

    $candidates = collect(
        $result['variant_candidates'],
    )->keyBy('external_variant_id');

    expect($result['variants_unresolved'])
        ->toBeFalse()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['attempted']
        )->toBeTrue()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['succeeded']
        )->toBeTrue()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['candidate_count']
        )->toBe(2)
        ->and($candidates)->toHaveCount(2)
        ->and(
            collect(
                $candidates['24045']['attributes']
            )->firstWhere(
                'label',
                'Średnica i długość igły',
            )['value']
        )->toBe('1.2x40mm')
        ->and(
            collect(
                $candidates['24046']['attributes']
            )->firstWhere(
                'label',
                'Średnica i długość igły',
            )['value']
        )->toBe('1.2 x 50 mm')
        ->and($result['warnings'])->not->toContain(
            'Seni24 product exposes additional variant choices whose authoritative combination prices were not resolved.'
        );

    Http::assertSentCount(3);
});

it('keeps structured Seni24 variants unresolved when combination pages expose no selected DOM semantics', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link
                rel="canonical"
                href="https://www.seni24.pl/test-strzykawki_22172-25594"
            >

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "22172",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-strzykawki_22172-25594#/pojemnosc-1_ml/rozmiar_igly-30g_03x8mm/skala-u_100",
                  "sku": "NN-MCH-I018-001",
                  "gtin13": "8586015044205",
                  "size": "30G 0.3x8mm",
                  "offers": {
                    "@type": "Offer",
                    "price": "44.19",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/OutOfStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-strzykawki_22172-25595#/pojemnosc-05_ml/rozmiar_igly-30g_03x8mm/skala-u_100",
                  "sku": "NN-MCH-I058-001",
                  "gtin13": "8586015046346",
                  "size": "30G 0.3x8mm",
                  "offers": {
                    "@type": "Offer",
                    "price": "45.80",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Test strzykawki</h1>

            <div>
                Cena za 1 opak. z VAT 8%
                44,19 zł (44,19 zł / 1 szt.)
            </div>

            <div
                id="availability-data"
                data-availability="OutOfStock"
            ></div>
        </body>
        </html>
    HTML;

    Http::fake([
        '*' => Http::response(
            $html,
            200,
        ),
    ]);

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            'https://www.seni24.pl/test-strzykawki_22172-25594',
        );

    expect($result['variants_unresolved'])
        ->toBeTrue()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['attempted']
        )->toBeTrue()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['succeeded']
        )->toBeFalse()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['reason']
        )->toBe(
            'missing_selected_dom_attributes:25594'
        )
        ->and($result['variant_candidates'])
        ->toHaveCount(2)
        ->and($result['warnings'])->toContain(
            'Seni24 product exposes additional variant choices whose authoritative combination prices were not resolved.'
        );

    /*
     * Initial product request + first hydration attempt.
     * Recovery must stop immediately after authoritative
     * semantics are unavailable.
     */
    Http::assertSentCount(2);
});

it('accepts a visible Seni24 combination price when exact page identity proves stale structured pricing', function (): void {
    $productGroup = <<<'JSON'
        {
          "@context": "https://schema.org",
          "@type": "ProductGroup",
          "productID": "1398",
          "hasVariant": [
            {
              "@type": "Product",
              "url": "https://www.seni24.pl/test-serweta_1398-25738#/rozmiar-130x90/otwor-8cm",
              "sku": "MA-134-SETF-048",
              "gtin13": "5900516268480",
              "size": "130x90",
              "offers": {
                "@type": "Offer",
                "price": "10.89",
                "priceCurrency": "PLN",
                "availability": "https://schema.org/InStock"
              }
            },
            {
              "@type": "Product",
              "url": "https://www.seni24.pl/test-serweta_1398-25737#/rozmiar-45x45cm/otwor-5cm",
              "sku": "MA-134-SETF-047",
              "gtin13": "5900516268473",
              "size": "45x45cm",
              "offers": {
                "@type": "Offer",
                "price": "10.89",
                "priceCurrency": "PLN",
                "availability": "https://schema.org/OutOfStock"
              }
            }
          ]
        }
    JSON;

    $page = static function (
        string $canonicalVariant,
        string $size,
        string $opening,
        string $price,
        string $sku,
        string $ean,
        string $productGroup,
    ): string {
        return <<<HTML
            <html>
            <head>
                <link
                    rel="canonical"
                    href="https://www.seni24.pl/test-serweta_1398-{$canonicalVariant}"
                >

                <script type="application/ld+json">
                {$productGroup}
                </script>
            </head>

            <body>
                <h1>Test serweta</h1>

                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">
                            Rozmiar
                        </span>

                        <label>
                            <input
                                data-product-attribute="1"
                                name="group[1]"
                                checked
                            >
                            {$size}
                        </label>
                    </div>

                    <div class="product-variants-item">
                        <span class="control-label">
                            Otwór
                        </span>

                        <label>
                            <input
                                data-product-attribute="2"
                                name="group[2]"
                                checked
                            >
                            {$opening}
                        </label>
                    </div>
                </div>

                <div>
                    Cena za 1 opak. z VAT 8%
                    {$price} zł ({$price} zł / 1 szt.)
                </div>

                <div id="product-details">
                    <table class="product-features">
                        <tr>
                            <th>Indeks</th>
                            <td>{$sku}</td>
                        </tr>
                        <tr>
                            <th>ean13</th>
                            <td>{$ean}</td>
                        </tr>
                    </table>
                </div>

                <div
                    id="availability-data"
                    data-availability="InStock"
                ></div>
            </body>
            </html>
        HTML;
    };

    $initial = $page(
        '25738',
        '130x90',
        '8cm',
        '10,89',
        'MA-134-SETF-048',
        '5900516268480',
        $productGroup,
    );

    $variant25738 = $initial;

    $variant25737 = $page(
        '25737',
        '45x45cm',
        '5cm',
        '3,69',
        'MA-134-SETF-047',
        '5900516268473',
        $productGroup,
    );

    $calls = [];

    Http::fake(
        function (
            Request $request
        ) use (
            &$calls,
            $initial,
            $variant25738,
            $variant25737,
        ) {
            $url = $request->url();
            $calls[$url] = ($calls[$url] ?? 0) + 1;

            if (str_ends_with($url, '_1398-25738')) {
                return Http::response(
                    $calls[$url] === 1
                        ? $initial
                        : $variant25738,
                    200,
                );
            }

            if (str_ends_with($url, '_1398-25737')) {
                return Http::response(
                    $variant25737,
                    200,
                );
            }

            return Http::response(
                'unexpected',
                404,
            );
        }
    );

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            'https://www.seni24.pl/test-serweta_1398-25738',
        );

    $candidates = collect(
        $result['variant_candidates'],
    )->keyBy('external_variant_id');

    expect($result['variants_unresolved'])
        ->toBeFalse()
        ->and($candidates['25737']['price_gross_amount'])
        ->toBe(3.69)
        ->and(
            collect(
                $candidates['25737']['attributes']
            )->firstWhere(
                'label',
                'Otwór',
            )['value']
        )->toBe('5cm')
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['succeeded']
        )->toBeTrue();

    Http::assertSentCount(3);
});

it('ignores a zero DOM placeholder when structured Seni24 semantics are fragment-confirmed', function (): void {
    $productGroup = <<<'JSON'
        {
          "@context": "https://schema.org",
          "@type": "ProductGroup",
          "productID": "5220",
          "hasVariant": [
            {
              "@type": "Product",
              "url": "https://www.seni24.pl/test-sliniaki_5220-9586#/ilosc_sztuk-50_szt/kolor-morelowy",
              "sku": "NN-SWE-TSKM-001",
              "gtin13": "5907457960091",
              "color": "Morelowy",
              "offers": {
                "@type": "Offer",
                "price": "19.79",
                "priceCurrency": "PLN",
                "availability": "https://schema.org/InStock"
              }
            },
            {
              "@type": "Product",
              "url": "https://www.seni24.pl/test-sliniaki_5220-9587#/ilosc_sztuk-50_szt/kolor-bialy",
              "sku": "NN-SWE-TSKM-002",
              "gtin13": "5907457960092",
              "color": "Biały",
              "offers": {
                "@type": "Offer",
                "price": "19.79",
                "priceCurrency": "PLN",
                "availability": "https://schema.org/InStock"
              }
            }
          ]
        }
    JSON;

    $page = static function (
        string $variant,
        string $productGroup,
    ): string {
        return <<<HTML
            <html>
            <head>
                <link
                    rel="canonical"
                    href="https://www.seni24.pl/test-sliniaki_5220-{$variant}"
                >

                <script type="application/ld+json">
                {$productGroup}
                </script>
            </head>

            <body>
                <h1>Test śliniaki</h1>

                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">
                            Kolor
                        </span>

                        <label>
                            <input
                                data-product-attribute="1"
                                name="group[1]"
                                value="0"
                                checked
                            >
                        </label>
                    </div>

                    <div class="product-variants-item">
                        <span class="control-label">
                            Ilość sztuk
                        </span>

                        <label>
                            <input
                                data-product-attribute="2"
                                name="group[2]"
                                checked
                            >
                            50 szt.
                        </label>
                    </div>
                </div>

                <div>
                    Cena za 1 opak. z VAT 23%
                    19,79 zł (19,79 zł / 1 szt.)
                </div>

                <div
                    id="availability-data"
                    data-availability="InStock"
                ></div>
            </body>
            </html>
        HTML;
    };

    Http::fake(
        function (
            Request $request
        ) use ($productGroup, $page) {
            if (
                str_ends_with(
                    $request->url(),
                    '_5220-9586',
                )
            ) {
                return Http::response(
                    $page('9586', $productGroup),
                    200,
                );
            }

            if (
                str_ends_with(
                    $request->url(),
                    '_5220-9587',
                )
            ) {
                return Http::response(
                    $page('9587', $productGroup),
                    200,
                );
            }

            return Http::response(
                'unexpected',
                404,
            );
        }
    );

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            'https://www.seni24.pl/test-sliniaki_5220-9586',
        );

    $candidates = collect(
        $result['variant_candidates'],
    )->keyBy('external_variant_id');

    expect($result['variants_unresolved'])
        ->toBeFalse()
        ->and(
            collect(
                $candidates['9586']['attributes']
            )->firstWhere(
                'label',
                'Kolor',
            )['value']
        )->toBe('Morelowy')
        ->and(
            collect(
                $candidates['9587']['attributes']
            )->firstWhere(
                'label',
                'Kolor',
            )['value']
        )->toBe('Biały')
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['succeeded']
        )->toBeTrue();
});

it('uses a concrete Seni24 ProductGroup price when it corroborates the visible combination price', function (): void {
    $group = static function (
        string $priceA,
        string $priceB,
    ): string {
        return <<<JSON
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "9000",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-price-drift_9000-10001#/rozmiar-a",
                  "sku": "TEST-A",
                  "gtin13": "5900000000001",
                  "size": "A",
                  "offers": {
                    "@type": "Offer",
                    "price": "{$priceA}",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-price-drift_9000-10002#/rozmiar-b",
                  "sku": "TEST-B",
                  "gtin13": "5900000000002",
                  "size": "B",
                  "offers": {
                    "@type": "Offer",
                    "price": "{$priceB}",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
        JSON;
    };

    $page = static function (
        string $variant,
        string $size,
        string $visiblePrice,
        string $jsonLd,
    ): string {
        return <<<HTML
            <html>
            <head>
                <link
                    rel="canonical"
                    href="https://www.seni24.pl/test-price-drift_9000-{$variant}"
                >

                <script type="application/ld+json">
                {$jsonLd}
                </script>
            </head>

            <body>
                <h1>Test price drift</h1>

                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">
                            Rozmiar
                        </span>

                        <label>
                            <input
                                data-product-attribute="1"
                                name="group[1]"
                                checked
                            >
                            {$size}
                        </label>
                    </div>
                </div>

                <div>
                    Cena za 1 opak. z VAT 23%
                    {$visiblePrice} zł
                    ({$visiblePrice} zł / 1 szt.)
                </div>

                <div
                    id="availability-data"
                    data-availability="InStock"
                ></div>
            </body>
            </html>
        HTML;
    };

    $initialGroup = $group(
        '10.00',
        '10.00',
    );

    $initial = $page(
        '10001',
        'A',
        '10,00',
        $initialGroup,
    );

    $variantA = $page(
        '10001',
        'A',
        '3,69',
        $group(
            '3.69',
            '10.00',
        ),
    );

    $variantB = $page(
        '10002',
        'B',
        '7,50',
        $group(
            '10.00',
            '7.50',
        ),
    );

    $calls = [];

    Http::fake(
        function (
            Request $request
        ) use (
            &$calls,
            $initial,
            $variantA,
            $variantB,
        ) {
            $url = $request->url();

            $calls[$url] =
                ($calls[$url] ?? 0) + 1;

            if (
                str_ends_with(
                    $url,
                    '_9000-10001',
                )
            ) {
                return Http::response(
                    $calls[$url] === 1
                        ? $initial
                        : $variantA,
                    200,
                );
            }

            if (
                str_ends_with(
                    $url,
                    '_9000-10002',
                )
            ) {
                return Http::response(
                    $variantB,
                    200,
                );
            }

            return Http::response(
                'unexpected',
                404,
            );
        }
    );

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            'https://www.seni24.pl/test-price-drift_9000-10001',
        );

    $candidates = collect(
        $result['variant_candidates'],
    )->keyBy('external_variant_id');

    expect($result['variants_unresolved'])
        ->toBeFalse()
        ->and(
            $result['raw_context'][
                'structured_variant_hydration'
            ]['succeeded']
        )->toBeTrue()
        ->and(
            $candidates['10001'][
                'price_gross_amount'
            ]
        )->toBe(3.69)
        ->and(
            $candidates['10002'][
                'price_gross_amount'
            ]
        )->toBe(7.5);

    Http::assertSentCount(3);
});

it('uses the requested Seni24 combination instead of a stale canonical combination for visible pricing', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link
                rel="canonical"
                href="https://www.seni24.pl/test-quatro_24173-31484"
            >

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "24173",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-quatro_24173-26363#/rozmiar-s",
                  "sku": "SE-094-SM10-G04",
                  "gtin13": "5900516803384",
                  "size": "S",
                  "offers": {
                    "@type": "Offer",
                    "price": "35.79",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-quatro_24173-31484#/rozmiar-xxl",
                  "sku": "SE-094-2X10-G04",
                  "gtin13": "5900516739386",
                  "size": "XXL",
                  "offers": {
                    "@type": "Offer",
                    "price": "47.63",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Seni Super Quatro</h1>

            <div>
                Cena za 1 opak. z VAT 5% 27,44 zł
            </div>

            <div>
                Produkt dostępny
            </div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/test-quatro_24173-26363',
    );

    $candidates = collect(
        $result['variant_candidates'],
    )->keyBy('external_variant_id');

    expect(
        $result['raw_context']['selected_variant_id'],
    )->toBe('26363')
        ->and($result['price_gross_amount'])
        ->toBe(27.44)
        ->and(
            $candidates['26363']['price_gross_amount'],
        )->toBe(27.44)
        ->and(
            $candidates['31484']['price_gross_amount'],
        )->toBe(47.63)
        ->and($result['variants_unresolved'])
        ->toBeFalse();
});

it('excludes Seni24 multipack product controls from the current products variant options', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link
                rel="canonical"
                href="https://www.seni24.pl/test-quatro_24173-26363"
            >

            <script type="application/ld+json">
            {
              "@context": "https://schema.org",
              "@type": "ProductGroup",
              "productID": "24173",
              "hasVariant": [
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-quatro_24173-26363#/ilosc_sztuk-10_szt/rozmiar-s",
                  "sku": "SE-094-SM10-G04",
                  "gtin13": "5900516803384",
                  "size": "S",
                  "offers": {
                    "@type": "Offer",
                    "price": "27.44",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                },
                {
                  "@type": "Product",
                  "url": "https://www.seni24.pl/test-quatro_24173-26364#/ilosc_sztuk-10_szt/rozmiar-m",
                  "sku": "SE-094-ME10-G04",
                  "gtin13": "5900516803391",
                  "size": "M",
                  "offers": {
                    "@type": "Offer",
                    "price": "31.13",
                    "priceCurrency": "PLN",
                    "availability": "https://schema.org/InStock"
                  }
                }
              ]
            }
            </script>
        </head>

        <body>
            <h1>Seni Super Quatro</h1>

            <div class="product-variants">
                <div class="product-variants-item">
                    <span class="control-label">
                        Rozmiar (Tabela rozmiarów)
                    </span>

                    <label>
                        <input
                            data-product-attribute="51"
                            name="group[51]"
                            value="1"
                            checked
                        >
                        S
                    </label>

                    <label>
                        <input
                            data-product-attribute="51"
                            name="group[51]"
                            value="2"
                        >
                        M
                    </label>
                </div>

                <div class="product-variants-item">
                    <span class="control-label">
                        Ilość sztuk
                    </span>

                    <label class="product-variants__radio">
                        <input
                            data-value="10"
                            class="input-radio unit_value"
                            type="radio"
                            data-product-attribute="52"
                            name="group[52]"
                            value="1292"
                            checked
                            data-product-pack-id-change="24173"
                            data-rate="5"
                            data-price-wt="27,44 zł"
                            data-product-type="origin"
                        >

                        <span class="radio-label">
                            <span class="multipack-size">
                                10 szt.
                            </span>

                            <span class="multipack-price">
                                (27,44 zł za opak.)
                            </span>
                        </span>
                    </label>

                    <label class="product-variants__radio">
                        <input
                            class="multipack-input-radio withpromoflag"
                            type="radio"
                            name="group[52]"
                            value="1292"
                            data-price-wt="327,56 zł"
                            data-price-unit="27,30 zł"
                            data-unit="12 x10 szt."
                            data-rate="5"
                            data-product-type="pack"
                            data-product-pack-id-change="25318"
                        >

                        <span class="radio-label promoflag">
                            <span class="multipack-size">
                                12 x10 szt.
                            </span>

                            <span class="multipack-price">
                                (27,30 zł za opak.)
                            </span>
                        </span>

                        <div class="multipackpackinginfo">
                            ZESTAW
                        </div>
                    </label>
                </div>
            </div>

            <div>
                Cena za 1 opak. z VAT 5% 27,44 zł
            </div>

            <div>
                Produkt dostępny
            </div>
        </body>
        </html>
    HTML;

    $result = app(Seni24ProductScraper::class)->extract(
        $html,
        'https://www.seni24.pl/test-quatro_24173-26363'
            .'#/ilosc_sztuk-10_szt/rozmiar-s',
    );

    $quantityGroup = collect(
        $result['variant_options'],
    )->firstWhere(
        'label',
        'Ilość sztuk',
    );

    $candidates = collect(
        $result['variant_candidates'],
    )->keyBy('external_variant_id');

    expect($quantityGroup)
        ->not->toBeNull()
        ->and($quantityGroup['options'])
        ->toBe([
            [
                'value' => '10 szt.',
                'selected' => true,
            ],
        ])
        ->and($result['variants_unresolved'])
        ->toBeFalse()
        ->and($result['variant_candidates'])
        ->toHaveCount(2)
        ->and(
            collect(
                $candidates['26363']['attributes'],
            )->firstWhere(
                'label',
                'Ilość sztuk',
            )['value']
        )->toBe('10 szt.')
        ->and(
            collect(
                $candidates['26364']['attributes'],
            )->firstWhere(
                'label',
                'Ilość sztuk',
            )['value']
        )->toBe('10 szt.')
        ->and(
            collect(
                $quantityGroup['options'],
            )->pluck('value')
        )->not->toContain(
            '12 x10 szt. (27,30 zł za opak.) ZESTAW',
        )
        ->and(
            collect(
                $candidates['26363']['attributes'],
            )->pluck('label')
        )->not->toContain(
            'Rozmiar (Tabela rozmiarów)',
        )
        ->and(
            collect(
                $candidates['26364']['attributes'],
            )->pluck('label')
        )->not->toContain(
            'Rozmiar (Tabela rozmiarów)',
        )
        ->and(
            collect(
                $candidates['26363']['attributes'],
            )->firstWhere(
                'label',
                'Rozmiar',
            )['value']
        )->toBe('S')
        ->and(
            collect(
                $candidates['26364']['attributes'],
            )->firstWhere(
                'label',
                'Rozmiar',
            )['value']
        )->toBe('M');
});

it('rejects an HTTP 200 Seni24 fallback page as a failed product scrape', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <title>Seni24</title>
        </head>

        <body>
            <h1>Co oferuje sklep Seni24.pl?</h1>

            <p>
                Generic store content without authoritative
                product commerce data.
            </p>
        </body>
        </html>
    HTML;

    Http::fake([
        '*' => Http::response(
            $html,
            200,
        ),
    ]);

    $url =
        'https://www.seni24.pl/'
        .'zel-do-higieny-intymnej-lactacyd-fresh-butelka-z-pompka'
        .'_715-24570';

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape($url);

    expect($result['name'])
        ->toBe('')
        ->and($result['canonical_url'])
        ->toBeNull()
        ->and($result['external_product_id'])
        ->toBeNull()
        ->and($result['variant_candidates'])
        ->toBe([])
        ->and($result['failed_urls'])
        ->toBe([
            $url => 'non_product_fallback_page',
        ]);
});

it('rejects an HTTP 200 Seni24 category page that contains only an incidental price', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <title>Seni24</title>
        </head>

        <body>
            <h1>Produkty do pielęgnacji i oczyszczania twarzy</h1>

            <div>
                Cena za 1 opak. 6,06 zł
            </div>

            <p>
                Generic category content rather than the requested
                product page.
            </p>
        </body>
        </html>
    HTML;

    Http::fake([
        '*' => Http::response(
            $html,
            200,
        ),
    ]);

    $url =
        'https://www.seni24.pl/'
        .'krem-ochronny-linoderm-plus-z-alantoina-50-ml'
        .'_2381-4352';

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape($url);

    expect($result['name'])
        ->toBe('')
        ->and($result['price_gross_amount'])
        ->toBeNull()
        ->and($result['external_product_id'])
        ->toBeNull()
        ->and($result['failed_urls'])
        ->toBe([
            $url => 'non_product_fallback_page',
        ]);
});
