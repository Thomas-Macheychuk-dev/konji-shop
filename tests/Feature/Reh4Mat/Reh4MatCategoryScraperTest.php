<?php

use App\Services\Reh4Mat\Reh4MatCategoryScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('extracts only allowed Reh4Mat top categories and their internal descendants', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/' => Http::response(reh4MatCategoryMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatCategoryScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect(array_column($result['top_categories'], 'name'))->toBe([
        'KOŃCZYNA DOLNA',
        'KOŃCZYNA GÓRNA',
        'KRĘGOSŁUP',
        'TUŁÓW',
        'MIEDNICA',
        'ORTEZY PEDIATRYCZNE',
        'PIONIZACJA',
        'STABILIZACJA',
        'WYROBY PRZECIWODLEŻYNOWE',
        'POZOSTAŁE WYROBY MEDYCZNE',
        'AKCESORIA',
    ]);

    $lowerLimb = $result['top_categories'][0];
    $pediatric = $result['top_categories'][5];
    $standing = $result['top_categories'][6];

    expect($lowerLimb['url'])->toBe('https://www.reh4mat.com/produkt/konczyna-dolna/')
        ->and(array_column($lowerLimb['children'], 'name'))->toBe([
            'Ortezy całej kończyny dolnej',
            'Ortezy biodra',
        ])
        ->and($pediatric['source_name'])->toBe('Ortezy i akcesoria pediatryczne')
        ->and($pediatric['name'])->toBe('ORTEZY PEDIATRYCZNE')
        ->and($standing['children'])->toBe([])
        ->and($result['category_urls'])->toContain('https://www.reh4mat.com/produkt/ortezy-dloni/')
        ->and($result['category_urls'])->not->toContain('https://www.reh4mat.com/wyroby-na-zamowienie/')
        ->and(array_column($result['skipped_categories'], 'name'))->toContain('Wyroby na zamówienie')
        ->and(array_column($result['skipped_categories'], 'url'))->toContain('http://biowalkeractive.com/walker/');
});

it('returns leaf Reh4Mat category URLs for later product-link scraping', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/' => Http::response(reh4MatCategoryMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatCategoryScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['product_category_urls'])->toContain(
        'https://www.reh4mat.com/produkt/cala-konczyna-dolna/',
        'https://www.reh4mat.com/produkt/biodro/',
        'https://www.reh4mat.com/produkt/ortezy-dloni/',
        'https://www.reh4mat.com/produkt/pionizacja/',
        'https://www.reh4mat.com/produkt/akcesoria-do-ortez/',
    )->not->toContain(
        'https://www.reh4mat.com/wyroby-na-zamowienie/',
        'http://biowalkeractive.com/walker/',
    );
});

it('records failed Reh4Mat category page requests', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatCategoryScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['top_categories'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://www.reh4mat.com/produkt/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.reh4mat.com/produkt/' => 'HTTP 500',
        ]);
});

it('can print the Reh4Mat category discovery result as JSON', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/' => Http::response(reh4MatCategoryMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('reh4mat:categories', [
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('"source": "reh4mat"')
        ->toContain('"name": "KOŃCZYNA DOLNA"')
        ->toContain('"name": "ORTEZY PEDIATRYCZNE"')
        ->toContain('"name": "AKCESORIA"');

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    $topCategoryNames = array_column($decoded['top_categories'], 'name');

    expect($topCategoryNames)->toBe([
        'KOŃCZYNA DOLNA',
        'KOŃCZYNA GÓRNA',
        'KRĘGOSŁUP',
        'TUŁÓW',
        'MIEDNICA',
        'ORTEZY PEDIATRYCZNE',
        'PIONIZACJA',
        'STABILIZACJA',
        'WYROBY PRZECIWODLEŻYNOWE',
        'POZOSTAŁE WYROBY MEDYCZNE',
        'AKCESORIA',
    ])->and($topCategoryNames)->not->toContain('WYROBY NA ZAMÓWIENIE')
        ->and(array_column($decoded['skipped_categories'], 'name'))->toContain('WYROBY NA ZAMÓWIENIE');
});

function reh4MatCategoryMenuFixture(): string
{
    return <<<'HTML'
        <html>
            <body>
                <div id="front-menu-wrapper">
                    <div id="front-menu" class="single-menu">
                        <ul id="menu-gorne-menu" class="menu">
                            <li><a href="https://www.reh4mat.com/produkt/konczyna-dolna/"><div class="main-menu-desc">KOŃCZYNA DOLNA</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/konczyna-gorna/"><div class="main-menu-desc">KOŃCZYNA GÓRNA</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/kregoslup/"><div class="main-menu-desc">KRĘGOSŁUP</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/tulow/"><div class="main-menu-desc">TUŁÓW</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/miednica/"><div class="main-menu-desc">MIEDNICA</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/ortezy-pediatryczne/"><div class="main-menu-desc">ORTEZY PEDIATRYCZNE</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/pionizacja/"><div class="main-menu-desc">PIONIZACJA</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/stabilizacja/"><div class="main-menu-desc">STABILIZACJA</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/wyroby-przeciwodlezynowe/"><div class="main-menu-desc">WYROBY PRZECIWODLEŻYNOWE</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/pozostale-wyroby-medyczne/"><div class="main-menu-desc">POZOSTAŁE WYROBY MEDYCZNE</div></a></li>
                            <li><a href="https://www.reh4mat.com/produkt/akcesoria/"><div class="main-menu-desc">AKCESORIA</div></a></li>
                            <li><a href="https://www.reh4mat.com/wyroby-na-zamowienie/"><div class="main-menu-desc">WYROBY NA&nbsp;ZAMÓWIENIE</div></a></li>
                        </ul>
                    </div>
                </div>
                <ul id="menu-glowne-menu" class="menu">
                    <li class="produktycssmenu menu-item menu-item-has-children"><a href="#">Produkty</a>
                        <ul class="sub-menu">
                            <li class="katalogprod menu-item"><a>KATALOG</a></li>
                            <li id="menu-item-320537" class="manmenuprod menu-item menu-item-has-children"><a href="#">Kończyna dolna</a>
                                <ul class="sub-menu">
                                    <li id="menu-item-320549"><a href="https://www.reh4mat.com/produkt/cala-konczyna-dolna/">Ortezy całej kończyny dolnej</a></li>
                                    <li id="menu-item-320550"><a href="https://www.reh4mat.com/produkt/biodro/">Ortezy biodra</a></li>
                                </ul>
                            </li>
                            <li id="menu-item-320538" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/konczyna-gorna/">Kończyna górna</a>
                                <ul class="sub-menu">
                                    <li id="menu-item-320556"><a href="https://www.reh4mat.com/produkt/ortezy-calej-konczyny-gornej/">Ortezy całej kończyny górnej</a></li>
                                    <li id="menu-item-320557"><a href="https://www.reh4mat.com/produkt/ortezy-dloni/">Ortezy dłoni</a></li>
                                </ul>
                            </li>
                            <li id="menu-item-320539" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/kregoslup/">Kręgosłup</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/caly-kregoslup/">Ortezy całego kręgosłupa (TLSO)</a></li></ul></li>
                            <li id="menu-item-320540" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/tulow/">Tułów</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/pasy-brzuszne/">Pasy brzuszne</a></li></ul></li>
                            <li id="menu-item-320541" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/miednica/">Miednica</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/ortezy-miednicy/">Ortezy miednicy</a></li></ul></li>
                            <li id="menu-item-320542" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/ortezy-pediatryczne/">Ortezy i&nbsp;akcesoria pediatryczne</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/pediatryczne-glowa/">Głowa</a></li></ul></li>
                            <li id="menu-item-320543" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/pionizacja/">Pionizacja</a><ul class="sub-menu"><li><a href="http://biowalkeractive.com/walker/">Walker</a></li><li><a href="http://biowalkeractive.com/biowalker/">BioWalker</a></li></ul></li>
                            <li id="menu-item-320544" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/stabilizacja/">Stabilizacja</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/stabilizacja-glowy/">Stabilizacja głowy</a></li></ul></li>
                            <li id="menu-item-320545" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/wyroby-przeciwodlezynowe/">Wyroby przeciwodleżynowe</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/krazki/">Krążki</a></li></ul></li>
                            <li id="menu-item-320546" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/pozostale-wyroby-medyczne/">Pozostałe wyroby medyczne</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/materace/">Materace</a></li></ul></li>
                            <li id="menu-item-320547" class="manmenuprod menu-item menu-item-has-children"><a href="https://www.reh4mat.com/produkt/akcesoria/">Akcesoria</a><ul class="sub-menu"><li><a href="https://www.reh4mat.com/produkt/akcesoria-do-ortez/">Akcesoria do&nbsp;ortez</a></li></ul></li>
                            <li id="menu-item-320548" class="manmenuprod menu-item"><a href="https://www.reh4mat.com/wyroby-na-zamowienie/">Wyroby na&nbsp;zamówienie</a></li>
                        </ul>
                    </li>
                </ul>
            </body>
        </html>
    HTML;
}
