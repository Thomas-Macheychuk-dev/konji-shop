<?php

declare(strict_types=1);

use App\Services\Iconic\IconicProductDataCrawler;
use App\Services\Iconic\IconicProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('extracts normalized Iconic product data, gallery, categories and size variants', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>But Pooperacyjny DARCO - Relief Dual | ICONIC</title>
                <meta name="description" content="But pooperacyjny do odciążania stopy.">
                <meta property="og:title" content="But Pooperacyjny DARCO - Relief Dual">
                <link rel="canonical" href="https://sklep.iconic.pl/produkty/relief-dual.html">
            </head>
            <body>
                <nav class="breadcrumbs">
                    <a href="/">Home</a>
                    <a href="/produkty/zaopatrzenie-ortopedyczne-stopy">Zaopatrzenie ortopedyczne stopy</a>
                    <a href="/produkty/zaopatrzenie-po-zabiegach-na-hallux-valgus">Zaopatrzenie po operacjach Hallux Valgus</a>
                    <span>But Pooperacyjny DARCO - Relief Dual</span>
                </nav>

                <main>
                    <h1>But Pooperacyjny DARCO - Relief Dual</h1>

                    <div class="gallery">
                        <a href="/_images/produkty/DARCO/ReliefDual.jpg">
                            <img src="/.miniatury/500/567/ReliefDual_mini.jpg" alt="Relief Dual">
                        </a>
                        <img src="/_images/produkty/DARCO/ReliefDual-detail.webp" alt="Relief Dual detal">
                    </div>

                    <div class="product-summary">
                        <strong>176.00 zł</strong>
                        <div>Dostępny: Dostępny</div>
                        <div>Czas wysyłki: 24 h - 2 dni</div>
                        <div>Jednostka miary: sztuka</div>
                        <label for="size">Rozmiar:</label>
                        <select id="size" name="size">
                            <option value="">Wybierz opcję</option>
                            <option value="ms">MS (39,0 – 41,0) do 26 cm</option>
                            <option value="mm">MM (41,5 – 43,0) do 27,5 cm</option>
                        </select>
                        <div>Numer katalogowy: RD-M1</div>
                    </div>

                    <h2>Opis produktu</h2>
                    <div class="product-description">
                        <h3>Ortopedyczne buty pooperacyjne DARCO Relief Dual</h3>
                        <p>Relief Dual to certyfikowany wyrób medyczny klasy I przeznaczony do odciążania stopy.</p>
                    </div>

                    <a href="/files/relief-dual-instrukcja.pdf">Instrukcja użytkowania</a>
                </main>
            </body>
        </html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/relief-dual.html',
        [
            'listing_pages' => [
                'https://sklep.iconic.pl/produkty/zaopatrzenie-ortopedyczne-stopy',
            ],
        ],
    );

    expect($result)->toMatchArray([
        'source' => 'iconic',
        'canonical_url' => 'https://sklep.iconic.pl/produkty/relief-dual.html',
        'external_product_id' => 'relief-dual',
        'slug' => 'relief-dual',
        'name' => 'But Pooperacyjny DARCO - Relief Dual',
        'sku' => 'RD-M1',
        'catalogue_number' => 'RD-M1',
        'price_gross_amount' => 176.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'is_on_order' => false,
        'shipping_time' => '24 h - 2 dni',
        'unit' => 'sztuka',
        'category' => 'Zaopatrzenie po operacjach Hallux Valgus',
        'categories' => [
            'Zaopatrzenie ortopedyczne stopy',
            'Zaopatrzenie po operacjach Hallux Valgus',
        ],
        'is_medical_device' => true,
        'medical_device_class' => 'I',
    ])
        ->and($result['description_html'])->toContain('certyfikowany wyrób medyczny klasy I')
        ->and($result['seo_description'])->toBe('But pooperacyjny do odciążania stopy.')
        ->and($result['images'])->toBe([
            [
                'url' => 'https://sklep.iconic.pl/_images/produkty/DARCO/ReliefDual-detail.webp',
                'alt' => 'Relief Dual detal',
            ],
            [
                'url' => 'https://sklep.iconic.pl/_images/produkty/DARCO/ReliefDual.jpg',
                'alt' => '',
            ],
        ])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => 'ms',
            'label' => 'Rozmiar: MS (39,0 – 41,0) do 26 cm',
            'price_gross_amount' => 176.0,
            'currency' => 'PLN',
            'attributes' => [
                [
                    'label' => 'Rozmiar',
                    'value' => 'MS (39,0 – 41,0) do 26 cm',
                ],
            ],
        ])
        ->and($result['downloads'])->toBe([
            [
                'label' => 'Instrukcja użytkowania',
                'url' => 'https://sklep.iconic.pl/files/relief-dual-instrukcja.pdf',
            ],
        ])
        ->and($result['failed_urls'])->toBe([]);
});

it('uses the live Iconic product heading and ignores related-product prices', function (): void {
    $html = <<<'HTML'
        <html lang="pl">
            <head>
                <link rel="canonical" href="https://sklep.iconic.pl/produkty/relief-dual-live.html">
            </head>
            <body>
                <nav class="breadcrumbs">
                    <a href="/">Home</a>
                    <a href="/produkty/zaopatrzenie-ortopedyczne-stopy">Zaopatrzenie ortopedyczne stopy</a>
                </nav>

                <main>
                    <h2>But Pooperacyjny DARCO - Relief Dual</h2>
                    <div class="gallery"><img src="/_images/produkty/DARCO/relief.jpg"></div>

                    <h2>But Pooperacyjny DARCO - Relief Dual</h2>
                    <div>176.00 zł</div>
                    <div>Dostępny: Dostępny</div>
                    <div>Czas wysyłki: 24 h - 2 dni</div>
                    <div>Jednostka miary: sztuka</div>
                    <div>
                        Rozmiar:
                        <select>
                            <option value="">Wybierz opcję</option>
                            <option value="1195">MS (39,0 – 41,0) do 26 cm</option>
                        </select>
                    </div>
                    <div>Numer katalogowy: RD-M1</div>

                    <div>Opis produktu</div>
                    <section>
                        <h1>Ortopedyczne buty pooperacyjne DARCO Relief Dual odciążające stopę</h1>
                        <p>Buty DARCO Relief Dual to certyfikowany wyrób medyczny klasy I przeznaczony do odciążania stopy.</p>
                        <p>Cena dotyczy jednej sztuki.</p>
                    </section>

                    <h2>Inne produkty w kategorii</h2>
                    <a href="/produkty/medsurg.html">But Pooperacyjny DARCO - MedSurg Pro 110.00 zł</a>
                </main>
            </body>
        </html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/relief-dual-live.html',
    );

    expect($result['name'])->toBe('But Pooperacyjny DARCO - Relief Dual')
        ->and($result['price_gross_amount'])->toBe(176.0)
        ->and($result['availability'])->toBe('in_stock')
        ->and($result['availability_label'])->toBe('Dostępny')
        ->and($result['shipping_time'])->toBe('24 h - 2 dni')
        ->and($result['unit'])->toBe('sztuka')
        ->and($result['catalogue_number'])->toBe('RD-M1')
        ->and($result['description_plain'])->toContain('certyfikowany wyrób medyczny klasy I')
        ->and($result['description_plain'])->not->toContain('MedSurg Pro 110.00 zł')
        ->and($result['variant_candidates'])->toHaveCount(1)
        ->and($result['variant_candidates'][0]['label'])
        ->toBe('Rozmiar: MS (39,0 – 41,0) do 26 cm');
});

it('parses Iconic comma-decimal prices with grouping', function (): void {
    $html = <<<'HTML'
        <html lang="pl">
            <head>
                <link rel="canonical" href="https://sklep.iconic.pl/produkty/test-price.html">
            </head>
            <body>
                <main>
                    <h1>Test Price</h1>
                    <div>1 234,56 zł</div>
                    <div>Dostępny: Dostępny</div>
                    <div>Numer katalogowy: PRICE-1</div>
                    <h2>Opis produktu</h2>
                    <div class="product-description"><p>Opis testowego produktu.</p></div>
                </main>
            </body>
        </html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/test-price.html',
    );

    expect($result['price_gross_amount'])->toBe(1234.56);
});

it('preserves an Iconic on-order service without inventing a retail price', function (): void {
    $html = <<<'HTML'
        <html lang="pl">
            <head>
                <link rel="canonical" href="https://sklep.iconic.pl/produkty/regeneracja-narzedzi.html">
            </head>
            <body>
                <main>
                    <h1>Regeneracja narzędzi</h1>
                    <div>Produkt na zamówienie</div>
                    <div>Czas wysyłki: 24 h - 2 dni</div>
                    <div>Jednostka miary: szt.</div>
                    <div>Numer katalogowy: REG</div>

                    <h2>Opis produktu</h2>
                    <div class="product-description">
                        <p>Profesjonalna regeneracja narzędzi podologicznych.</p>
                        <p>Cennik usługi zależy od rodzaju narzędzia.</p>
                    </div>
                </main>
            </body>
        </html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/regeneracja-narzedzi.html',
    );

    expect($result['name'])->toBe('Regeneracja narzędzi')
        ->and($result['price_gross_amount'])->toBeNull()
        ->and($result['availability'])->toBe('on_order')
        ->and($result['availability_label'])->toBe('Produkt na zamówienie')
        ->and($result['is_on_order'])->toBeTrue()
        ->and($result['catalogue_number'])->toBe('REG')
        ->and($result['variant_candidates'])->toBe([])
        ->and($result['warnings'])->toContain(
            'Product is marked as on-order and has no authoritative retail price on the product page.',
        );
});

it('crawls Iconic discovery data and keeps missing-price products for review', function (): void {
    $pricedHtml = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/tool.html">
        </head><body>
            <h1>Tool</h1>
            <div>35.00 zł</div>
            <div>Dostępny: Dostępny</div>
            <div>Numer katalogowy: T-1</div>
            <h2>Opis produktu</h2>
            <div class="product-description"><p>Opis produktu Tool.</p></div>
        </body></html>
    HTML;

    $onOrderHtml = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/service.html">
        </head><body>
            <h1>Service</h1>
            <div>Produkt na zamówienie</div>
            <div>Numer katalogowy: S-1</div>
            <h2>Opis produktu</h2>
            <div class="product-description"><p>Opis usługi.</p></div>
        </body></html>
    HTML;

    Http::fake([
        'https://sklep.iconic.pl/produkty/tool.html' => Http::response($pricedHtml),
        'https://sklep.iconic.pl/produkty/service.html' => Http::response($onOrderHtml),
    ]);

    $result = app(IconicProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'iconic',
            'product_urls' => [
                'https://sklep.iconic.pl/produkty/tool.html',
                'https://sklep.iconic.pl/produkty/service.html',
            ],
            'products' => [
                ['url' => 'https://sklep.iconic.pl/produkty/tool.html'],
                ['url' => 'https://sklep.iconic.pl/produkty/service.html'],
            ],
        ]);

    expect($result['source'])->toBe('iconic')
        ->and($result['product_count'])->toBe(2)
        ->and($result['skipped_failed_products'])->toBe([])
        ->and($result['failed_urls'])->toBe([])
        ->and($result['products'][0]['price_gross_amount'])->toBe(35.0)
        ->and($result['products'][1]['price_gross_amount'])->toBeNull()
        ->and($result['products'][1]['is_on_order'])->toBeTrue()
        ->and($result['warnings'])->toContain([
            'url' => 'https://sklep.iconic.pl/produkty/service.html',
            'warning' => 'Product is marked as on-order and has no authoritative retail price on the product page.',
        ]);
});

it('saves Iconic crawl JSON as a non-empty valid artifact when source text contains invalid utf-8', function (): void {
    Storage::fake('local');

    $html = "<html><head>"
        ."<link rel=\"canonical\" href=\"https://sklep.iconic.pl/produkty/utf8-test.html\">"
        ."</head><body>"
        ."<h2>UTF-8 Test</h2>"
        ."<div>49.00 zł</div>"
        ."<div>Dostępny: Dostępny</div>"
        ."<div>Numer katalogowy: UTF-1</div>"
        ."<h1>Opis testowego produktu</h1>"
        ."<p>Niepoprawny bajt: \xC3\x28</p>"
        ."</body></html>";

    Http::fake([
        'https://sklep.iconic.pl/produkty/utf8-test.html' => Http::response($html),
    ]);

    $exit = Artisan::call('iconic:crawl-product-data', [
        '--url' => ['https://sklep.iconic.pl/produkty/utf8-test.html'],
        '--request-delay-ms' => '0',
        '--save' => 'scrapers/iconic/utf8-test.json',
    ]);

    expect($exit)->toBe(0)
        ->and(Storage::disk('local')->exists('scrapers/iconic/utf8-test.json'))->toBeTrue()
        ->and(Storage::disk('local')->size('scrapers/iconic/utf8-test.json'))->toBeGreaterThan(0);

    $decoded = json_decode(
        Storage::disk('local')->get('scrapers/iconic/utf8-test.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($decoded['product_count'])->toBe(1)
        ->and($decoded['products'][0]['name'])->toBe('UTF-8 Test');
});

it('does not treat Iconic action buttons as a catalogue number and maps limited availability', function (): void {
    $html = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/blank-sku.html">
        </head><body>
            <h2>Blank SKU product</h2>
            <div>55.00 zł</div>
            <div>Dostępny: Ograniczony</div>
            <div>Czas wysyłki: 24 h - 2 dni</div>
            <div>Jednostka miary: sztuka</div>
            <div>Numer katalogowy:</div>
            <button>DODAJ DO KOSZYKA</button>
            <div>Opis produktu</div>
            <h1>Opis Blank SKU</h1>
            <p>Pełny opis produktu testowego.</p>
        </body></html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/blank-sku.html',
    );

    expect($result['catalogue_number'])->toBeNull()
        ->and($result['availability'])->toBe('in_stock')
        ->and($result['availability_label'])->toBe('Ograniczony')
        ->and($result['warnings'])->toContain('Catalogue number not found.');
});

it('maps exhausted Iconic availability to out of stock', function (): void {
    $html = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/exhausted.html">
        </head><body>
            <h2>Exhausted product</h2>
            <div>55.00 zł</div>
            <div>Dostępny: Wyczerpany</div>
            <div>Numer katalogowy: OUT-1</div>
            <div>Opis produktu</div>
            <h1>Opis Exhausted product</h1>
            <p>Produkt testowy bez stanu magazynowego.</p>
        </body></html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/exhausted.html',
    );

    expect($result['availability'])->toBe('out_of_stock')
        ->and($result['availability_label'])->toBe('Wyczerpany');
});

it('keeps only images belonging to the current Iconic structured gallery', function (): void {
    $html = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/gallery-scope.html">
        </head><body>
            <h2>Gallery scope product</h2>

            <div>
                Array ( [id] => 1 [galleryId] => 10 [productId] => 147 [src] => /_images/produkty/Test/product-1.jpg [srcMin] => /.miniatury/100/147/product-1.jpg [srcBig] => /_images/produkty/Test/product-1.jpg [alt] => Product one ) 1
                Array ( [id] => 2 [galleryId] => 11 [productId] => 147 [src] => /_images/produkty/Test/product-2.jpg [srcMin] => /.miniatury/100/147/product-2.jpg [srcBig] => /_images/produkty/Test/product-2.jpg [alt] => Product two ) 1
            </div>

            <div>55.00 zł</div>
            <div>Dostępny: Dostępny</div>
            <div>Numer katalogowy: GALLERY-1</div>

            <h2>Opis produktu</h2>
            <div class="product-description">
                <p>Opis produktu.</p>
                <img src="/_images/produkty/Test/measurement-guide.jpg" alt="Measurement guide">
            </div>

            <h2>Powiązane produkty</h2>
            <div>
                Array ( [id] => 3 [galleryId] => 12 [productId] => 999 [src] => /_images/produkty/Test/related.jpg [srcMin] => /.miniatury/100/999/related.jpg [srcBig] => /_images/produkty/Test/related.jpg [alt] => Related product ) 1
            </div>
            <img src="/_images/produkty/Test/related-fallback.jpg" alt="Related fallback">
        </body></html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/gallery-scope.html',
    );

    expect($result['images'])->toBe([
        [
            'url' => 'https://sklep.iconic.pl/_images/produkty/Test/product-1.jpg',
            'alt' => 'Product one',
            'fallback_url' => 'https://sklep.iconic.pl/.miniatury/100/147/product-1.jpg',
        ],
        [
            'url' => 'https://sklep.iconic.pl/_images/produkty/Test/product-2.jpg',
            'alt' => 'Product two',
            'fallback_url' => 'https://sklep.iconic.pl/.miniatury/100/147/product-2.jpg',
        ],
    ]);
});

it('deduplicates Iconic gallery rows by src when srcBig is malformed', function (): void {
    $html = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/gallery-duplicate.html">
        </head><body>
            <h2>Gallery duplicate product</h2>
            <div>
                Array ( [id] => 1 [galleryId] => 10 [productId] => 526 [src] => /_images/produkty/Test/product.jpg [srcMin] => /.miniatury/500/526/product_mini.jpg [srcBig] => /_images/produkty/Test/product.jpg [alt] => Product ) 1
                Array ( [id] => 2 [galleryId] => 11 [productId] => 526 [src] => /_images/produkty/Test/product.jpg [srcMin] => /.miniatury/500/526/product_mini.jpg [srcBig] => /_images/_images/produkty/Test/product.jpg [alt] => Product ) 1
            </div>
            <div>55.00 zł</div>
            <div>Dostępny: Dostępny</div>
            <div>Numer katalogowy: GALLERY-2</div>
            <h2>Opis produktu</h2>
            <div class="product-description"><p>Opis produktu.</p></div>
        </body></html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/gallery-duplicate.html',
    );

    expect($result['images'])->toBe([
        [
            'url' => 'https://sklep.iconic.pl/_images/produkty/Test/product.jpg',
            'alt' => 'Product',
            'fallback_url' => 'https://sklep.iconic.pl/.miniatury/500/526/product_mini.jpg',
        ],
    ]);
});

it('recovers Iconic full-size gallery paths embedded outside normal image links', function (): void {
    $html = <<<'HTML'
        <html><head>
            <link rel="canonical" href="https://sklep.iconic.pl/produkty/embedded-image.html">
        </head><body>
            <h2>Embedded image product</h2>
            <div>
                Array ( [src] => /_images/produkty/Test/full.jpg
                [srcMin] => /.miniatury/100/1/full_mini.jpg
                [srcBig] => /_images/produkty/Test/full.jpg )
            </div>
            <img src="/.miniatury/100/1/full_mini.jpg" alt="mini">
            <div>49.00 zł</div>
            <div>Dostępny: Dostępny</div>
            <div>Numer katalogowy: IMG-1</div>
            <div>Opis produktu</div>
            <h1>Opis obrazu</h1>
            <p>Opis produktu z osadzoną ścieżką galerii.</p>
        </body></html>
    HTML;

    $result = app(IconicProductScraper::class)->extract(
        $html,
        'https://sklep.iconic.pl/produkty/embedded-image.html',
    );

    expect($result['images'])->toContain([
        'url' => 'https://sklep.iconic.pl/_images/produkty/Test/full.jpg',
        'alt' => '',
    ]);
});

it('records failed Iconic product requests without throwing', function (): void {
    Http::fake([
        'https://sklep.iconic.pl/produkty/missing.html' => Http::response('', 404),
    ]);

    $result = app(IconicProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://sklep.iconic.pl/produkty/missing.html');

    expect($result['name'])->toBe('')
        ->and($result['failed_urls'])->toBe([
            'https://sklep.iconic.pl/produkty/missing.html' => 'HTTP 404',
        ]);
});
