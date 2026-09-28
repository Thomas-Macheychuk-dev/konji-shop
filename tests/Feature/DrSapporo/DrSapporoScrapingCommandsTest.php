<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('runs the Dr Sapporo discovery and product-data commands as a read-only JSON pipeline', function (): void {
    Storage::fake('local');

    Http::fake([
        'https://drsapporo.com/produkty' => Http::response(<<<'HTML'
            <html><body><main>
                <h2>Poduszki ortopedyczne Dr Sapporo</h2>
                <article>
                    <a href="/poduszka-ortopedyczna-rock">Poduszka ortopedyczna Rock</a>
                    <span>249,00 zł</span>
                </article>
            </main></body></html>
        HTML),
        'https://drsapporo.com/poduszka-ortopedyczna-rock' => Http::response(<<<'HTML'
            <html>
                <head>
                    <link rel="canonical" href="https://drsapporo.com/poduszka-ortopedyczna-rock">
                    <meta name="description" content="Poduszka ortopedyczna do snu.">
                </head>
                <body>
                    <main>
                        <h1>Poduszka ortopedyczna Rock</h1>
                        <div class="product-price">249,00 zł</div>
                        <p>Termin realizacji: 2 dni robocze</p>
                        <div class="product-description"><p>Opis produktu Rock.</p></div>
                    </main>
                </body>
            </html>
        HTML),
    ]);

    expect(Artisan::call('drsapporo:product-links', [
        '--save' => 'scrapers/drsapporo/product-links.json',
        '--request-delay-ms' => '0',
        '--no-progress' => true,
    ]))->toBe(0);

    Storage::disk('local')->assertExists('scrapers/drsapporo/product-links.json');

    expect(Artisan::call('drsapporo:crawl-product-data', [
        '--from' => 'scrapers/drsapporo/product-links.json',
        '--save' => 'scrapers/drsapporo/products.json',
        '--request-delay-ms' => '0',
        '--no-progress' => true,
    ]))->toBe(0);

    Storage::disk('local')->assertExists('scrapers/drsapporo/products.json');

    $dataset = json_decode(Storage::disk('local')->get('scrapers/drsapporo/products.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($dataset['source'])->toBe('drsapporo')
        ->and($dataset['product_count'])->toBe(1)
        ->and($dataset['products'][0]['name'])->toBe('Poduszka ortopedyczna Rock')
        ->and($dataset['products'][0]['price_gross_amount'])->toBe(249.0);
});
