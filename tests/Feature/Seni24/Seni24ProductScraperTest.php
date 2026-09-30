<?php

declare(strict_types=1);

use App\Services\Seni24\Seni24ProductScraper;

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
