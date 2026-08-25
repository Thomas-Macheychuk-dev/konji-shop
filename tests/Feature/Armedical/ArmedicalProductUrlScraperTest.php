<?php

declare(strict_types=1);

use App\Services\Armedical\ArmedicalProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('crawls the paginated ARmedical offer archive and deduplicates products', function (): void {
    Http::fake([
        'https://armedical.pl/oferta/' => Http::response(<<<'HTML'
            <html><body>
                <div class="products">
                    <a href="/oferta/balkonik-aluminiowy-skladany-kroczaco-staly-podwojna-rama-h/">AR-002 Balkonik aluminiowy składany, krocząco-stały. Podwójna rama H</a>
                    <a href="https://www.armedical.pl/oferta/elastyczny-tkaninowy-stabilizator-stawu-lokciowego/?ref=archive">AR-167E Elastyczny stabilizator łokcia. IMMOBILO SOFT-E</a>
                </div>
                <nav class="pagination"><a class="next" href="/oferta/page/2/">następne</a></nav>
            </body></html>
            HTML),
        'https://armedical.pl/oferta/page/2/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/oferta/elastyczny-tkaninowy-stabilizator-stawu-lokciowego/">AR-167E Elastyczny stabilizator łokcia. IMMOBILO SOFT-E</a>
                <a href="/oferta/oklad-zimno-cieply-classic/">CHP-1025 Okład zimno-ciepły – Classic, 10x25cm</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ArmedicalProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrapeListings();

    expect($result['source'])->toBe('armedical')
        ->and($result['visited_urls'])->toBe([
            'https://armedical.pl/oferta/',
            'https://armedical.pl/oferta/page/2/',
        ])
        ->and($result['product_urls'])->toHaveCount(3)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['stopped_early'])->toBeFalse();

    expect($result['products'][0])->toMatchArray([
        'external_product_id' => 'armedical-balkonik-aluminiowy-skladany-kroczaco-staly-podwojna-rama-h',
        'catalogue_number' => 'AR-002',
        'name' => 'Balkonik aluminiowy składany, krocząco-stały. Podwójna rama H',
    ]);

    expect($result['products'][2])->toMatchArray([
        'catalogue_number' => 'CHP-1025',
        'name' => 'Okład zimno-ciepły – Classic, 10x25cm',
    ]);
});
