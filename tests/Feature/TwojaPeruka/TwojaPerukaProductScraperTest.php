<?php

use App\Services\TwojaPeruka\TwojaPerukaProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('scrapes one TwojaPeruka product page into normalized product data', function (): void {
    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductScraper::class)->scrape('https://twojaperuka.pl/iris');

    expect($result['source'])->toBe('twojaperuka')
        ->and($result['source_url'])->toBe('https://twojaperuka.pl/iris')
        ->and($result['canonical_url'])->toBe('https://twojaperuka.pl/iris')
        ->and($result['external_product_id'])->toBe('TP-IRIS')
        ->and($result['slug'])->toBe('iris')
        ->and($result['name'])->toBe('Peruka syntetyczna, kolor blond, krótkie włosy - IRIS')
        ->and($result['brand'])->toBe('NAH')
        ->and($result['categories'])->toBe([
            'Peruki',
            'Peruki syntetyczne',
            'Peruki Flower Collection',
        ])
        ->and($result['category'])->toBe('Peruki Flower Collection')
        ->and($result['price_gross_amount'])->toBe(540.00)
        ->and($result['currency'])->toBe('PLN')
        ->and($result['availability'])->toBe('low_stock')
        ->and($result['availability_label'])->toBe('na wyczerpaniu')
        ->and($result['sku'])->toBe('TP-IRIS')
        ->and($result['ean'])->toBe('5900000000001')
        ->and($result['is_medical_device'])->toBeTrue()
        ->and($result['description_html'])->toContain('Peruka syntetyczna Iris')
        ->and($result['short_description'])->toBe('Peruka Iris warianty kolorystyczne do wyboru')
        ->and($result['images'])->toHaveCount(3)
        ->and($result['images'][0]['url'])->toBe('https://twojaperuka.pl/userdata/public/gfx/products/iris-main.webp')
        ->and($result['variant_options'])->toBe([
            [
                'name' => 'kolory flower collection',
                'values' => ['sunset brown', 'milk chocolate', 'copper brown'],
            ],
        ])
        ->and($result['warnings'])->toBe([]);
});

it('prints one TwojaPeruka product as JSON', function (): void {
    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('twojaperuka:product', [
        'url' => 'https://twojaperuka.pl/iris',
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('twojaperuka')
        ->and($decoded['name'])->toBe('Peruka syntetyczna, kolor blond, krótkie włosy - IRIS')
        ->and($decoded['price_gross_amount'])->toBe(540.00)
        ->and($decoded['variant_options'][0]['values'])->toContain('milk chocolate');
});

it('saves one TwojaPeruka product JSON under storage app', function (): void {
    Storage::disk('local')->delete('scrapers/twojaperuka/products/iris.json');

    Http::fake([
        'https://twojaperuka.pl/iris' => Http::response(twojaPerukaProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('twojaperuka:product', [
        'url' => 'https://twojaperuka.pl/iris',
        '--save' => 'scrapers/twojaperuka/products/iris.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $path = storage_path('app/scrapers/twojaperuka/products/iris.json');

    expect(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['external_product_id'])->toBe('TP-IRIS')
        ->and($decoded['images'])->toHaveCount(3);
});

it('records failed TwojaPeruka product page requests', function (): void {
    Http::fake([
        'https://twojaperuka.pl/missing-product' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaProductScraper::class)->scrape('https://twojaperuka.pl/missing-product');

    expect($result['name'])->toBe('')
        ->and($result['failed_urls'])->toBe([
            'https://twojaperuka.pl/missing-product' => 'HTTP 500',
        ])
        ->and($result['warnings'])->toContain('Unable to fetch TwojaPeruka product page.');
});

function twojaPerukaProductPageFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>Peruka syntetyczna, kolor blond, krótkie włosy - IRIS - TwojaPeruka.pl</title>
                <meta name="description" content="Peruka Iris to naturalnie wyglądający model syntetyczny.">
                <meta property="og:title" content="Peruka syntetyczna, kolor blond, krótkie włosy - IRIS">
                <meta property="product:price:amount" content="540.00">
                <meta property="product:price:currency" content="PLN">
                <link rel="canonical" href="https://twojaperuka.pl/iris">
            </head>
            <body>
                <main>
                    <nav class="breadcrumbs">
                        <ol class="breadcrumbs__list">
                            <li><a href="https://twojaperuka.pl">twojaperuka.pl</a></li>
                            <li><a href="/peruki">Peruki</a></li>
                            <li><a href="/pl/c/Peruki-syntetyczne/48">Peruki syntetyczne</a></li>
                            <li><a href="/flower">Peruki Flower Collection</a></li>
                            <li><span>Peruka syntetyczna, kolor blond, krótkie włosy - IRIS</span></li>
                        </ol>
                    </nav>

                    <div class="product-gallery">
                        <picture>
                            <source srcset="/userdata/public/gfx/products/iris-main.webp 1x, /userdata/public/gfx/products/iris-main-large.webp 2x">
                            <img src="/userdata/public/gfx/products/iris-main.webp" alt="Peruka Iris">
                        </picture>
                        <img data-src="/userdata/public/gfx/products/iris-second.webp" alt="IRIS drugi widok">
                        <img src="/userdata/public/storefront/images/logo.svg" alt="Logo">
                    </div>

                    <article class="product-card" data-product-id="TP-IRIS" data-sku="TP-IRIS">
                        <h1>Peruka syntetyczna, kolor blond, krótkie włosy - IRIS</h1>
                        <div class="producer">Producent: NAH</div>
                        <div class="product-short-description">Peruka Iris warianty kolorystyczne do wyboru</div>
                        <div class="price__value">Cena 540,00 zł</div>
                        <div class="product-availability">Dostępność: na wyczerpaniu</div>

                        <label for="color-select">kolory flower collection</label>
                        <select id="color-select" name="kolor">
                            <option value="">Wybierz</option>
                            <option value="sunset">sunset brown</option>
                            <option value="milk">milk chocolate</option>
                            <option value="copper">copper brown</option>
                        </select>
                    </article>

                    <section id="description" class="product-description">
                        <h2>Peruka syntetyczna Iris – naturalność i lekkość ułożenia</h2>
                        <p>Peruka Iris to model wykonany z włókna syntetycznego.</p>
                        <p>EAN: 5900000000001</p>
                        <p>Peruka jest wyrobem medycznym. Używaj zgodnie z instrukcją lub etykietą.</p>
                    </section>
                </main>
            </body>
        </html>
    HTML;
}
