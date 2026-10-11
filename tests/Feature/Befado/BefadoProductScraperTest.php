<?php

declare(strict_types=1);

use App\Services\Befado\BefadoProductScraper;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

                <product-codes product-id="9664" class="product-codes">
                    <span class="product-codes__code" data-product-code="sku">102X018</span>
                </product-codes>

                <div class="product-attributes">
                    <div class="product-attributes__attribute">
                        <span class="product-attributes__attribute-name">Typ obuwia</span>
                        <span class="product-attributes__attribute-value">Slip-On</span>
                    </div>
                    <div class="product-attributes__attribute">
                        <span class="product-attributes__attribute-name">Rodzaj zapięcia</span>
                        <span class="product-attributes__attribute-value">Wsuwany</span>
                    </div>
                    <div class="product-attributes__attribute">
                        <span class="product-attributes__attribute-name">Kolor</span>
                        <span class="product-attributes__attribute-value">Różowy</span>
                    </div>
                    <div class="product-attributes__attribute">
                        <span class="product-attributes__attribute-name">Forma</span>
                        <span class="product-attributes__attribute-value">Honey</span>
                    </div>
                    <div class="product-attributes__attribute">
                        <span class="product-attributes__attribute-name">Płeć</span>
                        <span class="product-attributes__attribute-value">Dziewczęce</span>
                    </div>
                </div>

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
        ->and($result['attributes'])->toContain([
            'code' => 'typ-obuwia',
            'label' => 'Typ obuwia',
            'value' => 'Slip-On',
            'slug' => 'slip-on',
        ])
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

it('resolves live Befado stock-options radio controls with size labels and stock state', function (): void {
    $html = <<<'HTML'
        <html>
        <head><link rel="canonical" href="https://befado.pl/pl/p/BUTY-CHLOPIECE-SPEEDY-BEFADO/11276"></head>
        <body>
        <h1>BUTY CHŁOPIĘCE SPEEDY BEFADO</h1>
        <div class="price"><em class="main-price">66,90 zł</em></div>
        <div class="stocks">
            <div class="stock-options f-grid-6">
                <div class="option_radio option_truestock option_required">
                    <input type="radio" id="option_8_79" name="option_8" value="79" data-unavailable>
                    <label for="option_8_79"></label>
                    <label style="order:18" data-label-id="79" data-unavailable for="option_8_79">18</label>
                    <input type="radio" id="option_8_80" name="option_8" value="80">
                    <label for="option_8_80"></label>
                    <label style="order:19" data-label-id="80" for="option_8_80">20</label>
                    <input type="radio" id="option_8_68" name="option_8" value="68">
                    <label for="option_8_68">21</label>
                </div>
            </div>
        </div>
        </body>
        </html>
    HTML;

    $result = app(BefadoProductScraper::class)->extract(
        $html,
        'https://befado.pl/pl/p/BUTY-CHLOPIECE-SPEEDY-BEFADO/11276',
    );

    expect($result['requires_variants'])->toBeTrue()
        ->and($result['variants_unresolved'])->toBeFalse()
        ->and($result['variant_candidates'])->toHaveCount(3)
        ->and($result['variant_candidates'][0]['availability'])->toBe('out_of_stock')
        ->and($result['variant_candidates'][1]['attributes'][0]['value'])->toBe('20')
        ->and($result['variant_candidates'][1]['availability'])->toBe('in_stock')
        ->and($result['variant_candidates'][2]['attributes'][0]['value'])->toBe('21');
});

it('preserves Shoper cache image paths rather than fabricating unavailable originals', function (): void {
    $html = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://befado.pl/pl/p/Test/11276">
            <meta property="og:image" content="/environment/cache/images/productGfx_57969_500_500/110P535.webp">
        </head><body>
            <h1>BUTY CHŁOPIĘCE SPEEDY BEFADO</h1>
            <div class="price"><em class="main-price">66,90 zł</em></div>
        </body></html>
    HTML;

    $result = app(BefadoProductScraper::class)->extract($html, 'https://befado.pl/pl/p/Test/11276');

    expect($result['images'])->toContain([
        'url' => 'https://befado.pl/environment/cache/images/productGfx_57969_500_500/110P535.webp',
        'alt' => 'BUTY CHŁOPIĘCE SPEEDY BEFADO',
    ]);
});

it('isolates the genuine Befado short description from purchasing controls', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head>
            <title>KAPCIE MĘSKIE BEFADO HOME & GO</title>
            <link rel="canonical" href="https://befado.pl/pl/p/KAPCIE-MESKIE-BEFADO-HOME-GO/10689">
        </head>
        <body>
            <div id="box_productfull">
                <div class="innerbox">
                    <h1>KAPCIE MĘSKIE BEFADO HOME & GO</h1>
                    <div class="price">59,90 zł</div>
                    <button>Do koszyka</button>
                    <div>Rozmiar: 39 40 41 42 43</div>
                </div>
            </div>

            <div class="product-modules-cols s-row container box">
                <div class="s-grid-6 short-desc">
                    <div class="innerbox">
                        <p>Kapcie męskie to synonim wygody i elegancji.</p>
                        <p>Wygodne obuwie domowe marki Befado.</p>
                    </div>
                </div>

                <div class="s-grid-6 purchase-panel">
                    <div class="innerbox">
                        <span>59,90 zł</span>
                        <button>Do koszyka</button>
                    </div>
                </div>
            </div>
        </body>
        </html>
    HTML;

    $result = app(BefadoProductScraper::class)->extract(
        $html,
        'https://befado.pl/pl/p/KAPCIE-MESKIE-BEFADO-HOME-GO/10689',
    );

    $description = $result['description_html'];

    expect($description)->toBeString()
        ->toContain('Kapcie męskie to synonim wygody i elegancji.')
        ->toContain('Wygodne obuwie domowe marki Befado.')
        ->not->toContain('Do koszyka')
        ->not->toContain('59,90 zł')
        ->not->toContain('Rozmiar:');
});

it('upgrades a Befado gallery thumbnail after HTTP verification', function (): void {
    $pageUrl = 'https://befado.pl/pl/p/Test-Befado/1234';
    $thumbnail = 'https://befado.pl/environment/cache/images/productGfx_53192_120_120/photo.webp';
    $hdImage = 'https://befado.pl/environment/cache/images/productGfx_53192_700_700/photo.webp';

    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://befado.pl/pl/p/Test-Befado/1234">
        </head>
        <body>
            <h1>Test Befado</h1>
            <div class="price"><em class="main-price">59,90 zł</em></div>
            <div id="box_description">
                <div class="innerbox"><p>Wygodne obuwie domowe.</p></div>
            </div>
            <div class="productimg">
                <img src="/environment/cache/images/productGfx_53192_120_120/photo.webp">
            </div>
        </body>
        </html>
    HTML;

    Http::fake([
        $pageUrl => Http::response($html, 200),
        $hdImage => Http::response('', 200, [
            'Content-Type' => 'image/webp',
        ]),
    ]);

    $result = app(BefadoProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape($pageUrl);

    expect($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['url'])->toBe($hdImage);

    Http::assertSent(
        fn (Request $request) => $request->method() === 'HEAD'
            && $request->url() === $hdImage
    );
});

it('keeps the Befado thumbnail when HD verification fails', function (): void {
    $pageUrl = 'https://befado.pl/pl/p/Test-Befado/1234';
    $thumbnail = 'https://befado.pl/environment/cache/images/productGfx_53192_120_120/photo.webp';
    $hdImage = 'https://befado.pl/environment/cache/images/productGfx_53192_700_700/photo.webp';

    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://befado.pl/pl/p/Test-Befado/1234">
        </head>
        <body>
            <h1>Test Befado</h1>
            <div class="price"><em class="main-price">59,90 zł</em></div>
            <div id="box_description">
                <div class="innerbox"><p>Wygodne obuwie domowe.</p></div>
            </div>
            <div class="productimg">
                <img src="/environment/cache/images/productGfx_53192_120_120/photo.webp">
            </div>
        </body>
        </html>
    HTML;

    Http::fake([
        $pageUrl => Http::response($html, 200),
        $hdImage => Http::response('', 404),
    ]);

    $result = app(BefadoProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape($pageUrl);

    expect($result['images'])->toHaveCount(1)
        ->and($result['images'][0]['url'])->toBe($thumbnail);

    Http::assertSent(
        fn (Request $request) => $request->method() === 'HEAD'
            && $request->url() === $hdImage
    );
});

it('prefers original Shoper gallery JPEGs over cached WebP thumbnails', function (): void {
    $html = <<<'HTML'
        <html>
        <head>
            <link rel="canonical" href="https://befado.pl/pl/p/Test-Befado/11276">
            <meta property="og:image" content="/environment/cache/images/productGfx_57969_500_500/110P535.webp">
        </head>
        <body>
            <h1>BUTY CHŁOPIĘCE SPEEDY BEFADO</h1>
            <div class="price"><em class="main-price">66,90 zł</em></div>
            <div id="box_productfull">
                <div class="productimg">
                    <img src="/environment/cache/images/productGfx_57969_700_700/110P535.webp">
                    <div class="smallgallery">
                        <a class="gallery js__gallery-anchor-image"
                           href="/userdata/public/gfx/57969/110P535.jpg">
                            <img src="/environment/cache/images/productGfx_57969_120_120/110P535.webp">
                        </a>
                        <a class="gallery js__gallery-anchor-image"
                           href="/userdata/public/gfx/57968/110P535_2.jpg">
                            <img src="/environment/cache/images/productGfx_57968_120_120/110P535_2.webp">
                        </a>
                    </div>
                </div>
            </div>
        </body>
        </html>
    HTML;

    $result = app(BefadoProductScraper::class)->extract(
        $html,
        'https://befado.pl/pl/p/Test-Befado/11276',
    );

    expect($result['images'])->toHaveCount(2)
        ->and(array_column($result['images'], 'url'))->toBe([
            'https://befado.pl/userdata/public/gfx/57969/110P535.jpg',
            'https://befado.pl/userdata/public/gfx/57968/110P535_2.jpg',
        ]);
});
