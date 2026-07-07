<?php

use App\Services\Apolonia\ApoloniaProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts Apolonia product details from an IdoSell product page', function (): void {
    $html = apoloniaProductPageFixture();

    $result = app(ApoloniaProductScraper::class)->extract(
        $html,
        'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
        [
            'name' => 'Bluza medyczna damska bordo BL 60',
            'category_name' => 'Odzież medyczna',
            'category_url' => 'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'top_category_name' => 'Odzież medyczna',
            'top_category_url' => 'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'category_path' => ['Odzież medyczna', 'Bluzy medyczne'],
        ],
    );

    expect($result)->toMatchArray([
        'source' => 'apolonia',
        'source_url' => 'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
        'canonical_url' => 'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
        'external_product_id' => '2970',
        'slug' => 'bluza-medyczna-damska-bordo-bl-60-krotki-rekaw-comfort-stretch',
        'name' => 'Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch',
        'sku' => 'BL60-BORDO',
        'price_gross_amount' => 19700,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Produkt dostępny Wysyłka w 24h',
        'category' => 'Bluzy medyczne',
        'categories' => ['Odzież medyczna', 'Bluzy medyczne'],
        'seo_title' => 'Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch | Apolonia',
        'seo_description' => 'Wygodna bluza medyczna damska bordo BL 60.',
        'short_description' => 'Wygodna bluza medyczna damska bordo BL 60.',
        'failed_urls' => [],
        'warnings' => [],
    ])
        ->and($result['description_html'])->toContain('<h2>Opis produktu</h2>')
        ->and($result['description_html'])->toContain('https://www.apolonia.com.pl/data/include/cms/bluza-opis.webp')
        ->and($result['description_html'])->not->toContain('<script')
        ->and($result['description_html'])->not->toContain('href=')
        ->and($result['description_plain'])->toContain('Elastyczna tkanina Comfort Stretch')
        ->and($result['images'])->toHaveCount(2)
        ->and($result['images'][0])->toMatchArray([
            'url' => 'https://www.apolonia.com.pl/data/gfx/pictures/large/bl60-bordo.webp',
            'alt' => 'Bluza medyczna damska bordo BL 60',
        ])
        ->and($result['attributes'])->toContain([
            'label' => 'Symbol',
            'value' => 'BL60-BORDO',
            'code' => 'symbol',
            'slug' => 'bl60-bordo',
        ])
        ->and($result['attributes'])->toContain([
            'label' => 'Kolor',
            'value' => 'bordo',
            'code' => 'kolor',
            'slug' => 'bordo',
        ])
        ->and($result['variant_candidates'])->toHaveCount(3)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '1',
            'label' => 'XS',
            'sku' => 'BL60-BORDO-XS',
            'price_gross_amount' => 19700,
            'currency' => 'PLN',
            'attributes' => [[
                'code' => 'rozmiar',
                'label' => 'Rozmiar',
                'value' => 'XS',
                'slug' => 'xs',
            ]],
        ]);
});


it('extracts Apolonia clothing sizes from product_data with the selected colour and fabric variant', function (): void {
    $result = app(ApoloniaProductScraper::class)->extract(
        apoloniaClothingProductDataFixture(),
        'https://www.apolonia.com.pl/product-pol-2219-Dopasowana-bluza-medyczna-damska-amarantowa-BL-62-Elegant-Stretch.html',
    );

    expect($result)->toMatchArray([
        'external_product_id' => '2219',
        'name' => 'Dopasowana bluza medyczna damska amarantowa BL 62, Elegant Stretch',
        'price_gross_amount' => 19700,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Produkt dostępny w bardzo małej ilości',
        'categories' => ['Odzież medyczna', 'Bluzy medyczne', 'Bluzy medyczne damskie'],
    ])
        ->and($result['attributes'])->toContain([
            'label' => 'Kolor i tkanina',
            'value' => 'Amarantowy (Elegant Stretch | K89)',
            'code' => 'kolor-i-tkanina',
            'slug' => 'amarantowy-elegant-stretch-k89',
        ])
        ->and($result['variant_candidates'])->toHaveCount(3)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '2219-2',
            'label' => 'XS',
            'price_gross_amount' => 19700,
            'currency' => 'PLN',
            'availability' => 'preorder',
            'availability_label' => 'Produkt na zamówienie',
            'source_availability_status' => 'order',
            'stock_quantity' => 0,
            'attributes' => [
                [
                    'code' => 'kolor-i-tkanina',
                    'label' => 'Kolor i tkanina',
                    'value' => 'Amarantowy (Elegant Stretch | K89)',
                    'slug' => 'amarantowy-elegant-stretch-k89',
                ],
                [
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'XS',
                    'slug' => 'xs',
                ],
            ],
        ])
        ->and($result['variant_candidates'][1])->toMatchArray([
            'external_variant_id' => '2219-3',
            'label' => 'S',
            'availability' => 'in_stock',
            'stock_quantity' => 1,
        ]);
});

it('extracts Apolonia shoe numeric sizes and high-resolution gallery images', function (): void {
    $result = app(ApoloniaProductScraper::class)->extract(
        apoloniaShoeProductDataFixture(),
        'https://www.apolonia.com.pl/product-pol-3132-Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient.html',
    );

    expect($result)->toMatchArray([
        'external_product_id' => '3132',
        'name' => 'Buty sportowe Wock ACTIONPRO 06, biały - niebieski gradient',
        'price_gross_amount' => 32600,
        'availability' => 'in_stock',
        'categories' => ['Obuwie medyczne', 'Obuwie medyczne męskie'],
    ])
        ->and($result['images'])->toHaveCount(1)
        ->and($result['images'][0])->toMatchArray([
            'url' => 'https://www.apolonia.com.pl/hpeciai/f6d4c975f9686ce60bde6b1af9d0465a/pol_pl_Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient-3132_1.webp',
            'alt' => 'Buty sportowe Wock ACTIONPRO 06, biały - niebieski gradient',
        ])
        ->and($result['variant_candidates'])->toHaveCount(3)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '3132-X',
            'label' => '36',
            'availability' => 'preorder',
            'attributes' => [
                [
                    'code' => 'kolor',
                    'label' => 'Kolor',
                    'value' => 'Biały - niebieski gradient',
                    'slug' => 'bialy-niebieski-gradient',
                ],
                [
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => '36',
                    'slug' => '36',
                ],
            ],
        ])
        ->and($result['variant_candidates'][1])->toMatchArray([
            'external_variant_id' => '3132-11',
            'label' => '40',
            'availability' => 'in_stock',
            'stock_quantity' => 1,
        ]);
});

it('uses JSON-LD offer data as an Apolonia price and availability fallback', function (): void {
    $result = app(ApoloniaProductScraper::class)->extract(
        apoloniaJsonLdOfferFixture(),
        'https://www.apolonia.com.pl/product-pol-4000-Testowy-produkt.html',
    );

    expect($result)->toMatchArray([
        'external_product_id' => '4000',
        'name' => 'Testowy produkt Apolonia',
        'price_gross_amount' => 12345,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'InStock',
    ]);
});

it('falls back to Apolonia short product description when a long description is missing', function (): void {
    $result = app(ApoloniaProductScraper::class)->extract(
        apoloniaMissingLongDescriptionFixture(),
        'https://www.apolonia.com.pl/product-pol-2832-Koszulka-polo-meska-blekitna-Malfini-COTTON-HEAVY-215.html',
    );

    expect($result)->toMatchArray([
        'external_product_id' => '2832',
        'name' => 'Koszulka polo męska błękitna Malfini COTTON HEAVY 215',
        'price_gross_amount' => 7900,
        'currency' => 'PLN',
        'short_description' => 'Zapraszamy do zapoznania się z: Koszulka polo męska błękitna Malfini COTTON HEAVY 215 Błękitny. Sprawdź Sam!',
    ])
        ->and($result['description_html'])->toContain('<p>Oddychająca koszulka polo męska w kolorze błękitnym.</p>')
        ->and($result['description_html'])->toContain('<p>Zapraszamy do zapoznania się z: Koszulka polo męska błękitna Malfini COTTON HEAVY 215 Błękitny. Sprawdź Sam!</p>')
        ->and($result['description_plain'])->toContain('Oddychająca koszulka polo męska w kolorze błękitnym.');
});

it('keeps Apolonia product-link category context when breadcrumbs are missing', function (): void {
    $html = str_replace(
        '<nav class="breadcrumbs"><a href="/">Strona główna</a><a href="/pol_m_Odziez-medyczna-241.html">Odzież medyczna</a><a href="/pol_m_Odziez-medyczna_Bluzy-medyczne-259.html">Bluzy medyczne</a><span>Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch</span></nav>',
        '',
        apoloniaProductPageFixture(),
    );

    $result = app(ApoloniaProductScraper::class)->extract(
        $html,
        'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
        [
            'category_name' => 'Bluzy medyczne',
            'category_path' => ['Odzież medyczna', 'Bluzy medyczne'],
        ],
    );

    expect($result['categories'])->toBe(['Odzież medyczna', 'Bluzy medyczne'])
        ->and($result['category'])->toBe('Bluzy medyczne');
});

it('returns a failed Apolonia product payload when the product request fails', function (): void {
    Http::fake([
        'https://www.apolonia.com.pl/product-pol-9999-Missing.html' => Http::response('', 404),
        '*' => Http::response('', 500),
    ]);

    $result = app(ApoloniaProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://www.apolonia.com.pl/product-pol-9999-Missing.html');

    expect($result)->toMatchArray([
        'source' => 'apolonia',
        'source_url' => 'https://www.apolonia.com.pl/product-pol-9999-Missing.html',
        'external_product_id' => null,
        'name' => '',
        'failed_urls' => [
            'https://www.apolonia.com.pl/product-pol-9999-Missing.html' => 'HTTP 404',
        ],
    ]);
});

function apoloniaProductPageFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch | Apolonia</title>
                <meta name="description" content="Wygodna bluza medyczna damska bordo BL 60.">
                <meta property="og:title" content="Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch | Apolonia">
                <meta property="og:image" content="/data/gfx/pictures/large/bl60-bordo-drugi.webp">
                <meta property="product:price:amount" content="197.00">
                <link rel="canonical" href="/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html">
            </head>
            <body>
                <nav class="breadcrumbs"><a href="/">Strona główna</a><a href="/pol_m_Odziez-medyczna-241.html">Odzież medyczna</a><a href="/pol_m_Odziez-medyczna_Bluzy-medyczne-259.html">Bluzy medyczne</a><span>Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch</span></nav>
                <main id="content">
                    <form id="projector_form">
                        <h1 itemprop="name">Bluza medyczna damska bordo BL 60 krótki rękaw Comfort Stretch</h1>
                        <strong id="projector_price_value">197,00 zł brutto</strong>
                        <span class="projector_status__description">Produkt dostępny Wysyłka w 24h</span>
                        <div id="projector_sizes_cont"><button>XS</button><button>S</button><button>M</button></div>
                    </form>
                    <div id="projector_photos">
                        <img data-src="/data/gfx/pictures/large/bl60-bordo.webp" alt="Bluza medyczna damska bordo BL 60">
                    </div>
                    <section id="projector_longdescription">
                        <h2>Opis produktu</h2>
                        <p>Elastyczna tkanina Comfort Stretch zapewnia wygodę przez cały dzień.</p>
                        <p><a href="https://www.apolonia.com.pl/contact-pol.html">Kontakt</a></p>
                        <p><img src="/data/include/cms/bluza-opis.webp" srcset="/data/include/cms/bluza-opis.webp 1x" alt="Tabela rozmiarów"></p>
                        <script>alert('x')</script>
                    </section>
                    <table class="dictionary">
                        <tr><th>Symbol</th><td>BL60-BORDO</td></tr>
                        <tr><th>Kod producenta</th><td>5901234567890</td></tr>
                        <tr><th>Kolor</th><td>bordo</td></tr>
                        <tr><th>Rękaw</th><td>krótki</td></tr>
                        <tr><th>Tkanina</th><td>Comfort Stretch</td></tr>
                        <tr><th>Kolekcja</th><td>Comfort Stretch</td></tr>
                    </table>
                </main>
            </body>
        </html>
        HTML;
}


function apoloniaClothingProductDataFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Dopasowana bluza medyczna damska amarantowa BL 62, Elegant Stretch</title>
                <link rel="canonical" href="/product-pol-2219-Dopasowana-bluza-medyczna-damska-amarantowa-BL-62-Elegant-Stretch.html">
            </head>
            <body>
                <div id="breadcrumbs" class="breadcrumbs"><a href="/">Strona główna</a><a href="/pol_m_Odziez-medyczna-241.html">Odzież medyczna</a><a href="/pol_m_Odziez-medyczna_Bluzy-medyczne-259.html">Bluzy medyczne</a><a href="/pol_m_Odziez-medyczna_Bluzy-medyczne_Bluzy-medyczne-damskie-207.html">Bluzy medyczne damskie</a><span>Dopasowana bluza medyczna damska amarantowa BL 62, Elegant Stretch</span></div>
                <main id="content">
                    <h1 class="product_name__name">Dopasowana bluza medyczna damska amarantowa BL 62, Elegant Stretch</h1>
                    <div id="projector_variants_section" class="projector_variants">
                        <span class="projector_variants__label">Kolor i tkanina</span>
                        <select class="projector_variants__select">
                            <option selected data-link="/product-pol-2219-Dopasowana-bluza-medyczna-damska-amarantowa-BL-62-Elegant-Stretch.html">Amarantowy (Elegant Stretch | K89)</option>
                            <option data-link="/product-pol-1421-Dopasowana-bluza-medyczna-damska-bez-nude-BL-62-scrubs-Elegant-Stretch.html">Beż nude (Elegant Stretch | K48)</option>
                        </select>
                    </div>
                    <section id="projector_longdescription"><p>Bluza medyczna damska amarantowa</p></section>
                </main>
                <script class="ajaxLoad">
                    window.product_data = [{
                        id: 2219,
                        currency: "zł",
                        base_price: {
                            value: "197.00",
                            price_formatted: "197,00 zł"
                        },
                        sizes: [
                            {
                                name: "XS",
                                id: "2",
                                product_id: 2219,
                                amount_mw: 0,
                                availability: {
                                    description: "Produkt na zamówienie",
                                    status: "order"
                                },
                                price: {
                                    price: {
                                        gross: {
                                            value: 197.00,
                                            formatted: "197,00 zł"
                                        }
                                    }
                                }
                            },
                            {
                                name: "S",
                                id: "3",
                                product_id: 2219,
                                amount_mw: 1,
                                availability: {
                                    description: "Produkt dostępny w bardzo małej ilości",
                                    status: "enable"
                                },
                                price: {
                                    price: {
                                        gross: {
                                            value: 197.00,
                                            formatted: "197,00 zł"
                                        }
                                    }
                                }
                            },
                            {
                                name: "M",
                                id: "4",
                                product_id: 2219,
                                amount_mw: 0,
                                availability: {
                                    description: "Produkt na zamówienie",
                                    status: "order"
                                },
                                price: {
                                    price: {
                                        gross: {
                                            value: 197.00,
                                            formatted: "197,00 zł"
                                        }
                                    }
                                }
                            },
                        ],
                    }];
                </script>
            </body>
        </html>
        HTML;
}

function apoloniaShoeProductDataFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Buty sportowe Wock ACTIONPRO 06, biały - niebieski gradient</title>
                <link rel="canonical" href="/product-pol-3132-Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient.html">
                <meta property="og:image" content="/hpeciai/f6d4c975f9686ce60bde6b1af9d0465a/pol_pl_Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient-3132_1.webp">
            </head>
            <body>
                <div id="breadcrumbs" class="breadcrumbs"><a href="/">Strona główna</a><a href="/pol_m_Obuwie-medyczne-230.html">Obuwie medyczne</a><a href="/pol_m_Obuwie-medyczne_Obuwie-medyczne-meskie-194.html">Obuwie medyczne męskie</a><span>Buty sportowe Wock ACTIONPRO 06, biały - niebieski gradient</span></div>
                <main id="content">
                    <section id="projector_photos" class="photos">
                        <figure class="photos__figure">
                            <picture>
                                <source srcset="/hpeciai/f95841fb16a7f1807b86dcc538b4f5af/pol_pm_Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient-3132_1.webp" data-img_high_res_webp="/hpeciai/f6d4c975f9686ce60bde6b1af9d0465a/pol_pl_Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient-3132_1.webp">
                                <img class="photos__photo" src="/hpeciai/b3abb02aeae059a050c53ba3f195c7f5/pol_pm_Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient-3132_1.jpg" alt="Buty sportowe Wock ACTIONPRO 06, biały - niebieski gradient" data-img_high_res="/hpeciai/a760b00f12c7717e45c1b54d480e48e5/pol_pl_Buty-sportowe-Wock-ACTIONPRO-06-bialy-niebieski-gradient-3132_1.jpg">
                            </picture>
                        </figure>
                    </section>
                    <h1 class="product_name__name">Buty sportowe Wock ACTIONPRO 06, biały - niebieski gradient</h1>
                    <div id="projector_variants_section" class="projector_variants">
                        <span class="projector_variants__label">Kolor</span>
                        <select class="projector_variants__select"><option selected>Biały - niebieski gradient</option><option>Czarny</option></select>
                    </div>
                    <section id="projector_longdescription"><p>Obuwie o niezawodnej przyczepności.</p></section>
                </main>
                <script class="ajaxLoad">
                    window.product_data = [{
                        id: 3132,
                        currency: "zł",
                        base_price: {
                            value: "326.00",
                            price_formatted: "326,00 zł"
                        },
                        sizes: [
                            {name: "36", id: "X", product_id: 3132, amount_mw: 0, availability: {description: "Produkt na zamówienie", status: "order"}, price: {price: {gross: {value: 326.00, formatted: "326,00 zł"}}}},
                            {name: "40", id: "11", product_id: 3132, amount_mw: 1, availability: {description: "Produkt dostępny w bardzo małej ilości", status: "enable"}, price: {price: {gross: {value: 326.00, formatted: "326,00 zł"}}}},
                            {name: "41", id: "12", product_id: 3132, amount_mw: 0, availability: {description: "Produkt na zamówienie", status: "order"}, price: {price: {gross: {value: 326.00, formatted: "326,00 zł"}}}},
                        ],
                    }];
                </script>
            </body>
        </html>
        HTML;
}

function apoloniaMissingLongDescriptionFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Koszulka polo męska błękitna Malfini COTTON HEAVY 215 | Błękitny | Sklep z odzieżą medyczną Warszawa Apolonia</title>
                <meta name="description" content="Zapraszamy do zapoznania się z: Koszulka polo męska błękitna Malfini COTTON HEAVY 215 Błękitny. Sprawdź Sam!">
                <link rel="canonical" href="/product-pol-2832-Koszulka-polo-meska-blekitna-Malfini-COTTON-HEAVY-215.html">
            </head>
            <body>
                <main id="content">
                    <h1 class="product_name__name">Koszulka polo męska błękitna Malfini COTTON HEAVY 215</h1>
                    <div class="product_name__block --description mt-3"><ul><li>Oddychająca koszulka polo męska w kolorze błękitnym.</li></ul></div>
                    <div id="projector_variants_section" class="projector_variants">
                        <span class="projector_variants__label">Kolor</span>
                        <select class="projector_variants__select"><option selected>Błękitny</option></select>
                    </div>
                </main>
                <script class="ajaxLoad">
                    window.product_data = [{
                        id: 2832,
                        currency: "zł",
                        base_price: {
                            value: "79.00",
                            price_formatted: "79,00 zł"
                        },
                        sizes: [
                            {name: "S", id: "3", product_id: 2832, amount_mw: 1, availability: {description: "Produkt dostępny", status: "enable"}, price: {price: {gross: {value: 79.00, formatted: "79,00 zł"}}}},
                        ],
                    }];
                </script>
            </body>
        </html>
        HTML;
}

function apoloniaJsonLdOfferFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Testowy produkt Apolonia</title>
                <link rel="canonical" href="/product-pol-4000-Testowy-produkt.html">
                <script type="application/ld+json">
                    {
                        "@context": "https://schema.org",
                        "@type": "Product",
                        "name": "Testowy produkt Apolonia",
                        "offers": {
                            "@type": "Offer",
                            "price": "123.45",
                            "priceCurrency": "PLN",
                            "availability": "https://schema.org/InStock"
                        }
                    }
                </script>
            </head>
            <body>
                <h1 class="product_name__name">Testowy produkt Apolonia</h1>
                <section id="projector_longdescription"><p>Produkt testowy.</p></section>
            </body>
        </html>
        HTML;
}
