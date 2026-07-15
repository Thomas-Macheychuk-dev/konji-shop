<?php

use App\Services\Medi\MediCategoryUrlScraper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

it('discovers only the approved medi product category trees', function (): void {
    Http::fake([
        MediCategoryUrlScraper::DEFAULT_URL => Http::response(<<<'HTML'
            <html><body>
                <nav class="navigation">
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html">Kompresja</a>
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/ponczochy-uciskowe.html">Pończochy uciskowe</a>
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/ortopedia.html">Ortopedia</a>
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/ortopedia/ortezy-i-stabilizatory-kolan.html">Ortezy i stabilizatory kolan</a>
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html">Akcesoria</a>
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria/kosmetyki-do-pielegnacji-ciala.html">Kosmetyki do pielęgnacji ciała</a>
                    <a href="https://www.medi-polska.pl/shop/kategoria-produktu/wyroznione.html">Wyróżnione</a>
                    <a href="https://www.medi-polska.pl/shop/czesci-ciala/kolano.html">Kolano</a>
                    <a href="https://www.medi-polska.pl/shop/wskazania-i-terapia/ortopedia.html">Ortopedia</a>
                    <a href="https://example.com/shop/kategoria-produktu/kompresja.html">External</a>
                </nav>
            </body></html>
            HTML),
        '*' => Http::response('<html><body></body></html>'),
    ]);

    $result = app(MediCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['source'])->toBe('medi')
        ->and($result['category_urls'])->toBe([
            'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/ortopedia.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/ponczochy-uciskowe.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/ortopedia/ortezy-i-stabilizatory-kolan.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria/kosmetyki-do-pielegnacji-ciala.html',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['top_categories'])->toHaveCount(3)
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'medi',
        'external_category_id' => 'kompresja',
        'name' => 'Kompresja',
        'source_name' => 'Kompresja',
        'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
        'slug' => 'kompresja',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Kompresja'],
        'product_count' => null,
    ]);

    expect($result['categories'][4])->toMatchArray([
        'external_category_id' => 'ortopedia/ortezy-i-stabilizatory-kolan',
        'name' => 'Ortezy i stabilizatory kolan',
        'level' => 2,
        'parent_external_category_id' => 'ortopedia',
        'path' => ['Ortopedia', 'Ortezy i stabilizatory kolan'],
    ]);
});

it('normalizes medi hosts, duplicate menu links, and Magento query strings', function (): void {
    Http::fake([
        MediCategoryUrlScraper::DEFAULT_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="//medi-polska.pl/shop/kategoria-produktu/kompresja.html?product_list_order=name">Kompresja</a>
                <a href="/shop/kategoria-produktu/kompresja.html?p=2">more...</a>
                <a href="/shop/kategoria-produktu/kompresja/rajstopy-uciskowe.html?product_list_limit=24#products">Rajstopy uciskowe</a>
                <a href="mailto:sklep@medi-polska.pl">Email</a>
            </body></html>
            HTML),
        '*' => Http::response('<html><body></body></html>'),
    ]);

    $result = app(MediCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['category_urls'])->toBe([
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
        'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja/rajstopy-uciskowe.html',
    ])
        ->and($result['categories'][0]['name'])->toBe('Kompresja')
        ->and($result['categories'][1]['path'])->toBe(['Kompresja', 'Rajstopy uciskowe']);
});

it('follows approved medi category pages to discover nested categories', function (): void {
    Http::fake([
        MediCategoryUrlScraper::DEFAULT_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="/shop/kategoria-produktu/akcesoria.html">Akcesoria</a>
            </body></html>
            HTML),
        'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html' => Http::response(<<<'HTML'
            <html><body>
                <a href="/shop/kategoria-produktu/akcesoria.html">Akcesoria</a>
                <a href="/shop/kategoria-produktu/akcesoria/pielegnacja-produktow.html">Pielęgnacja produktów</a>
            </body></html>
            HTML),
        'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria/pielegnacja-produktow.html' => Http::response('<html><body></body></html>'),
        '*' => Http::response('', 404),
    ]);

    $result = app(MediCategoryUrlScraper::class)
        ->withMaxPages(5)
        ->scrape();

    expect($result['visited_urls'])->toBe([
        MediCategoryUrlScraper::DEFAULT_URL,
        'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html',
        'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria/pielegnacja-produktow.html',
    ])
        ->and($result['category_urls'])->toBe([
            'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html',
            'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria/pielegnacja-produktow.html',
        ])
        ->and($result['categories'][1])->toMatchArray([
            'name' => 'Pielęgnacja produktów',
            'level' => 2,
            'parent_external_category_id' => 'akcesoria',
            'path' => ['Akcesoria', 'Pielęgnacja produktów'],
        ]);
});

it('records failed medi category discovery URLs', function (): void {
    Http::fake([
        MediCategoryUrlScraper::DEFAULT_URL => Http::response('', 500),
    ]);

    $result = app(MediCategoryUrlScraper::class)
        ->withMaxAttempts(1)
        ->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([MediCategoryUrlScraper::DEFAULT_URL])
        ->and($result['failed_urls'])->toBe([
            MediCategoryUrlScraper::DEFAULT_URL => 'HTTP 500',
        ]);
});

it('runs the medi category discovery command and saves its JSON result', function (): void {
    $relativePath = 'scrapers/medi/categories-test.json';
    $absolutePath = storage_path('app/'.$relativePath);

    File::delete($absolutePath);

    Http::fake([
        MediCategoryUrlScraper::DEFAULT_URL => Http::response(<<<'HTML'
            <html><body>
                <a href="/shop/kategoria-produktu/kompresja.html">Kompresja</a>
            </body></html>
            HTML),
        '*' => Http::response('<html><body></body></html>'),
    ]);

    $this->artisan('medi:categories', [
        '--page-limit' => 1,
        '--request-delay-ms' => 0,
        '--save' => $relativePath,
    ])
        ->expectsOutputToContain('Discovering medi category hierarchy...')
        ->expectsOutputToContain('Discovered category URLs: 1')
        ->expectsOutputToContain('Saved discovery result to storage/app/'.$relativePath)
        ->assertSuccessful();

    expect(File::exists($absolutePath))->toBeTrue()
        ->and(json_decode((string) File::get($absolutePath), true, 512, JSON_THROW_ON_ERROR))
        ->toMatchArray([
            'source' => 'medi',
            'category_urls' => [
                'https://www.medi-polska.pl/shop/kategoria-produktu/kompresja.html',
            ],
        ]);

    File::delete($absolutePath);
});
