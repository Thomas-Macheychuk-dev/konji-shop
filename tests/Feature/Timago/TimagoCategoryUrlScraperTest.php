<?php

use App\Services\Timago\TimagoCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts Timago categories under the OFERTA navigation and excludes NOWOŚCI', function (): void {
    Http::fake([
        'https://www.timago.com' => Http::response(<<<'HTML'
            <html><body>
                <ul class="nav__level-1">
                    <li><a href="/pl/o-nas/" title="O nas">O nas</a></li>
                    <li>
                        <a href="javascript:void();" title="Oferta">Oferta</a>
                        <ul class="nav__level-2">
                            <li><a href="https://www.timago.com/pl/nowosci/">NOWOŚCI</a></li>
                            <li>
                                <a href="https://www.timago.com/pl/rehabilitacja/">Rehabilitacja</a>
                                <div class="nav__level-3"><ul class="nav__level-3-ul">
                                    <li><a href="https://www.timago.com/pl/rehabilitacja/wozki-elektryczne/">Wózki elektryczne</a></li>
                                    <li><a href="https://www.timago.com/pl/rehabilitacja/wozki-stalowe/">Wózki stalowe</a></li>
                                </ul><div class="nav__level-3-hit"><ul>
                                    <li><a href="/pl/polecany-produkt.html">Polecany produkt</a></li>
                                </ul></div></div>
                            </li>
                            <li><a href="https://www.timago.com/pl/higiena-pacjenta/">Higiena pacjenta</a></li>
                            <li>
                                <a href="https://www.timago.com/pl/tlenoterapia/">Tlenoterapia</a>
                                <div class="nav__level-3"><ul class="nav__level-3-ul">
                                    <li><a href="https://www.timago.com/pl/tlenoterapia/koncentratory-tlenu/">Koncentratory tlenu</a></li>
                                </ul></div>
                            </li>
                        </ul>
                    </li>
                    <li><a href="/pl/katalogi/" title="Katalogi">Katalogi</a></li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(TimagoCategoryUrlScraper::class)->scrape();

    expect($result['source'])->toBe('timago')
        ->and($result['category_urls'])->toBe([
            'https://www.timago.com/pl/rehabilitacja/',
            'https://www.timago.com/pl/rehabilitacja/wozki-elektryczne/',
            'https://www.timago.com/pl/rehabilitacja/wozki-stalowe/',
            'https://www.timago.com/pl/higiena-pacjenta/',
            'https://www.timago.com/pl/tlenoterapia/',
            'https://www.timago.com/pl/tlenoterapia/koncentratory-tlenu/',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['top_categories'])->toHaveCount(3);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'timago',
        'external_category_id' => 'rehabilitacja',
        'name' => 'Rehabilitacja',
        'source_name' => 'Rehabilitacja',
        'url' => 'https://www.timago.com/pl/rehabilitacja/',
        'slug' => 'rehabilitacja',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Rehabilitacja'],
    ]);

    expect($result['categories'][1])->toMatchArray([
        'external_category_id' => 'rehabilitacja/wozki-elektryczne',
        'name' => 'Wózki elektryczne',
        'level' => 2,
        'parent_external_category_id' => 'rehabilitacja',
        'path' => ['Rehabilitacja', 'Wózki elektryczne'],
    ]);

    expect($result['category_urls'])
        ->not->toContain('https://www.timago.com/pl/nowosci/')
        ->not->toContain('https://www.timago.com/pl/polecany-produkt.html');
});

it('normalizes Timago category links and ignores utility, product, external, and non-offer links', function (): void {
    Http::fake([
        'https://www.timago.com' => Http::response(<<<'HTML'
            <html><body>
                <ul class="nav__level-1">
                    <li><a href="/pl/katalogi/" title="Katalogi">Katalogi</a></li>
                    <li>
                        <a href="javascript:void();" title="Oferta">Oferta</a>
                        <ul class="nav__level-2">
                            <li><a href="/pl/rehabilitacja/?utm=ignored">Rehabilitacja</a></li>
                            <li><a href="https://timago.com/pl/ortopedia#ignored">Ortopedia</a></li>
                            <li><a href="/pl/nowosci/">NOWOŚCI</a></li>
                            <li><a href="/pl/produkt-testowy.html">Product</a></li>
                            <li><a href="https://example.com/external/">External</a></li>
                            <li><a href="mailto:test@example.com">Email</a></li>
                            <li><a href="tel:+48123456789">Phone</a></li>
                        </ul>
                    </li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(TimagoCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://www.timago.com/pl/rehabilitacja/',
        'https://www.timago.com/pl/ortopedia/',
    ]);
});

it('records failed Timago category discovery URLs', function (): void {
    Http::fake([
        'https://www.timago.com' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(TimagoCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://www.timago.com',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.timago.com' => 'HTTP 500',
        ]);
});
