<?php

declare(strict_types=1);

use App\Services\Armedical\ArmedicalProductDataCrawler;
use App\Services\Armedical\ArmedicalProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts ARmedical product identity descriptions specifications sizes images documents and medical-device flag', function (): void {
    $url = 'https://armedical.pl/oferta/elastyczny-tkaninowy-stabilizator-stawu-lokciowego/';

    $product = app(ArmedicalProductScraper::class)->extract(armedicalProductFixture(), $url);

    expect($product)->toMatchArray([
        'source' => 'armedical',
        'external_product_id' => 'armedical-elastyczny-tkaninowy-stabilizator-stawu-lokciowego',
        'catalogue_number' => 'AR-167E',
        'sku' => 'AR-167E',
        'name' => 'Elastyczny stabilizator łokcia. IMMOBILO SOFT-E',
        'brand' => 'ARmedical',
        'manufacturer' => 'ARMEDICAL Sp. z o.o.',
        'categories' => ['Produkty ortopedyczne', 'Łokieć'],
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'is_medical_device' => true,
        'failed_urls' => [],
    ]);

    expect($product['description'])->toContain('Stabilizator wykonany z innowacyjnego materiału')
        ->and($product['description_html'])->toContain('https://armedical.pl/wp-content/uploads/2026/08/ar-167e.jpg')
        ->and($product['images'])->toHaveCount(1)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://armedical.pl/wp-content/uploads/2026/08/ar-167e.jpg',
            'is_primary' => true,
        ])
        ->and($product['technical_specifications'])->toContain([
            'label' => 'Maksymalne obciążenie',
            'value' => '120 kg',
        ])
        ->and($product['size_options'])->toContain([
            'label' => 'S',
            'value' => '24,1 ÷ 26,7 cm',
        ])
        ->and($product['documents'])->toHaveCount(2)
        ->and(array_column($product['documents'], 'type'))->toBe(['manual', 'declaration']);
});

it('crawls ARmedical product-link discovery without catalogue writes', function (): void {
    $url = 'https://armedical.pl/oferta/elastyczny-tkaninowy-stabilizator-stawu-lokciowego/';

    Http::fake([
        $url => Http::response(armedicalProductFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(ArmedicalProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlFromProductLinkDiscovery([
            'source' => 'armedical',
            'products' => [[
                'url' => $url,
                'external_product_id' => 'armedical-elastyczny-tkaninowy-stabilizator-stawu-lokciowego',
                'catalogue_number' => 'AR-167E',
                'name' => 'Elastyczny stabilizator łokcia. IMMOBILO SOFT-E',
                'source_category_url' => 'https://armedical.pl/kategoria-produktow/lokiec/',
            ]],
            'product_urls' => [$url],
        ]);

    expect($result['source'])->toBe('armedical')
        ->and($result['product_count'])->toBe(1)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['products'][0]['catalogue_number'])->toBe('AR-167E')
        ->and($result['products'][0]['is_medical_device'])->toBeTrue();
});

function armedicalProductFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head>
            <title>AR-167E Elastyczny stabilizator łokcia. IMMOBILO SOFT-E - ARmedical</title>
            <meta name="description" content="Elastyczny stabilizator łokcia ARmedical">
            <link rel="canonical" href="https://www.armedical.pl/oferta/elastyczny-tkaninowy-stabilizator-stawu-lokciowego/">
        </head>
        <body>
            <nav class="breadcrumbs">
                <a href="/">Strona główna</a>
                <a href="/kategoria-produktow/produkty-ortopedyczne/">Produkty ortopedyczne</a>
                <a href="/kategoria-produktow/lokiec/">Łokieć</a>
            </nav>
            <main>
                <h1>AR-167E Elastyczny stabilizator łokcia. IMMOBILO SOFT-E</h1>
                <div>To jest wyrób medyczny. Używaj go zgodnie z instrukcją używania lub etykietą.</div>
                <div class="entry-content">
                    <p>Stabilizator wykonany z innowacyjnego materiału, miękkiego i elastycznego.</p>
                    <img src="/wp-content/uploads/2026/08/ar-167e.jpg" alt="AR-167E">
                    <h2>Informacje o produkcie</h2>
                    <ul>
                        <li>Maksymalne obciążenie: 120 kg</li>
                        <li>Materiał: Spandex</li>
                    </ul>
                    <h2>Rozmiar</h2>
                    <table>
                        <tr><th>Rozmiar</th><th>Obwód</th></tr>
                        <tr><td>S</td><td>24,1 ÷ 26,7 cm</td></tr>
                        <tr><td>M</td><td>27,9 ÷ 30,5 cm</td></tr>
                    </table>
                    <a href="/wp-content/uploads/2026/08/AR-167E_INSTRUKCJA.pdf">Instrukcja obsługi</a>
                    <a href="https://armedical.pl/wp-content/uploads/2026/08/AR-167E_DEKLARACJA.pdf">Deklaracja zgodności</a>
                </div>
            </main>
        </body>
        </html>
        HTML;
}

it('keeps product documents and images clean and parses walker parameters individually', function (): void {
    $url = 'https://armedical.pl/oferta/balkonik-testowy/';

    $product = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head><link rel="canonical" href="https://armedical.pl/oferta/balkonik-testowy/"></head>
        <body>
            <main>
                <h1>AR-999 Balkonik testowy</h1>
                <div>To jest wyrób medyczny.</div>
                <div class="entry-content">
                    <p>Opis balkonika testowego o odpowiedniej długości do testu.</p>
                    <h3>Parametry techniczne</h3>
                    <ul>
                        <li>szerokość: 57 cm</li>
                        <li>długość: 54 cm</li>
                        <li>waga: 2,4 kg</li>
                        <li>maksymalne obciążenie: 120 kg</li>
                    </ul>
                    <img src="/wp-content/uploads/2026/08/ar-999.jpg" alt="AR-999">
                    <img src="/wp-content/uploads/2026/08/ar-999-150x150.jpg" alt="AR-999 miniatura">
                    <a href="/wp-content/uploads/2026/08/ar-999-instrukcja.pdf">Instrukcja obsługi</a>
                </div>
            </main>
            <footer>
                <a href="/wp-content/uploads/2024/05/dsa-regulamin-social-media.pdf">DSA – Regulamin Social Media</a>
            </footer>
        </body>
        </html>
        HTML, $url);

    expect($product['technical_specifications'])->toContain(
        ['label' => 'szerokość', 'value' => '57 cm'],
        ['label' => 'długość', 'value' => '54 cm'],
        ['label' => 'waga', 'value' => '2,4 kg'],
        ['label' => 'maksymalne obciążenie', 'value' => '120 kg'],
    )->and($product['images'])->toHaveCount(1)
        ->and($product['images'][0]['url'])->toBe('https://armedical.pl/wp-content/uploads/2026/08/ar-999.jpg')
        ->and($product['documents'])->toHaveCount(1)
        ->and($product['documents'][0]['type'])->toBe('manual');
});

it('separates size-table rows from specifications and warns about inconsistent source catalogue data', function (): void {
    $url = 'https://armedical.pl/oferta/soft-cast-test/';

    $product = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head><link rel="canonical" href="https://armedical.pl/oferta/soft-cast-test/"></head>
        <body>
            <main>
                <h1>82102 do 82102 Soft Cast test</h1>
                <div class="entry-content">
                    <p>Opis produktu Soft Cast wystarczająco długi do poprawnego parsowania.</p>
                    <table>
                        <tr><th>Nr katalogowy</th><th>Rozmiar</th><th>j.m.</th></tr>
                        <tr><td>82102</td><td>5 cm x 3,6 m</td><td>szt.</td></tr>
                        <tr><td>82103</td><td>7,6 cm x 3,6 m</td><td>szt.</td></tr>
                        <tr><td>82104</td><td>10,1 cm x 3,6 m</td><td>szt.</td></tr>
                        <tr><td>82104</td><td>12,7 cm x 3,6 m</td><td>szt.</td></tr>
                    </table>
                    <img src="/wp-content/uploads/2026/08/soft-cast.jpg" alt="Soft Cast">
                </div>
            </main>
        </body>
        </html>
        HTML, $url);

    expect($product['size_options'])->toHaveCount(4)
        ->and($product['technical_specifications'])->toBe([])
        ->and($product['warnings'])->toContain(
            'Source size table reuses catalogue/size label 82104 for multiple values; review source data before import.',
            'Source catalogue range 82102 do 82102 is inconsistent with 4 size-table rows; review source data before import.',
        );
});


it('parses horizontal ARmedical size matrices as individual size options', function (): void {
    $ar060 = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head><link rel="canonical" href="https://armedical.pl/oferta/stabilizator-palca-aparat-stacka/"></head>
        <body>
            <main>
                <h1>AR-060 Stabilizator palca – aparat Stacka</h1>
                <div class="entry-content">
                    <p>Opis stabilizatora palca AR-060 wystarczająco długi do poprawnego parsowania produktu.</p>
                    <table>
                        <tr><th>Rozmiar:</th><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td><td>5,5</td><td>6</td><td>7</td></tr>
                        <tr><th>Obwód palca w stawie paliczkowym dalszym (cm):</th><td>&lt; 3,5</td><td>3,5 ÷ 4,0</td><td>4,0 ÷ 4,5</td><td>4,5 ÷ 5,0</td><td>5,0 ÷ 5,5</td><td>6,0 ÷ 7,0</td><td>7,0 ÷ 7,5</td><td>7,5 ÷ 8,0</td></tr>
                    </table>
                    <img src="/wp-content/uploads/2026/08/ar-060.jpg" alt="AR-060">
                </div>
            </main>
        </body>
        </html>
        HTML, 'https://armedical.pl/oferta/stabilizator-palca-aparat-stacka/');

    $ar061 = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html>
        <html lang="pl">
        <head><link rel="canonical" href="https://armedical.pl/oferta/stabilizator-palca-aparat-stacka-z-tasma-dociagajaca/"></head>
        <body>
            <main>
                <h1>AR-061 Stabilizator palca – aparat Stacka z taśmą dociągającą</h1>
                <div class="entry-content">
                    <p>Opis stabilizatora palca AR-061 wystarczająco długi do poprawnego parsowania produktu.</p>
                    <table>
                        <tr><th>Rozmiar:</th><td>0</td><td>1</td><td>2</td><td>3</td><td>4</td><td>5</td></tr>
                        <tr><th>Obwód palca w stawie paliczkowym dalszym (cm):</th><td>&lt; 3,5</td><td>3,5 ÷ 4,0</td><td>4,0 ÷ 4,5</td><td>4,5 ÷ 5,0</td><td>5,0 ÷ 5,5</td><td>5,5 ÷ 6,0</td></tr>
                    </table>
                    <img src="/wp-content/uploads/2026/08/ar-061.jpg" alt="AR-061">
                </div>
            </main>
        </body>
        </html>
        HTML, 'https://armedical.pl/oferta/stabilizator-palca-aparat-stacka-z-tasma-dociagajaca/');

    expect($ar060['size_options'])->toHaveCount(8)
        ->and($ar060['size_options'])->toContain(
            ['label' => '1', 'value' => '< 3,5'],
            ['label' => '5,5', 'value' => '6,0 ÷ 7,0'],
            ['label' => '7', 'value' => '7,5 ÷ 8,0'],
        )
        ->and($ar060['technical_specifications'])->toBe([])
        ->and($ar061['size_options'])->toHaveCount(6)
        ->and($ar061['size_options'])->toContain(
            ['label' => '0', 'value' => '< 3,5'],
            ['label' => '5', 'value' => '5,5 ÷ 6,0'],
        )
        ->and($ar061['technical_specifications'])->toBe([]);
});

it('parses headerless two-column ARmedical size tables', function (): void {
    $product = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html><html lang="pl"><body><main>
            <h1>AR-167E Elastyczny stabilizator łokcia</h1>
            <div class="entry-content">
                <p>Opis produktu wystarczająco długi do poprawnego parsowania danych źródłowych.</p>
                <table>
                    <tr><td>S</td><td>24,1 ÷ 26,7 cm</td></tr>
                    <tr><td>M</td><td>27,9 ÷ 30,5 cm</td></tr>
                    <tr><td>L</td><td>31,8 ÷ 34,3 cm</td></tr>
                    <tr><td>XL</td><td>35,6 ÷ 40,6 cm</td></tr>
                    <tr><td>XXL</td><td>powyżej 41,9 cm</td></tr>
                </table>
                <img src="/wp-content/uploads/2026/08/ar-167e.jpg" alt="AR-167E">
            </div>
        </main></body></html>
        HTML, 'https://armedical.pl/oferta/ar-167e-test/');

    expect($product['size_options'])->toHaveCount(5)
        ->and($product['size_options'])->toContain(
            ['label' => 'S', 'value' => '24,1 ÷ 26,7 cm'],
            ['label' => 'XXL', 'value' => 'powyżej 41,9 cm'],
        )
        ->and($product['technical_specifications'])->toBe([]);
});

it('expands catalogue size tables and parallel ARband identities', function (): void {
    $product = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html><html lang="pl"><body><main>
            <h1>EB Taśma rehabilitacyjna ARband</h1>
            <div class="entry-content">
                <p>Opis taśmy rehabilitacyjnej wystarczająco długi do poprawnego parsowania produktu.</p>
                <table>
                    <tr><th>Numer katalogowy</th><th>Rozmiar taśmy</th><th>Siła oporu</th></tr>
                    <tr><td>EB-30R / EB-M30</td><td>15cm x 45,5m / 15cm x 2mb</td><td>słaba</td></tr>
                    <tr><td>EB-35R / EB-M35</td><td>15cm x 45,5m / 15cm x 2mb</td><td>średnia</td></tr>
                </table>
                <img src="/wp-content/uploads/2026/08/eb.jpg" alt="EB">
            </div>
        </main></body></html>
        HTML, 'https://armedical.pl/oferta/arband-test/');

    expect($product['size_options'])->toHaveCount(4)
        ->and($product['size_options'])->toContain(
            ['label' => 'EB-30R', 'value' => '15cm x 45,5m'],
            ['label' => 'EB-M30', 'value' => '15cm x 2mb'],
            ['label' => 'EB-M35', 'value' => '15cm x 2mb'],
        )
        ->and($product['technical_specifications'])->toBe([]);
});

it('parses collar height matrices and model comparison tables as variants', function (): void {
    $collar = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html><html lang="pl"><body><main>
            <h1>AR-223 Kołnierz ortopedyczny typu Florida</h1>
            <div class="entry-content">
                <p>Opis kołnierza ortopedycznego wystarczająco długi do poprawnego parsowania produktu.</p>
                <table>
                    <tr><th>Wysokość: 8cm</th><th>Wysokość: 10cm</th><th>Wysokość: 12cm</th></tr>
                    <tr><td>Rozmiar S:</td><td>obwód do 38cm</td><td>Rozmiar S:</td><td>obwód do 38cm</td><td>Rozmiar S:</td><td>obwód do 38cm</td></tr>
                    <tr><td>Rozmiar M:</td><td>obwód 38-43cm</td><td>Rozmiar M:</td><td>obwód 38-43cm</td><td>Rozmiar M:</td><td>obwód 38-43cm</td></tr>
                </table>
                <img src="/wp-content/uploads/2026/08/ar-223.jpg" alt="AR-223">
            </div>
        </main></body></html>
        HTML, 'https://armedical.pl/oferta/ar-223-test/');

    $toilet = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html><html lang="pl"><body><main>
            <h1>AR-110 / AR-115 Nakładka toaletowa z klapą</h1>
            <div class="entry-content">
                <p>Opis nakładki toaletowej wystarczająco długi do poprawnego parsowania produktu.</p>
                <table>
                    <tr><th>Parametr:</th><th>Model AR-110</th><th>Model AR-115</th></tr>
                    <tr><td>Szerokość zewnętrzna:</td><td>36,5 cm</td><td>36,5 cm</td></tr>
                    <tr><td>Wysokość:</td><td>10 cm</td><td>15 cm</td></tr>
                </table>
                <img src="/wp-content/uploads/2026/08/ar-110.jpg" alt="AR-110">
            </div>
        </main></body></html>
        HTML, 'https://armedical.pl/oferta/ar-110-test/');

    expect($collar['size_options'])->toHaveCount(6)
        ->and($collar['size_options'])->toContain(
            ['label' => 'S / 8cm', 'value' => 'obwód do 38cm'],
            ['label' => 'M / 12cm', 'value' => 'obwód 38-43cm'],
        )
        ->and($collar['technical_specifications'])->toBe([])
        ->and($toilet['size_options'])->toBe([
            ['label' => 'AR-110', 'value' => 'Wysokość: 10 cm'],
            ['label' => 'AR-115', 'value' => 'Wysokość: 15 cm'],
        ])
        ->and($toilet['technical_specifications'])->toBe([]);
});

it('selects the correct model-specific Walker height from shared size matrices', function (): void {
    $matrix = <<<'HTML'
        <table>
            <tr><th>AR-607 AR-608</th><th>Rozmiar buta</th><th>Długość stopy [cm]</th><th>Długość podeszwy [cm]</th><th>Szerokość podeszwy [cm]</th><th>Wysokość całkowita [cm]</th></tr>
            <tr><th>AR-607</th><th>AR-608</th></tr>
            <tr><td>S</td><td>34-38</td><td>22-24</td><td>27,5</td><td>9,9</td><td>30,2</td><td>43</td></tr>
            <tr><td>M</td><td>38-41</td><td>24,5-26,5</td><td>29,3</td><td>10,8</td><td>31</td><td>45</td></tr>
        </table>
        HTML;

    $low = app(ArmedicalProductScraper::class)->extract(
        '<html><body><main><h1>AR-607 Walker niski</h1><div class="entry-content"><p>Opis produktu Walker niski wystarczająco długi do poprawnego parsowania.</p>'.$matrix.'<img src="/wp-content/uploads/2026/08/ar-607.jpg"></div></main></body></html>',
        'https://armedical.pl/oferta/ar-607-test/',
    );
    $high = app(ArmedicalProductScraper::class)->extract(
        '<html><body><main><h1>AR-608 Walker wysoki</h1><div class="entry-content"><p>Opis produktu Walker wysoki wystarczająco długi do poprawnego parsowania.</p>'.$matrix.'<img src="/wp-content/uploads/2026/08/ar-608.jpg"></div></main></body></html>',
        'https://armedical.pl/oferta/ar-608-test/',
    );

    expect($low['size_options'])->toHaveCount(2)
        ->and($high['size_options'])->toHaveCount(2)
        ->and($low['size_options'][0]['label'])->toBe('S')
        ->and($low['size_options'][0]['value'])->toContain('Wysokość całkowita: 30,2 cm')
        ->and($high['size_options'][0]['value'])->toContain('Wysokość całkowita: 43 cm')
        ->and($low['technical_specifications'])->toBe([])
        ->and($high['technical_specifications'])->toBe([]);
});

it('parses wheelchair seat width matrices without treating diagram columns as variants', function (): void {
    $product = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html><html lang="pl"><body><main>
            <h1>AR-320 Wózek inwalidzki aluminiowy PERFECT</h1>
            <div class="entry-content">
                <p>Opis wózka inwalidzkiego wystarczająco długi do poprawnego parsowania produktu.</p>
                <table>
                    <tr><th>Siedzisko wymiary [cm]</th><th>Podnóżki</th><th>Podłokietniki</th><th></th></tr>
                    <tr><td></td><td>D</td><td>A</td><td>B</td><td>C</td></tr>
                    <tr><td>Szer. 16” (41cm)</td><td>41</td><td>104</td><td>60</td><td>95</td></tr>
                    <tr><td>Szer. 17” (43cm)</td><td>43</td><td>104</td><td>62</td><td>95</td></tr>
                </table>
                <img src="/wp-content/uploads/2026/08/ar-320.jpg" alt="AR-320">
            </div>
        </main></body></html>
        HTML, 'https://armedical.pl/oferta/ar-320-test/');

    expect($product['size_options'])->toBe([
        ['label' => 'Szer. 16” (41cm)', 'value' => 'Szerokość siedziska: 41 cm'],
        ['label' => 'Szer. 17” (43cm)', 'value' => 'Szerokość siedziska: 43 cm'],
    ])->and($product['technical_specifications'])->toBe([]);
});

it('keeps structured specifications bounded to their source value', function (): void {
    $product = app(ArmedicalProductScraper::class)->extract(<<<'HTML'
        <!doctype html><html lang="pl"><body><main>
            <h1>AR-999A Test parametrów</h1>
            <div class="entry-content additional-informations">
                <p>Opis produktu testowego wystarczająco długi do poprawnego parsowania danych źródłowych.</p>
                <div class="product-info">
                    <p>– szerokość całkowita: 65cm</p>
                    <p>– długość całkowita: 61cm</p>
                    <p>– waga: 4,6kg</p>
                </div>
                <p>kolor: zielony metalic Instrukcja obsługi Dokumenty rejestrowe</p>
                <img src="/wp-content/uploads/2026/08/ar-999a.jpg" alt="AR-999A">
            </div>
        </main></body></html>
        HTML, 'https://armedical.pl/oferta/ar-999a-test/');

    expect($product['technical_specifications'])->toContain(
        ['label' => 'szerokość całkowita', 'value' => '65cm'],
        ['label' => 'długość całkowita', 'value' => '61cm'],
        ['label' => 'waga', 'value' => '4,6kg'],
        ['label' => 'kolor', 'value' => 'zielony metalic'],
    );
});
