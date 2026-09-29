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
            </nav>

            <main>
                <h1>Skarpety bezuciskowe z wełną merynosową DeoMed Wool</h1>
                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">Rozmiar</span>
                        <label><input data-product-attribute="1" name="group[1]" value="43-46" checked>43-46</label>
                        <label><input data-product-attribute="1" name="group[1]" value="39-42">39-42</label>
                    </div>
                    <div class="product-variants-item">
                        <span class="control-label">Kolor</span>
                        <label><input data-product-attribute="2" name="group[2]" value="Czarny" checked>Czarny</label>
                        <label><input data-product-attribute="2" name="group[2]" value="Ciemny szary">Ciemny szary</label>
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
