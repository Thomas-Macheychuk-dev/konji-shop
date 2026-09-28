<?php

declare(strict_types=1);

use App\Services\DrSapporo\DrSapporoProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Dr Sapporo products with catalogue category and price context', function (): void {
    $html = <<<'HTML'
        <html><body>
            <main>
                <h2>Poduszki ortopedyczne Dr Sapporo</h2>
                <article>
                    <a href="/poduszka-ortopedyczna-rock"><img alt="Poduszka ortopedyczna Rock"></a>
                    <h3><a href="/poduszka-ortopedyczna-rock">Poduszka ortopedyczna Rock</a></h3>
                    <div>249,00 zł</div>
                </article>

                <h2>Poduszki ortopedyczne ONSEN</h2>
                <article>
                    <a href="https://drsapporo.com/poduszka-ortopedyczna-onsen"><span>Poduszka ortopedyczna ONSEN</span></a>
                    <div>299,00 zł</div>
                </article>

                <h2>Aparaty na haluksy</h2>
                <article>
                    <a href="/aparat-na-haluksy-bunito-duo">Aparat na haluksy Bunito Duo</a>
                    <div>149,00 zł</div>
                </article>

                <a href="/blog">Blog</a>
            </main>
        </body></html>
    HTML;

    Http::fake([
        'https://drsapporo.com/produkty' => Http::response($html),
    ]);

    $result = app(DrSapporoProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('drsapporo')
        ->and($result['product_count'])->toBe(3)
        ->and($result['product_urls'])->toBe([
            'https://drsapporo.com/poduszka-ortopedyczna-rock',
            'https://drsapporo.com/poduszka-ortopedyczna-onsen',
            'https://drsapporo.com/aparat-na-haluksy-bunito-duo',
        ])
        ->and($result['products'][0])->toMatchArray([
            'name' => 'Poduszka ortopedyczna Rock',
            'category_name' => 'Poduszki ortopedyczne Dr Sapporo',
            'brand_name' => 'Dr Sapporo',
            'price_gross_amount' => 249.0,
            'currency' => 'PLN',
        ])
        ->and($result['products'][1]['brand_name'])->toBe('ONSEN')
        ->and($result['failed_urls'])->toBe([]);
});

it('rejects non-product and external URLs during Dr Sapporo discovery', function (): void {
    $scraper = app(DrSapporoProductUrlScraper::class);

    expect($scraper->normalizeProductUrl('https://drsapporo.com/produkty'))->toBeNull()
        ->and($scraper->normalizeProductUrl('https://drsapporo.com/blog'))->toBeNull()
        ->and($scraper->normalizeProductUrl('https://example.com/poduszka-test'))->toBeNull()
        ->and($scraper->normalizeProductUrl('/poszewka-na-poduszke-test', 'https://drsapporo.com/produkty'))
        ->toBe('https://drsapporo.com/poszewka-na-poduszke-test');
});
