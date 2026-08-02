<?php

use App\Services\Novicare\NovicareProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts Novicare descriptions indications size variants images and related products', function (): void {
    $url = 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';

    $product = app(NovicareProductScraper::class)->extract(
        novicareProductPageFixture(
            canonicalUrl: $url,
            name: 'Orteza stawu kolanowego 6155',
            category: 'Kolano',
            sizes: ['XS', 'S', 'M', 'L', 'XL', '2XL'],
            measurements: ['27 – 30', '30 – 33', '33 – 36', '36 – 39', '39 – 42', '42 – 45'],
        ),
        $url,
        [
            'external_id' => hash('sha256', $url),
            'product_code' => '6155',
            'category_paths' => [['Kolano']],
        ],
    );

    expect($product)->toMatchArray([
        'source' => 'novicare',
        'source_url' => $url,
        'canonical_url' => $url,
        'external_product_id' => hash('sha256', $url),
        'slug' => 'orteza-stawu-kolanowego-6155',
        'name' => 'Orteza stawu kolanowego 6155',
        'product_code' => '6155',
        'category' => 'Kolano',
        'categories' => ['Kolano'],
        'category_paths' => [['Kolano']],
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'is_medical_device' => true,
        'failed_urls' => [],
    ])->and($product['description_items'])->toBe([
        'wysokiej jakości neopren utrzymuje odpowiednią temperaturę,',
        'wyposażona jest w dwuzawiasowe szyny stabilizujące staw kolanowy.',
    ])->and($product['indications'])->toBe([
        'niestabilności kolana,',
        'schorzeniach łękotki.',
    ])->and($product['size_table'])->toMatchArray([
        'header_label' => 'Rozmiar',
        'sizes' => ['XS', 'S', 'M', 'L', 'XL', '2XL'],
    ])->and($product['variant_candidates'])->toHaveCount(6)
        ->and($product['variant_candidates'][0])->toMatchArray([
            'size' => 'XS',
            'measurements' => ['cm' => '27 – 30'],
            'measurement_label' => 'cm',
            'measurement' => '27 – 30',
            'price_gross_amount' => null,
            'availability' => 'unknown',
        ])
        ->and($product['variant_candidates'][0]['external_variant_id'])
        ->toBe(hash('sha256', $url.'|size|xs'))
        ->and(collect($product['images'])->pluck('type')->all())
        ->toBe(['main', 'detail', 'detail', 'fitting'])
        ->and($product['images'][0]['url'])
        ->toBe('https://novicare.pl/wp-content/uploads/2024/09/main.webp')
        ->and($product['images'][1]['url'])
        ->toBe('https://novicare.pl/wp-content/uploads/2024/10/detail-one.jpg')
        ->and($product['related_products'])->toBe([
            [
                'url' => 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-dr-k018/',
                'name' => 'DR-K018 Orteza stawu kolanowego DR-K018',
                'product_code' => 'DR-K018',
            ],
        ])
        ->and($product['warnings'])->toBe([]);
});

it('supports universal sizes and warns when optional size data is absent', function (): void {
    $url = 'https://novicare.pl/produkty/nadgarstek/orteza-palca-dr-w132-2/';

    $universal = app(NovicareProductScraper::class)->extract(
        novicareProductPageFixture(
            canonicalUrl: $url,
            name: 'Orteza palca DR-W132-2',
            category: 'Nadgarstek',
            sizes: ['UNI'],
            measurements: ['UNI'],
        ),
        $url,
    );

    expect($universal['product_code'])->toBe('DR-W132-2')
        ->and($universal['variant_candidates'])->toHaveCount(1)
        ->and($universal['variant_candidates'][0]['size'])->toBe('UNI')
        ->and($universal['variant_candidates'][0]['measurement'])->toBe('UNI');

    $withoutSizeTable = app(NovicareProductScraper::class)->extract(
        novicareProductPageFixture(
            canonicalUrl: $url,
            name: 'Orteza palca DR-W132-2',
            category: 'Nadgarstek',
            sizes: [],
            measurements: [],
        ),
        $url,
    );

    expect($withoutSizeTable['variant_candidates'])->toBe([])
        ->and($withoutSizeTable['warnings'])->toContain('Product size table was not found.');
});

it('extracts colour model variants when a Novicare product has no size table', function (): void {
    $url = 'https://novicare.pl/produkty/akcesoria/tasmy-oporowe-do-cwiczen-dr-rb100/';

    $html = str_replace(
        '<h3>Detale produktu</h3>',
        <<<'HTML'
            <h3>Dostępne kolory</h3>
            <figure class="wp-block-table">
                <table>
                    <thead>
                        <tr><th>Model</th><th>RB 101</th><th>RB 102</th><th>RB 103</th><th>RB 104</th><th>RB 105</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>kolor</td><td>żółta</td><td>czerwona</td><td>zielona</td><td>niebieska</td><td>czarna</td></tr>
                    </tbody>
                </table>
            </figure>
            <h3>Detale produktu</h3>
        HTML,
        novicareProductPageFixture(
            canonicalUrl: $url,
            name: 'Taśmy oporowe do ćwiczeń DR-RB100',
            category: 'Akcesoria',
            sizes: [],
            measurements: [],
        ),
    );

    $product = app(NovicareProductScraper::class)->extract($html, $url);

    expect($product['size_table'])->toBeNull()
        ->and($product['color_table'])->toMatchArray([
            'header_label' => 'Model',
            'models' => ['RB 101', 'RB 102', 'RB 103', 'RB 104', 'RB 105'],
        ])
        ->and($product['color_table']['options'])->toBe([
            ['model_code' => 'RB 101', 'color' => 'żółta'],
            ['model_code' => 'RB 102', 'color' => 'czerwona'],
            ['model_code' => 'RB 103', 'color' => 'zielona'],
            ['model_code' => 'RB 104', 'color' => 'niebieska'],
            ['model_code' => 'RB 105', 'color' => 'czarna'],
        ])
        ->and($product['variant_candidates'])->toHaveCount(5)
        ->and($product['variant_candidates'][0])->toMatchArray([
            'product_code' => 'RB 101',
            'model_code' => 'RB 101',
            'color' => 'żółta',
            'size' => null,
            'name' => 'RB 101 – żółta',
            'option_values' => [
                ['attribute' => 'Kolor', 'value' => 'żółta'],
            ],
        ])
        ->and($product['variant_candidates'][0]['external_variant_id'])
        ->toBe(hash('sha256', $url.'|model|rb 101|color|żółta'))
        ->and($product['warnings'])->not->toContain('Product size table was not found.')
        ->and(collect($product['attributes'])->pluck('code')->all())
        ->toBe(['kod-produktu', 'model', 'kolor']);
});

it('normalizes only Novicare product-detail URLs', function (): void {
    $scraper = app(NovicareProductScraper::class);

    expect($scraper->normalizeProductUrl(
        'http://www.novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/?utm_source=test#opis'
    ))->toBe('https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/')
        ->and($scraper->normalizeProductUrl(
            '/produkty/stopa/orteza-stawu-skokowego-dr-a004/',
            'https://novicare.pl/produkty/stopa/'
        ))->toBe('https://novicare.pl/produkty/stopa/orteza-stawu-skokowego-dr-a004/')
        ->and($scraper->normalizeProductUrl('https://novicare.pl/produkty/kolano/'))->toBeNull()
        ->and($scraper->normalizeProductUrl('https://example.com/produkty/kolano/product/'))->toBeNull();
});

it('retries temporary Novicare product-page failures', function (): void {
    $url = 'https://novicare.pl/produkty/kolano/orteza-stawu-kolanowego-6155/';

    Http::fake([
        $url => Http::sequence()
            ->push('', 503)
            ->push(novicareProductPageFixture(
                canonicalUrl: $url,
                name: 'Orteza stawu kolanowego 6155',
                category: 'Kolano',
                sizes: ['M'],
                measurements: ['33 – 36'],
            )),
    ]);

    $product = app(NovicareProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrape($url);

    expect($product['name'])->toBe('Orteza stawu kolanowego 6155')
        ->and($product['failed_urls'])->toBe([]);

    Http::assertSentCount(2);
});

/**
 * @param  array<int, string>  $sizes
 * @param  array<int, string>  $measurements
 */
function novicareProductPageFixture(
    string $canonicalUrl,
    string $name,
    string $category,
    array $sizes,
    array $measurements,
): string {
    $sizeTable = '';

    if ($sizes !== []) {
        $sizeHeaders = implode('', array_map(
            static fn (string $size): string => '<th>'.$size.'</th>',
            $sizes,
        ));
        $measurementCells = implode('', array_map(
            static fn (string $measurement): string => '<td>'.$measurement.'</td>',
            $measurements,
        ));
        $sizeTable = <<<HTML
            <h3>Dostępne rozmiary</h3>
            <figure class="wp-block-table">
                <table><thead><tr><th>Rozmiar</th>{$sizeHeaders}</tr></thead>
                <tbody><tr><td>cm</td>{$measurementCells}</tr></tbody></table>
            </figure>
        HTML;
    }

    return <<<HTML
        <!doctype html>
        <html lang="pl-PL">
            <head>
                <title>{$name} - NOVICARE - Dystrybutor Specjalistycznych Produktów Ortopedycznych</title>
                <link rel="canonical" href="{$canonicalUrl}">
                <meta name="description" content="Opis SEO {$name}">
                <meta property="og:title" content="{$name} - NOVICARE - Dystrybutor Specjalistycznych Produktów Ortopedycznych">
            </head>
            <body>
                <main id="main">
                    <article>
                        <div class="entry-content single-content">
                            <a href="/produkty/{$category}/"><h6>{$category}</h6></a>
                            <h2>{$name}</h2>
                            <figure class="wp-block-kadence-image">
                                <img src="/wp-content/uploads/2024/09/main.webp" alt="">
                            </figure>
                            <h2>{$name}</h2>
                            <h3>Opis</h3>
                            <div class="wp-block-kadence-iconlist"><ul>
                                <li><span class="kt-svg-icon-list-text">wysokiej jakości neopren utrzymuje odpowiednią temperaturę,</span></li>
                                <li><span class="kt-svg-icon-list-text">wyposażona jest w dwuzawiasowe szyny stabilizujące staw kolanowy.</span></li>
                            </ul></div>
                            <h3>Wskazania</h3>
                            <div class="wp-block-kadence-iconlist"><ul>
                                <li><span class="kt-svg-icon-list-text">niestabilności kolana,</span></li>
                                <li><span class="kt-svg-icon-list-text">schorzeniach łękotki.</span></li>
                            </ul></div>
                            {$sizeTable}
                            <h3>Detale produktu</h3>
                            <div class="wp-block-kadence-advancedgallery">
                                <img src="/wp-content/uploads/2024/10/detail-one-300x300.jpg" data-full-image="/wp-content/uploads/2024/10/detail-one.jpg">
                                <img src="/wp-content/uploads/2024/10/detail-two-300x300.jpg" data-light-image="/wp-content/uploads/2024/10/detail-two.jpg">
                            </div>
                            <h3>Sposób zakładania</h3>
                            <figure><img src="/wp-content/uploads/2024/10/fitting.jpg"></figure>
                            <h3>Powiązane produkty</h3>
                            <a class="kt-blocks-info-box-link-wrap" href="/produkty/kolano/orteza-stawu-kolanowego-dr-k018/">
                                <h4>DR-K018</h4><p>Orteza stawu kolanowego DR-K018</p>
                                <img src="/wp-content/uploads/2024/09/related.jpg">
                            </a>
                            <h2>Masz jakieś pytania?</h2>
                            <img src="/wp-content/uploads/2024/09/contact.jpg">
                        </div>
                    </article>
                </main>
            </body>
        </html>
    HTML;
}
