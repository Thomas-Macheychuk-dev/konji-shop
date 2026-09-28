<?php

declare(strict_types=1);

use App\Services\DrSapporo\DrSapporoProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts normalized Dr Sapporo product data and size variants', function (): void {
    $html = <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Aparat na haluksy Bunito Duo</title>
                <meta name="description" content="Aparat ortopedyczny na haluksy.">
                <meta property="og:image" content="https://drsapporo.com/media/bunito-duo.webp">
                <link rel="canonical" href="https://drsapporo.com/aparat-na-haluksy-bunito-duo">
            </head>
            <body>
                <nav class="breadcrumbs">
                    <a href="/">Strona główna</a>
                    <a href="/produkty">Produkty</a>
                    <a href="/produkty#haluksy">Aparaty na haluksy</a>
                </nav>
                <main>
                    <h1>Aparat na haluksy Bunito Duo</h1>
                    <div class="product-price">149,00 zł</div>
                    <p>Termin realizacji: 3 dni robocze</p>
                    <p>Wyrób medyczny przeznaczony do korekcji palucha koślawego.</p>
                    <p>Szerokość: 32 cm</p>
                    <p>Długość: 54 cm</p>
                    <p>Wysokość: 11/13 cm</p>
                    <p>Rozmiar poduszki: uniwersalny (jeden rozmiar)</p>
                    <p>SKU: BUN-DUO</p>
                    <p>EAN: 5901234567890</p>
                    <div class="product-description" itemprop="description">
                        <p>Orteza pomaga ustabilizować paluch i może być stosowana w dzień oraz w nocy.</p>
                    </div>
                    <div class="gallery">
                        <a href="/media/bunito-duo-large.jpg"><img src="/media/bunito-duo-thumb.jpg" alt="Bunito Duo"></a>
                    </div>
                    <label for="size">Rozmiar:</label>
                    <select id="size" name="size">
                        <option value="">Wybierz rozmiar</option>
                        <option value="s-m">S/M</option>
                        <option value="l-xl">L/XL</option>
                    </select>
                    <table>
                        <tr><th>Materiał</th><td>Elastyczna tkanina</td></tr>
                    </table>
                </main>
            </body>
        </html>
    HTML;

    $result = app(DrSapporoProductScraper::class)->extract(
        $html,
        'https://drsapporo.com/aparat-na-haluksy-bunito-duo',
        [
            'name' => 'Aparat na haluksy Bunito Duo',
            'category_name' => 'Aparaty na haluksy',
            'brand_name' => 'Dr Sapporo',
        ],
    );

    expect($result)->toMatchArray([
        'source' => 'drsapporo',
        'canonical_url' => 'https://drsapporo.com/aparat-na-haluksy-bunito-duo',
        'external_product_id' => 'aparat-na-haluksy-bunito-duo',
        'name' => 'Aparat na haluksy Bunito Duo',
        'brand' => [
            'name' => 'Dr Sapporo',
            'slug' => 'dr-sapporo',
        ],
        'category' => 'Aparaty na haluksy',
        'price_gross_amount' => 149.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'shipping_time' => '3 dni robocze',
        'sku' => 'BUN-DUO',
        'ean' => '5901234567890',
        'is_medical_device' => true,
        'failed_urls' => [],
    ])
        ->and($result['seo_description'])->toBe('Aparat ortopedyczny na haluksy.')
        ->and($result['description_html'])->toContain('Orteza pomaga ustabilizować paluch')
        ->and($result['images'])->toContain([
            'url' => 'https://drsapporo.com/media/bunito-duo-large.jpg',
            'alt' => 'Bunito Duo',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'material',
            'label' => 'Materiał',
            'value' => 'Elastyczna tkanina',
            'slug' => 'elastyczna-tkanina',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'szerokosc',
            'label' => 'Szerokość',
            'value' => '32 cm',
            'slug' => '32-cm',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'dlugosc',
            'label' => 'Długość',
            'value' => '54 cm',
            'slug' => '54-cm',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'wysokosc',
            'label' => 'Wysokość',
            'value' => '11/13 cm',
            'slug' => '11-13-cm',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'rozmiar',
            'label' => 'Rozmiar',
            'value' => 'uniwersalny (jeden rozmiar)',
            'slug' => 'uniwersalny-jeden-rozmiar',
        ])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => 's-m',
            'label' => 'Rozmiar: S/M',
            'price_gross_amount' => 149.0,
            'currency' => 'PLN',
            'attributes' => [
                ['label' => 'Rozmiar', 'value' => 'S/M'],
            ],
        ]);
});

it('extracts a first width measurement concatenated to its section heading', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <link rel="canonical" href="https://drsapporo.com/poduszka-ortopedyczna-rock">
            </head>
            <body>
                <main>
                    <h1>Rock Poduszka ortopedyczna</h1>
                    <div class="product-price">319,00 zł</div>
                    <p>Termin realizacji: 1 dzień roboczy</p>
                    <div>Wymiary poduszkiszerokość: 62 centymetry długość: 38 centymetrów wysokość: 3/11 centymetrów</div>
                </main>
            </body>
        </html>
    HTML;

    $result = app(DrSapporoProductScraper::class)->extract(
        $html,
        'https://drsapporo.com/poduszka-ortopedyczna-rock',
        [
            'category_name' => 'Poduszki ortopedyczne Dr Sapporo',
            'brand_name' => 'Dr Sapporo',
        ],
    );

    expect($result['attributes'])->toContain([
        'code' => 'szerokosc',
        'label' => 'Szerokość',
        'value' => '62 centymetry',
        'slug' => '62-centymetry',
    ]);
});

it('normalizes concatenated Dr Sapporo product names', function (): void {
    $html = <<<'HTML'
        <html>
            <head>
                <link rel="canonical" href="https://drsapporo.com/poszewka-na-poduszke-shell">
            </head>
            <body>
                <main>
                    <h1>ShellPoszewka na poduszkę ortopedyczną</h1>
                    <div class="product-price">79,00 zł</div>
                    <p>Termin realizacji: 1 dzień roboczy</p>
                </main>
            </body>
        </html>
    HTML;

    $result = app(DrSapporoProductScraper::class)->extract(
        $html,
        'https://drsapporo.com/poszewka-na-poduszke-shell',
        [
            'category_name' => 'Poszewki na poduszki Dr Sapporo',
            'brand_name' => 'Dr Sapporo',
        ],
    );

    expect($result['name'])->toBe('Shell Poszewka na poduszkę ortopedyczną');
});

it('records failed Dr Sapporo product requests without throwing', function (): void {
    Http::fake([
        'https://drsapporo.com/poduszka-ortopedyczna-missing' => Http::response('', 404),
    ]);

    $result = app(DrSapporoProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://drsapporo.com/poduszka-ortopedyczna-missing');

    expect($result['name'])->toBe('')
        ->and($result['failed_urls'])->toBe([
            'https://drsapporo.com/poduszka-ortopedyczna-missing' => 'HTTP 404',
        ]);
});
