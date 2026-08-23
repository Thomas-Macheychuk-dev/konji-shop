<?php

declare(strict_types=1);

use App\Services\Zamst\ZamstProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Zamst products from shop sections and category pages without pagination loops', function (): void {
    $shop = 'https://zamst.com.pl/sklep/';
    $pageTwo = 'https://zamst.com.pl/sklep/page/2/';
    $category = 'https://zamst.com.pl/kategoria-produktu/ortezy-dla-siatkarzy/';

    Http::fake([
        $shop => Http::response(<<<HTML
            <html><head><link rel="next" href="{$pageTwo}"></head><body>
                <ul class="category-list">
                    <li>
                        <h2><a href="{$category}">Ortezy dla siatkarzy</a></h2>
                        <a href="/produkt/stabilizator-kolana-jk-2/" class="uk-link-reset uk-card">
                            <img src="/wp-content/uploads/2020/08/01-jk-2.webp" alt="JK-2">
                            <h3><span>Stabilizator rzepki Zamst JK-2</span></h3>
                        </a>
                    </li>
                </ul>
            </body></html>
            HTML),
        $pageTwo => Http::response(<<<HTML
            <html><head><link rel="next" href="{$pageTwo}"></head><body>
                <a href="/produkt/stabilizator-kolana-zk-motion/" class="uk-card"><h3>ZK-MOTION</h3></a>
                <a href="/produkt/stabilizator-kolana-jk-2/" class="uk-card"><h3>Duplicate JK-2</h3></a>
            </body></html>
            HTML),
        $category => Http::response(<<<'HTML'
            <html><body>
                <div class="category-list">
                    <h1>Ortezy dla siatkarzy</h1>
                    <a href="/produkt/stabilizator-kolana-jk-2/" class="uk-card"><h3>JK-2</h3></a>
                    <a href="/produkt/stabilizator-nadgarstka-wrist-band/" class="uk-card"><h3>Wrist Band</h3></a>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ZamstProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([
            'source' => 'zamst',
            'categories' => [[
                'external_category_id' => 'ortezy-dla-siatkarzy',
                'name' => 'Ortezy dla siatkarzy',
                'url' => $category,
                'path' => ['Ortezy dla siatkarzy'],
            ]],
        ]);

    expect($result['failed_urls'])->toBe([])
        ->and($result['catalogue_pages'])->toBe([$shop, $pageTwo])
        ->and($result['product_urls'])->toHaveCount(3)
        ->and($result['product_urls'])->toContain(
            'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/',
            'https://zamst.com.pl/produkt/stabilizator-kolana-zk-motion/',
            'https://zamst.com.pl/produkt/stabilizator-nadgarstka-wrist-band/',
        );

    $jk2 = collect($result['products'])
        ->firstWhere('url', 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/');

    expect($jk2['name'])->toBe('Stabilizator rzepki Zamst JK-2')
        ->and($jk2['listing_image_url'])->toBe('https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp')
        ->and($jk2['category_paths'])->toContain(['Ortezy dla siatkarzy']);
});
