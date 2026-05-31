<?php

use App\Services\Eldan\EldanProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers product urls through Eldan category links and pagination', function (): void {
    Http::fake([
        'https://eldan.pl/odziez-medyczna-damska' => Http::response(<<<'HTML'
            <html><body>
                <a href="/44774-bluzy-damskie">Bluzy damskie</a>
            </body></html>
            HTML),
        'https://eldan.pl/44774-bluzy-damskie' => Http::response(<<<'HTML'
            <html><body>
                <script>
                    window.__catalog = {"items":[{"url":"https:\\/\\/eldan.pl\\/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640"}]};
                </script>
                <a href="/44774-bluzy-damskie?page=2">2</a>
            </body></html>
            HTML),
        'https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640' => Http::response(eldanProductHtml(179, 'bluza damska medyczna taliowana krótki rękaw RÓŻA')),
        'https://eldan.pl/44774-bluzy-damskie?page=2' => Http::response(<<<'HTML'
            <html><body>
                <a href="/159-bluza-medyczna-damska-z-krotkim-rekawem-agata">AGATA</a>
            </body></html>
            HTML),
        'https://eldan.pl/159-bluza-medyczna-damska-z-krotkim-rekawem-agata' => Http::response(eldanProductHtml(159, 'bluza medyczna damska z krótkim rękawem AGATA')),
        '*' => Http::response('', 404),
    ]);

    $result = app(EldanProductUrlScraper::class)->scrape(
        startUrls: ['https://eldan.pl/odziez-medyczna-damska'],
        maxDepth: 4,
        maxPages: 20,
    );

    expect($result['product_urls'])->toBe([
        'https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza',
        'https://eldan.pl/159-bluza-medyczna-damska-z-krotkim-rekawem-agata',
    ]);
});


it('discovers Eldan product urls from XML sitemaps before crawling category pages', function (): void {
    Http::fake([
        'https://eldan.pl/sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sitemapindex>
                <sitemap><loc>https://eldan.pl/products-sitemap.xml</loc></sitemap>
            </sitemapindex>
            XML, 200, ['Content-Type' => 'application/xml']),
        'https://eldan.pl/products-sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset>
                <url><loc>https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640</loc></url>
                <url><loc>https://eldan.pl/kd-med-57-wygodne-skorzane-buty-medyczne-kd-med-57?v=44450</loc></url>
                <url><loc>https://eldan.pl/odziez-medyczna-damska</loc></url>
            </urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
        'https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640' => Http::response(eldanProductHtml(179, 'bluza damska medyczna taliowana krótki rękaw RÓŻA')),
        'https://eldan.pl/kd-med-57-wygodne-skorzane-buty-medyczne-kd-med-57?v=44450' => Http::response(eldanProductHtml(57, 'wygodne skórzane buty medyczne KD MED 57')),
        '*' => Http::response('', 404),
    ]);

    $result = app(EldanProductUrlScraper::class)->scrape(
        startUrls: ['https://eldan.pl/odziez-medyczna-damska'],
        maxDepth: 4,
        maxPages: 20,
    );

    expect($result['product_urls'])->toBe([
        'https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza',
        'https://eldan.pl/kd-med-57-wygodne-skorzane-buty-medyczne-kd-med-57',
    ]);
});


it('does not classify Eldan category slugs as product urls', function (): void {
    Http::fake([
        'https://eldan.pl/sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset>
                <url><loc>https://eldan.pl/polary-medyczne-damskie</loc></url>
                <url><loc>https://eldan.pl/buty-medyczne-damskie</loc></url>
                <url><loc>https://eldan.pl/buty-medyczne-meskie</loc></url>
            </urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
        '*' => Http::response('', 404),
    ]);

    $result = app(EldanProductUrlScraper::class)->scrape(
        startUrls: ['https://eldan.pl/odziez-medyczna-damska'],
        maxDepth: 1,
        maxPages: 20,
    );

    expect($result['product_urls'])->toBe([]);
});

it('discovers product urls exposed as url keys inside category payloads', function (): void {
    Http::fake([
        'https://eldan.pl/odziez-medyczna-damska' => Http::response(<<<'HTML'
            <html><body>
                <script>
                    window.__catalog = {
                        "items": [
                            {"url_key":"179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640"},
                            {"slug":"kd-med-57-wygodne-skorzane-buty-medyczne-kd-med-57?v=44450"},
                            {"url":"polary-medyczne-damskie"}
                        ]
                    };
                </script>
            </body></html>
            HTML),
        'https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640' => Http::response(eldanProductHtml(179, 'bluza damska medyczna taliowana krótki rękaw RÓŻA')),
        'https://eldan.pl/kd-med-57-wygodne-skorzane-buty-medyczne-kd-med-57?v=44450' => Http::response(eldanProductHtml(57, 'wygodne skórzane buty medyczne KD MED 57')),
        '*' => Http::response('', 404),
    ]);

    $result = app(EldanProductUrlScraper::class)->scrape(
        startUrls: ['https://eldan.pl/odziez-medyczna-damska'],
        maxDepth: 2,
        maxPages: 20,
    );

    expect($result['product_urls'])->toBe([
        'https://eldan.pl/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza',
        'https://eldan.pl/kd-med-57-wygodne-skorzane-buty-medyczne-kd-med-57',
    ]);
});


it('ignores raw postal codes dates and short ids when a sitemap endpoint returns html', function (): void {
    Http::fake([
        'https://eldan.pl/sitemap.xml' => Http::response(<<<'HTML'
            <html><body>
                <p>ELDAN Sp. z o.o. sp. k. ul. Wojska Polskiego 3, 39-300 Mielec</p>
                <script>
                    window.config = {
                        "created_at":"2025-02-05T12:59:48.000000Z",
                        "updated_at":"2026-02-19T13:51:06.000000Z",
                        "short_id":"018-8V0C5"
                    };
                </script>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    $result = app(EldanProductUrlScraper::class)->scrape(
        startUrls: ['https://eldan.pl/odziez-medyczna-damska'],
        maxDepth: 1,
        maxPages: 20,
        limit: 10,
    );

    expect($result['product_urls'])->toBe([]);
});

function eldanProductHtml(int $id, string $name): string
{
    $payload = htmlspecialchars(json_encode([
        'id' => $id,
        'name' => $name,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<html><body><v-product :product="'.$payload.'"></v-product></body></html>';
}

it('discovers Eldan product urls from the category products API', function (): void {
    Http::fake([
        'eldan.pl/odziez-medyczna-damska' => Http::response(
            '<v-category id="52" name="Odzież medyczna damska"></v-category>',
            200,
            ['Content-Type' => 'text/html']
        ),

        'eldan.pl/api/products?category_id=52*' => Http::response([
            'data' => [
                [
                    'id' => 15652,
                    'sku' => '705-configurable',
                    'name' => 'Bezrękawnik polarowy damski zapinany na zamek EMI',
                    'url_key' => '705-bezrekawnik-polarowy-damski-zapinany-na-zamek-emi',
                ],
                [
                    'id' => 44486,
                    'sku' => '1132-configurable',
                    'name' => 'Bluza Carmen dł. rękaw',
                    'url_key' => '1132-bluza-carmen-dl-rekaw',
                ],
            ],
            'links' => [
                'next' => null,
            ],
            'meta' => [
                'current_page' => 1,
                'last_page' => 1,
                'total' => 2,
            ],
        ], 200, ['Content-Type' => 'application/json']),

        '*' => Http::response('', 404),
    ]);

    $result = app(EldanProductUrlScraper::class)->scrape([
        'https://eldan.pl/odziez-medyczna-damska',
    ]);

    expect($result['product_urls'])
        ->toContain('https://eldan.pl/705-bezrekawnik-polarowy-damski-zapinany-na-zamek-emi')
        ->toContain('https://eldan.pl/1132-bluza-carmen-dl-rekaw');
});
