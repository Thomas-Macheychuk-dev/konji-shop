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
                <a href="/179-bluza-damska-medyczna-taliowana-krotki-rekaw-roza?v=3640">Róża</a>
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

function eldanProductHtml(int $id, string $name): string
{
    $payload = htmlspecialchars(json_encode([
        'id' => $id,
        'name' => $name,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return '<html><body><v-product :product="'.$payload.'"></v-product></body></html>';
}
