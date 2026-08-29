<?php

use App\Services\Neoxmed\NeoxmedProductPageScraper;
use Illuminate\Support\Facades\Http;

it('extracts NeoxMed product sections with NFZ data, product images and visual size charts', function (): void {
    $url = 'https://neoxmed.com/ortezy-barku/';

    $result = app(NeoxmedProductPageScraper::class)->extract(
        neoxmedShoulderFixture(),
        $url,
        [
            'name' => 'Ortezy barku',
            'slug' => 'ortezy-barku',
            'url' => $url,
        ],
    );

    expect($result['source'])->toBe('neoxmed')
        ->and($result['product_count'])->toBe(2)
        ->and($result['warnings'])->toBe([]);

    $products = collect($result['products'])->keyBy('external_product_id');
    $b01 = $products->get('B-01');
    $b02 = $products->get('B-02');

    expect($b01)->not->toBeNull()
        ->and($b01['name'])->toBe('Kamizelka stawu barkowego')
        ->and($b01['sku'])->toBe('B-01')
        ->and($b01['category'])->toBe('Ortezy barku')
        ->and($b01['brand'])->toBe(['name' => 'Neox', 'slug' => 'neox'])
        ->and($b01['price_gross_amount'])->toBeNull()
        ->and($b01['currency'])->toBeNull()
        ->and($b01['is_medical_device'])->toBeTrue()
        ->and($b01['nfz_codes'])->toBe(['J.06.01.00', 'J.06.01.01'])
        ->and($b01['size_note'])->toBe('Dostępne rozmiary (obwód klatki piersiowej):')
        ->and($b01['description_text'])->toContain('pianka poliuretanowa')
        ->and($b01['description_text'])->not->toContain('NFZ:')
        ->and($b01['images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2015/12/B-01.jpg',
            'alt' => 'B-01',
        ])
        ->and($b01['size_chart_images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2025/06/B_01N.jpg',
            'alt' => 'B_01N',
        ])
        ->and($b01['variant_candidates'])->toBe([])
        ->and($b01['warnings'])->toContain('NeoxMed publishes size information visually; variant sizes require review before import mapping.');

    expect($b02)->not->toBeNull()
        ->and($b02['name'])->toBe('Stabilizator stawu barkowego')
        ->and($b02['images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2015/12/B-02-300x237.jpg',
            'alt' => 'B-02',
        ])
        ->and($b02['size_chart_images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2025/06/B_02.jpg',
            'alt' => 'B_02',
        ]);
});

it('deduplicates the responsive duplicate product blocks on a NeoxMed category page', function (): void {
    $result = app(NeoxmedProductPageScraper::class)->extract(
        neoxmedShoulderFixture(includeResponsiveDuplicate: true),
        'https://neoxmed.com/ortezy-barku/',
    );

    expect($result['product_count'])->toBe(2)
        ->and(collect($result['products'])->pluck('external_product_id')->all())->toBe(['B-01', 'B-02']);
});

it('retries temporary NeoxMed category-page failures', function (): void {
    $url = 'https://neoxmed.com/ortezy-barku/';

    Http::fake([
        $url => Http::sequence()
            ->push('', 503)
            ->push(neoxmedShoulderFixture()),
    ]);

    $result = app(NeoxmedProductPageScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrape($url);

    expect($result['product_count'])->toBe(2)
        ->and($result['failed_urls'])->toBe([]);

    Http::assertSentCount(2);
});

function neoxmedShoulderFixture(bool $includeResponsiveDuplicate = false): string
{
    $products = <<<'HTML'
        <section class="product-row">
            <h2>B-01 Kamizelka stawu barkowego</h2>
            <p>– pianka poliuretanowa dwustronnie pokryta dzianiną poliamidową</p>
            <p>– ustala ramię w przywiedzeniu i rotacji wewnętrznej</p>
            <p>Dostępne rozmiary (obwód klatki piersiowej):</p>
            <img src="/wp2015/wp-content/uploads/2025/06/B_01N.jpg" alt="B_01N">
            <img src="/wp2015/wp-content/uploads/2015/12/B-01.jpg" alt="B-01">
            <p>NFZ: J.06.01.00, J.06.01.01</p>
            <img src="/wp2015/wp-content/uploads/2015/12/B-02-300x237.jpg" alt="B-02">
        </section>
        <section class="product-row">
            <h2>B-02 Stabilizator stawu barkowego</h2>
            <p>– termoaktywny neopren 3mm</p>
            <p>– stosowany w celu łagodzenia objawów zwichnięć</p>
            <p>Dostępne rozmiary (obwód klatki piersiowej):</p>
            <img src="/wp2015/wp-content/uploads/2025/06/B_02.jpg" alt="B_02">
        </section>
        HTML;

    $duplicate = $includeResponsiveDuplicate ? $products : '';

    return <<<HTML
        <!doctype html>
        <html lang="pl"><body>
            <main>
                <h1>Ortezy barku</h1>
                <img src="/wp-content/uploads/bark.jpg" alt="bark">
                {$products}
                {$duplicate}
            </main>
            <footer><h4>Kontakt</h4><p>Zagórze 239</p></footer>
        </body></html>
        HTML;
}

it('preserves heading word boundaries and separates reused NeoxMed source codes', function (): void {
    $result = app(NeoxmedProductPageScraper::class)->extract(
        neoxmedReusedCodeFixture(),
        'https://neoxmed.com/ortezy-konczyn-gornych/',
    );

    $products = collect($result['products'])->keyBy('external_product_id');

    expect($products->keys()->all())->toBe([
        'N-02',
        'N-03',
        'N-03-SHORT',
        'N-06',
        'N-06-SHORT',
        'N-11-SHORT',
    ])
        ->and($products['N-02']['name'])->toBe('Stabilizator nadgarstka i kciuka')
        ->and($products['N-03-SHORT']['source_code'])->toBe('N-03')
        ->and($products['N-03-SHORT']['source_qualifier'])->toBe('SHORT')
        ->and($products['N-06-SHORT']['source_code'])->toBe('N-06')
        ->and($products['N-11-SHORT']['sku'])->toBe('N-11-SHORT')
        ->and($products['N-03-SHORT']['warnings'])->toContain(
            'NeoxMed reuses source code N-03 for multiple catalogue products; derived SKU N-03-SHORT preserves the distinct source heading.',
        );
});

it('keeps the three P-30 heights as distinct products and propagates their shared NFZ code', function (): void {
    $result = app(NeoxmedProductPageScraper::class)->extract(
        neoxmedP30Fixture(),
        'https://neoxmed.com/ortezy-tulowia/',
    );

    $products = collect($result['products'])->keyBy('external_product_id');

    expect($result['product_count'])->toBe(3)
        ->and($products->keys()->all())->toBe(['P-30-21', 'P-30-24', 'P-30-30'])
        ->and($products['P-30-21']['source_code'])->toBe('P-30')
        ->and($products['P-30-21']['source_qualifier'])->toBe('21')
        ->and($products['P-30-24']['source_qualifier'])->toBe('24')
        ->and($products['P-30-30']['source_qualifier'])->toBe('30')
        ->and($products['P-30-21']['images'])->toContain([
            'url' => 'https://neoxmed.com/wp-content/uploads/P-30-21.jpg',
            'alt' => 'P-30-21',
        ])
        ->and($products['P-30-24']['images'])->toContain([
            'url' => 'https://neoxmed.com/wp-content/uploads/P-30-24.jpg',
            'alt' => 'P-30-24',
        ])
        ->and($products['P-30-30']['images'])->toContain([
            'url' => 'https://neoxmed.com/wp-content/uploads/P-30-30.jpg',
            'alt' => 'P-30-30',
        ])
        ->and($products['P-30-21']['nfz_codes'])->toBe(['S.02.01.00'])
        ->and($products['P-30-24']['nfz_codes'])->toBe(['S.02.01.00'])
        ->and($products['P-30-30']['nfz_codes'])->toBe(['S.02.01.00']);
});

it('treats NeoxMed resize-labelled neck-brace images as product images rather than size charts', function (): void {
    $result = app(NeoxmedProductPageScraper::class)->extract(
        neoxmedNeckBraceFixture(),
        'https://neoxmed.com/ortezy-szyi/',
    );

    $products = collect($result['products'])->keyBy('external_product_id');

    expect($products['SZ-01']['name'])->toBe('Kołnierz ortopedyczny typ Schanza')
        ->and($products['SZ-01']['images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2016/01/SZ-01_resize-300x225.jpg',
            'alt' => 'SZ-01_resize',
        ])
        ->and($products['SZ-01']['size_chart_images'])->toBe([])
        ->and($products['SZ-02']['images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2016/01/SZ-02_resize-300x225.jpg',
            'alt' => 'SZ-02_resize',
        ]);
});

function neoxmedReusedCodeFixture(): string
{
    return <<<'HTML'
        <!doctype html><html lang="pl"><body><main>
        <h1>Ortezy kończyn górnych</h1>
        <h2>N-02 Stabilizator nadgarstka<br>i kciuka</h2>
        <p>Opis N-02</p><p>Dostępne rozmiary:</p><img src="/wp-content/uploads/N_02.jpg" alt="N_02"><img src="/wp-content/uploads/N-02.jpg" alt="N-02">
        <h2>N-03 Stabilizator nadgarstka<br>z szynami</h2>
        <p>Opis standard N-03</p><p>NFZ: J.01.01.00</p><img src="/wp-content/uploads/N_03.jpg" alt="N_03"><img src="/wp-content/uploads/N-03.jpg" alt="N-03">
        <h2>N-03 Short – Stabilizator nadgarstka z szynami, krótki</h2>
        <p>Opis short N-03</p><img src="/wp-content/uploads/N_03.jpg" alt="N_03">
        <h2>N-06 Stabilizator nadgarstka<br>z szynami AIR FLEX</h2>
        <p>Opis standard N-06</p><img src="/wp-content/uploads/N-06.jpg" alt="N-06">
        <h2>N-06 Short – Stabilizator nadgarstka z szynami, krótki<br>AIR FLEX</h2>
        <p>Opis short N-06</p><img src="/wp-content/uploads/N-06.jpg" alt="N-06">
        <h2>N-11 Short – Stabilizator nadgarstka z szynami, krótki</h2>
        <p>Opis N-11</p><img src="/wp-content/uploads/N-11.jpg" alt="N-11">
        </main></body></html>
        HTML;
}

function neoxmedP30Fixture(): string
{
    return <<<'HTML'
        <!doctype html><html lang="pl"><body><main>
        <h1>Ortezy tułowia</h1>
        <h2>P-30 (21) Pas brzuszny 21cm</h2>
        <p>– wysokość pasa – 21cm</p><p>Dostępne rozmiary (obwód pasa):</p>
        <img src="/wp-content/uploads/P_01.jpg" alt="P_01"><img src="/wp-content/uploads/P-30-21.jpg" alt="P-30-21"><p>NFZ: S.02.01.00</p>
        <h2>P-30 (24) Pas brzuszny 24cm</h2>
        <p>– wysokość pasa – 24cm</p><p>Dostępne rozmiary (obwód pasa):</p>
        <img src="/wp-content/uploads/P_01.jpg" alt="P_01"><img src="/wp-content/uploads/P-30-24.jpg" alt="P-30-24">
        <h2>P-30 (30) Pas brzuszny 30cm</h2>
        <p>– wysokość pasa – 30cm</p><p>Dostępne rozmiary (obwód pasa):</p>
        <img src="/wp-content/uploads/P_01.jpg" alt="P_01"><img src="/wp-content/uploads/P-30-30.jpg" alt="P-30-30"><p>NFZ: S.02.01.00</p>
        </main></body></html>
        HTML;
}

function neoxmedNeckBraceFixture(): string
{
    return <<<'HTML'
        <!doctype html><html lang="pl"><body><main>
        <h1>Ortezy szyi</h1>
        <h2>SZ-01 Kołnierz ortopedyczny<br>typ Schanza</h2>
        <p>– wykonany z pianki poliuretanowej</p><p>Dostępne rozmiary (obwód szyi): S 37; M 37-42</p>
        <img src="/wp2015/wp-content/uploads/2016/01/SZ-01_resize-300x225.jpg" alt="SZ-01_resize"><p>NFZ: L.05.01.00</p>
        <h2>SZ-02 Kołnierz ortopedyczny z usztywnieniem typ Florida</h2>
        <p>– anatomiczna wkładka usztywniająca</p><img src="/wp2015/wp-content/uploads/2016/01/SZ-02_resize-300x225.jpg" alt="SZ-02_resize">
        </main></body></html>
        HTML;
}

it('splits comma-separated NeoxMed source codes that share one catalogue heading', function (): void {
    $result = app(NeoxmedProductPageScraper::class)->extract(
        neoxmedCombinedKneeCodeFixture(),
        'https://neoxmed.com/ortezy-konczyn-dolnych/',
    );

    $products = collect($result['products'])->keyBy('external_product_id');

    expect($result['product_count'])->toBe(3)
        ->and($products->keys()->all())->toBe(['K-01', 'K-02', 'K-30'])
        ->and($products['K-01']['name'])->toBe('Stabilizator stawu kolanowego')
        ->and($products['K-02']['name'])->toBe('Stabilizator stawu kolanowego')
        ->and($products['K-01']['size_chart_images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2025/06/K_01.jpg',
            'alt' => 'K_01',
        ])
        ->and($products['K-02']['size_chart_images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2025/06/K_01.jpg',
            'alt' => 'K_01',
        ])
        ->and($products['K-01']['images'])->toBe([])
        ->and($products['K-01']['warnings'])->toContain('No product image matching the NeoxMed product code was found.')
        ->and($products['K-02']['images'])->toContain([
            'url' => 'https://neoxmed.com/wp2015/wp-content/uploads/2015/12/K-02-217x300.jpg',
            'alt' => 'K-02',
        ])
        ->and($products['K-30']['name'])->toBe('Aparat szynowo-opaskowy na goleń i udo');
});

function neoxmedCombinedKneeCodeFixture(): string
{
    return <<<'HTML'
        <!doctype html><html lang="pl"><body><main>
        <h1>Ortezy kończyn dolnych</h1>
        <h2>K-01, K-02 Stabilizator stawu kolanowego</h2>
        <p>– termoaktywny neopren 3mm</p>
        <p>– nie krępuje ruchów przy zachowaniu pełnej amplitudy ruchu kolana</p>
        <p>Uwaga K-01 nie posiada otworu stabilizującego rzepkę!</p>
        <p>Dostępne rozmiary (obwód wokół rzepki):</p>
        <img src="/wp2015/wp-content/uploads/2025/06/K_01.jpg" alt="K_01">
        <img src="/wp2015/wp-content/uploads/2015/12/K-02-217x300.jpg" alt="K-02">
        <h2>K-30 Aparat szynowo-opaskowy na goleń i udo</h2>
        <p>Opis K-30</p><img src="/wp-content/uploads/K-30.jpg" alt="K-30">
        </main></body></html>
        HTML;
}
