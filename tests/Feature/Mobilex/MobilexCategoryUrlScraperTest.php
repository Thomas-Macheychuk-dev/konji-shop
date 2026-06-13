<?php

use App\Services\Mobilex\MobilexCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts Mobilex category links between Nowości and Serwis', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/nowosci/">Nowości</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/">Wózki inwalidzkie</a></li>
                    <li><a href="/kategoria-produktu/sprzet-elektryczny/">Sprzęt elektryczny</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/schodolazy-i-rampy/?utm=ignored">Schodołazy i rampy</a></li>
                    <li><a href="https://mobilex.pl/obuwie-scholl/">Obuwie Scholl</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/sprzet-do-transferu/">Sprzęt do transferu</a></li>
                    <li><a href="https://mobilex.pl/serwis/">Serwis</a></li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        'https://mobilex.pl/kategoria-produktu/sprzet-elektryczny/',
        'https://mobilex.pl/kategoria-produktu/schodolazy-i-rampy/',
        'https://mobilex.pl/obuwie-scholl/',
        'https://mobilex.pl/kategoria-produktu/sprzet-do-transferu/',
    ]);

    expect($result['categories'])->toBe([
        [
            'name' => 'Wózki inwalidzkie',
            'url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        ],
        [
            'name' => 'Sprzęt elektryczny',
            'url' => 'https://mobilex.pl/kategoria-produktu/sprzet-elektryczny/',
        ],
        [
            'name' => 'Schodołazy i rampy',
            'url' => 'https://mobilex.pl/kategoria-produktu/schodolazy-i-rampy/',
        ],
        [
            'name' => 'Obuwie Scholl',
            'url' => 'https://mobilex.pl/obuwie-scholl/',
        ],
        [
            'name' => 'Sprzęt do transferu',
            'url' => 'https://mobilex.pl/kategoria-produktu/sprzet-do-transferu/',
        ],
    ]);

    expect($result['visited_urls'])->toBe([
        'https://mobilex.pl/produkty/',
    ]);

    expect($result['failed_urls'])->toBe([]);
});

it('ignores links outside the Mobilex custom taxonomy category list boundaries', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://mobilex.pl/kategoria-produktu/from-main-menu/">Main menu category</a>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/nowosci/">Nowości</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/">Wózki inwalidzkie</a></li>
                    <li><a href="https://mobilex.pl/serwis/">Serwis</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/after-serwis/">After Serwis</a></li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
    ]);
});

it('records failed Mobilex category discovery URLs', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://mobilex.pl/produkty/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://mobilex.pl/produkty/' => 'HTTP 500',
        ]);
});

it('discovers lower categories and keeps top categories without children as product scraping URLs', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/nowosci/">Nowości</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/">Wózki inwalidzkie</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/">Siedziska ortopedyczne</a></li>
                    <li><a href="https://mobilex.pl/obuwie-scholl/">Obuwie Scholl</a></li>
                    <li><a href="https://mobilex.pl/serwis/">Serwis</a></li>
                </ul>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/' => Http::response(<<<'HTML'
            <html><body>
                <h1>Wózki inwalidzkie</h1>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-podstawowe/">wózki podstawowe</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-aktywne/">wózki aktywne</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-spacerowe-dla-dzieci/">wózki spacerowe dla dzieci</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/wozki-z-napedem-elektrycznym/">wózki z napędem elektrycznym</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/akcesoria-do-wozkow-inwalidzkich/">akcesoria do wózków</a></li>
                </ul>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/' => Http::response(<<<'HTML'
            <html><body>
                <h1>Siedziska ortopedyczne</h1>
                <div class="builder-posts-wrap">
                    <article><a href="https://mobilex.pl/produkty/siedzisko-testowe/">Siedzisko testowe</a></article>
                </div>
            </body></html>
            HTML),
        'https://mobilex.pl/obuwie-scholl/' => Http::response(<<<'HTML'
            <html><body>
                <div id="kafle-sholl">
                    <div class="module_column">
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/obuwie-medyczne-damskie/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Obuwie medyczne damskie</h2></div>
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/buty-operacyjne-meskie/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Buty operacyjne męskie</h2></div>
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/anatomiczne-buty-damskie/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Anatomiczne buty damskie</h2></div>
                    </div>
                    <div class="module_column">
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/buty-medyczne-meskie/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Buty medyczne męskie</h2></div>
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/obuwie-operacyjne-damskie/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Obuwie operacyjne damskie</h2></div>
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/anatomiczne-obuwie-meskie/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Anatomiczne obuwie męskie</h2></div>
                    </div>
                    <div class="module_column">
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/obuwie-damskie-do-pracy/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Obuwie damskie do pracy</h2></div>
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/buty-do-pracy/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Buty do pracy</h2></div>
                        <div class="module-image"><a href="https://mobilex.pl/kategoria-produktu/komfortowe-obuwie-dla-seniorow/"><img alt="tile"></a></div>
                        <div class="module-text"><h2>Komfortowe obuwie dla seniorów</h2></div>
                    </div>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexCategoryUrlScraper::class)->scrapeHierarchy();

    expect($result['visited_urls'])->toBe([
        'https://mobilex.pl/produkty/',
        'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/',
        'https://mobilex.pl/obuwie-scholl/',
    ]);

    expect($result['category_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
        'https://mobilex.pl/kategoria-produktu/wozki-aktywne/',
        'https://mobilex.pl/kategoria-produktu/wozki-spacerowe-dla-dzieci/',
        'https://mobilex.pl/kategoria-produktu/wozki-z-napedem-elektrycznym/',
        'https://mobilex.pl/kategoria-produktu/akcesoria-do-wozkow-inwalidzkich/',
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/',
        'https://mobilex.pl/kategoria-produktu/obuwie-medyczne-damskie/',
        'https://mobilex.pl/kategoria-produktu/buty-operacyjne-meskie/',
        'https://mobilex.pl/kategoria-produktu/anatomiczne-buty-damskie/',
        'https://mobilex.pl/kategoria-produktu/buty-medyczne-meskie/',
        'https://mobilex.pl/kategoria-produktu/obuwie-operacyjne-damskie/',
        'https://mobilex.pl/kategoria-produktu/anatomiczne-obuwie-meskie/',
        'https://mobilex.pl/kategoria-produktu/obuwie-damskie-do-pracy/',
        'https://mobilex.pl/kategoria-produktu/buty-do-pracy/',
        'https://mobilex.pl/kategoria-produktu/komfortowe-obuwie-dla-seniorow/',
    ]);

    expect($result['top_categories'][0])->toMatchArray([
        'name' => 'Wózki inwalidzkie',
        'url' => 'https://mobilex.pl/kategoria-produktu/wozki-inwalidzkie/',
        'children' => [
            [
                'name' => 'wózki podstawowe',
                'url' => 'https://mobilex.pl/kategoria-produktu/wozki-podstawowe/',
            ],
            [
                'name' => 'wózki aktywne',
                'url' => 'https://mobilex.pl/kategoria-produktu/wozki-aktywne/',
            ],
            [
                'name' => 'wózki spacerowe dla dzieci',
                'url' => 'https://mobilex.pl/kategoria-produktu/wozki-spacerowe-dla-dzieci/',
            ],
            [
                'name' => 'wózki z napędem elektrycznym',
                'url' => 'https://mobilex.pl/kategoria-produktu/wozki-z-napedem-elektrycznym/',
            ],
            [
                'name' => 'akcesoria do wózków',
                'url' => 'https://mobilex.pl/kategoria-produktu/akcesoria-do-wozkow-inwalidzkich/',
            ],
        ],
    ]);

    expect($result['top_categories'][1])->toMatchArray([
        'name' => 'Siedziska ortopedyczne',
        'url' => 'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/',
        'children' => [],
        'product_category_urls' => [
            'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/',
        ],
    ]);

    expect($result['top_categories'][2]['children'])->toHaveCount(9)
        ->and($result['top_categories'][2]['children'][0])->toBe([
            'name' => 'Obuwie medyczne damskie',
            'url' => 'https://mobilex.pl/kategoria-produktu/obuwie-medyczne-damskie/',
        ]);
});

it('keeps a top category as a product scraping URL when child category discovery fails', function (): void {
    Http::fake([
        'https://mobilex.pl/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="custom-taxonomy-list">
                    <li><a href="https://mobilex.pl/kategoria-produktu/nowosci/">Nowości</a></li>
                    <li><a href="https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/">Siedziska ortopedyczne</a></li>
                    <li><a href="https://mobilex.pl/serwis/">Serwis</a></li>
                </ul>
            </body></html>
            HTML),
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(MobilexCategoryUrlScraper::class)->scrapeHierarchy();

    expect($result['category_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/',
    ])->and($result['failed_urls'])->toBe([
        'https://mobilex.pl/kategoria-produktu/siedziska-ortopedyczne/' => 'HTTP 500',
    ]);
});
