<?php

declare(strict_types=1);

use App\Services\Befado\BefadoProductScraper;

it('extracts Befado Shoper product data and size stock variants', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head>
            <title>BUTY DZIECIĘCE HONEY BEFADO - Befado</title>
            <meta name="description" content="Lekkie obuwie dziecięce Befado.">
            <meta itemprop="priceCurrency" content="PLN">
            <meta property="og:image" content="/userdata/public/gfx/48033/102X018.jpg">
            <link rel="canonical" href="https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664">
        </head>
        <body class="shop_product product-9664">
            <div class="breadcrumbs">
                <a href="/">Strona główna</a>
                <a href="/pl/c/Obuwie-dzieciece/10">Obuwie dziecięce</a>
                <span>BUTY DZIECIECE HONEY BEFADO</span>
            </div>

            <div id="box_productfull">
                <div class="productimg">
                    <img itemprop="image" src="/userdata/public/gfx/48033/102X018.jpg" alt="BUTY DZIECIECE HONEY BEFADO">
                </div>

                <h1 itemprop="name">BUTY DZIECIĘCE HONEY BEFADO</h1>
                <p class="producer">Producent: <a href="/--befado">Befado</a></p>
                <p class="availability">Dostępność: W magazynie</p>
                <p class="shipping-time">Wysyłka w: 48 godzin</p>
                <div class="price"><em class="main-price">49,90 zł</em></div>

                <table class="product-params">
                    <tr><th>Kod produktu</th><td>102X018</td></tr>
                    <tr><th>Typ obuwia</th><td>Slip-On</td></tr>
                    <tr><th>Rodzaj zapięcia</th><td>Wsuwany</td></tr>
                    <tr><th>Kolor</th><td>Różowy</td></tr>
                    <tr><th>Forma</th><td>Honey</td></tr>
                    <tr><th>Płeć</th><td>Dziewczęce</td></tr>
                </table>

                <product-variants product-id="9664" variant-id="501">
                    <div class="size-options">
                        <span>Rozmiar:</span>

                        <input type="radio" id="option_10_501" name="option_10" value="501" checked>
                        <label for="option_10_501">23</label>

                        <input type="radio" id="option_10_502" name="option_10" value="502" data-unavailable>
                        <label for="option_10_502">24</label>
                    </div>
                </product-variants>

                <div class="size-map">
                    18:11,5cm#19:12,0cm#20:12,5cm#21:13,5cm#22:14,5cm#23:15,0cm#24:15,5cm#25:16,2cm#
                </div>
            </div>

            <div id="box_description">
                <div class="innerbox">
                    <h2>Opis</h2>
                    <p>Wygodne dziecięce obuwie tekstylne Befado Honey.</p>
                </div>
            </div>

            <script>window.product_id = 9664;</script>
        </body>
        </html>
    HTML;

    $result = app(BefadoProductScraper::class)->extract(
        $html,
        'https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664',
    );

    expect($result)->toMatchArray([
        'source' => 'befado',
        'external_product_id' => '9664',
        'name' => 'BUTY DZIECIĘCE HONEY BEFADO',
        'price_gross_amount' => 49.90,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'W magazynie',
        'shipping_time' => '48 godzin',
        'sku' => '102X018',
        'requires_variants' => true,
        'variants_unresolved' => false,
        'is_medical_device' => false,
    ])
        ->and($result['brand'])->toMatchArray(['name' => 'Befado'])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => 'option-501',
            'label' => 'Rozmiar: 23',
            'availability' => 'in_stock',
            'attributes' => [
                ['label' => 'Rozmiar', 'value' => '23'],
                ['label' => 'Długość wkładki', 'value' => '15.0 cm'],
            ],
        ])
        ->and($result['variant_candidates'][1])->toMatchArray([
            'external_variant_id' => 'option-502',
            'label' => 'Rozmiar: 24',
            'availability' => 'out_of_stock',
        ]);
});

it('fails closed when a Befado product requires sizes but concrete variants cannot be resolved', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://befado.pl/pl/p/Test-Befado/1234">
        </head>
        <body>
            <h1>Test Befado</h1>
            <div id="box_productfull">
                <div>Rozmiar: wybierz rozmiar</div>
                <div class="price"><em class="main-price">59,90 zł</em></div>
            </div>
            <script>window.product_id = 1234;</script>
        </body>
        </html>
    HTML;

    $result = app(BefadoProductScraper::class)->extract(
        $html,
        'https://befado.pl/pl/p/Test-Befado/1234',
    );

    expect($result['requires_variants'])->toBeTrue()
        ->and($result['variants_unresolved'])->toBeTrue()
        ->and($result['variant_candidates'])->toBe([]);
});
