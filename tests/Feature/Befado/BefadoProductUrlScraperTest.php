<?php

declare(strict_types=1);

use App\Services\Befado\BefadoProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Befado product links and manufacturer pagination', function (): void {
    Http::fake([
        'https://befado.pl/--befado' => Http::response(<<<'HTML'
            <html><body>
                <nav><a href="/pl/p/Menu-product/99999">Menu product</a></nav>
                <div id="box_mainproducts">
                    <div class="product">
                        <a class="prodname" href="/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664">
                            BUTY DZIECIĘCE HONEY BEFADO
                        </a>
                        <span class="price">49,90 zł</span>
                    </div>
                </div>
                <ul class="paginator"><li><a href="/--befado/2">2</a></li></ul>
            </body></html>
        HTML),
        'https://befado.pl/--befado/2' => Http::response(<<<'HTML'
            <html><body>
                <div id="box_mainproducts">
                    <div class="product">
                        <a class="prodname" href="/pl/p/BUTY-DZIECIECE-SPEEDY-BEFADO/9077">
                            BUTY DZIECIĘCE SPEEDY BEFADO
                        </a>
                        <span class="price">39,90 zł</span>
                    </div>
                </div>
            </body></html>
        HTML),
    ]);

    $result = app(BefadoProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('befado')
        ->and($result['product_count'])->toBe(2)
        ->and($result['product_urls'])->toBe([
            'https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664',
            'https://befado.pl/pl/p/BUTY-DZIECIECE-SPEEDY-BEFADO/9077',
        ])
        ->and($result['visited_urls'])->toBe([
            'https://befado.pl/--befado',
            'https://befado.pl/--befado/2',
        ])
        ->and($result['failed_urls'])->toBe([]);
});

it('accepts only Befado Shoper product urls', function (): void {
    $scraper = app(BefadoProductUrlScraper::class);

    expect($scraper->normalizeProductUrl('/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664', 'https://befado.pl/--befado'))
        ->toBe('https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664')
        ->and($scraper->normalizeProductUrl('/pl/c/Dzieci/12', 'https://befado.pl/--befado'))
        ->toBeNull()
        ->and($scraper->normalizeProductUrl('https://example.com/pl/p/Fake/1'))
        ->toBeNull();
});
