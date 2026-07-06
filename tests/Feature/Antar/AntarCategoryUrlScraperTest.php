<?php

use App\Services\Antar\AntarCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts Antar product categories from the Elementor product menu', function (): void {
    Http::fake([
        'https://antar.net/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <nav class="e-n-menu" aria-label="Menu">
                    <a href="https://antar.net/produkty/ortopedia/">Ortopedia</a>
                    <a href="https://antar.net/produkty/rehabilitacja/">Rehabilitacja</a>
                    <a href="https://antar.net/produkty/sprzet-pomocniczy-i-sanitarny/">Sprzęt pomocniczy i sanitarny</a>
                    <a href="https://antar.net/produkty/wyroby-niemedyczne/">Wyroby niemedyczne</a>
                    <a href="https://antar.net/produkty/ortopedia/ortezy-konczyn-dolnych/">Ortezy kończyn dolnych</a>
                    <a href="https://antar.net/produkty/ortopedia/ortezy-konczyn-dolnych/">Wszystko</a>
                    <a href="https://antar.net/produkty/ortopedia/ortezy-konczyn-dolnych/ortezy-stawu-skokowego/">Ortezy stawu skokowego</a>
                    <a href="https://antar.net/produkty/rehabilitacja/wozki-inwalidzkie/">Wózki inwalidzkie</a>
                    <a href="https://antar.net/produkty/sprzet-pomocniczy-i-sanitarny/tlenoterapia/">Tlenoterapia</a>
                    <a href="https://antar.net/produkty/wyroby-niemedyczne/fotele-elektryczne/">Fotele elektryczne</a>
                    <a href="https://antar.net/produkt/testowy-produkt/">Product detail</a>
                    <a href="https://example.com/produkty/ortopedia/">External</a>
                    <a href="mailto:antar@antar.net">Email</a>
                </nav>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['source'])->toBe('antar')
        ->and($result['category_urls'])->toBe([
            'https://antar.net/produkty/ortopedia/',
            'https://antar.net/produkty/rehabilitacja/',
            'https://antar.net/produkty/sprzet-pomocniczy-i-sanitarny/',
            'https://antar.net/produkty/wyroby-niemedyczne/',
            'https://antar.net/produkty/ortopedia/ortezy-konczyn-dolnych/',
            'https://antar.net/produkty/rehabilitacja/wozki-inwalidzkie/',
            'https://antar.net/produkty/sprzet-pomocniczy-i-sanitarny/tlenoterapia/',
            'https://antar.net/produkty/wyroby-niemedyczne/fotele-elektryczne/',
            'https://antar.net/produkty/ortopedia/ortezy-konczyn-dolnych/ortezy-stawu-skokowego/',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['top_categories'])->toHaveCount(4)
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'antar',
        'external_category_id' => 'ortopedia',
        'name' => 'Ortopedia',
        'source_name' => 'Ortopedia',
        'url' => 'https://antar.net/produkty/ortopedia/',
        'slug' => 'ortopedia',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Ortopedia'],
        'product_count' => null,
    ]);

    expect($result['categories'][4])->toMatchArray([
        'external_category_id' => 'ortopedia/ortezy-konczyn-dolnych',
        'name' => 'Ortezy kończyn dolnych',
        'level' => 2,
        'parent_external_category_id' => 'ortopedia',
        'path' => ['Ortopedia', 'Ortezy kończyn dolnych'],
    ]);

    expect($result['categories'][8])->toMatchArray([
        'external_category_id' => 'ortopedia/ortezy-konczyn-dolnych/ortezy-stawu-skokowego',
        'name' => 'Ortezy stawu skokowego',
        'level' => 3,
        'parent_external_category_id' => 'ortopedia/ortezy-konczyn-dolnych',
        'path' => ['Ortopedia', 'Ortezy kończyn dolnych', 'Ortezy stawu skokowego'],
    ]);
});

it('does not treat WooCommerce pagination links as categories', function (): void {
    Http::fake([
        'https://antar.net/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://antar.net/produkty/ortopedia/">Ortopedia</a>
                <a href="https://antar.net/produkty/ortopedia/page/2/">2</a>
                <a href="https://antar.net/produkty/ortopedia/page/3/">3</a>
                <a href="https://antar.net/produkty/ortopedia/ortezy-kregoslupa/">Ortezy kręgosłupa</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['category_urls'])->toBe([
        'https://antar.net/produkty/ortopedia/',
        'https://antar.net/produkty/ortopedia/ortezy-kregoslupa/',
    ])
        ->and(collect($result['categories'])->pluck('name')->all())->not->toContain('Page')
        ->and(collect($result['categories'])->pluck('external_category_id')->all())->not->toContain('ortopedia/page/2');
});

it('does not use WordPress skip-link text as a category name', function (): void {
    Http::fake([
        'https://antar.net/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <a class="skip-link screen-reader-text" href="#content">Przejdź do treści</a>
                <a href="https://antar.net/produkty/ortopedia/">Przejdź do treści</a>
                <a href="https://antar.net/produkty/rehabilitacja/">Przejdź do treści</a>
                <a href="https://antar.net/produkty/rehabilitacja/wozki-inwalidzkie/">Wózki inwalidzkie</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['categories'][0])->toMatchArray([
        'external_category_id' => 'ortopedia',
        'name' => 'Ortopedia',
        'path' => ['Ortopedia'],
    ])
        ->and($result['categories'][2]['path'])->toBe(['Rehabilitacja', 'Wózki inwalidzkie']);
});

it('follows discovered Antar category pages to discover nested categories', function (): void {
    Http::fake([
        'https://antar.net/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/rehabilitacja/">Rehabilitacja</a>
            </body></html>
            HTML),
        'https://antar.net/produkty/rehabilitacja/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/rehabilitacja/">Rehabilitacja</a>
                <a href="/produkty/rehabilitacja/chodziki/">Chodziki</a>
            </body></html>
            HTML),
        'https://antar.net/produkty/rehabilitacja/chodziki/' => Http::response(<<<'HTML'
            <html><body>
                <a href="/produkty/rehabilitacja/chodziki/">Chodziki</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarCategoryUrlScraper::class)
        ->withMaxPages(5)
        ->scrape();

    expect($result['visited_urls'])->toBe([
        'https://antar.net/produkty/',
        'https://antar.net/produkty/rehabilitacja/',
        'https://antar.net/produkty/rehabilitacja/chodziki/',
    ])
        ->and($result['category_urls'])->toBe([
            'https://antar.net/produkty/rehabilitacja/',
            'https://antar.net/produkty/rehabilitacja/chodziki/',
        ])
        ->and($result['categories'][1])->toMatchArray([
            'name' => 'Chodziki',
            'level' => 2,
            'parent_external_category_id' => 'rehabilitacja',
            'path' => ['Rehabilitacja', 'Chodziki'],
        ]);
});

it('records failed Antar category discovery URLs', function (): void {
    Http::fake([
        'https://antar.net/produkty/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(AntarCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://antar.net/produkty/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://antar.net/produkty/' => 'HTTP 500',
        ]);
});
