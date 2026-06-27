<?php

use App\Services\Reh4Mat\Reh4MatProductScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('scrapes one Reh4Mat product page into normalized product data with pictograms', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/');

    expect($result['source'])->toBe('reh4mat')
        ->and($result['source_url'])->toBe('https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/')
        ->and($result['canonical_url'])->toBe('https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/')
        ->and($result['external_product_id'])->toBe('341026')
        ->and($result['slug'])->toBe('am-sp-07')
        ->and($result['name'])->toBe('AM-SP-07')
        ->and($result['brand'])->toBe('4medic')
        ->and($result['sku'])->toBe('AM-SP-07')
        ->and($result['category'])->toBe('Ortezy dłoni')
        ->and($result['categories'])->toBe(['KOŃCZYNA GÓRNA', 'Ortezy dłoni'])
        ->and($result['price_gross_amount'])->toBeNull()
        ->and($result['currency'])->toBe('PLN')
        ->and($result['availability'])->toBe('unknown')
        ->and($result['is_medical_device'])->toBeTrue()
        ->and($result['product_meta'])->toBe([
            'Kod katalogowy' => 'AM-SP-07',
            'Nazwa handlowa' => 'ORTEZA PALCA RĘKI',
            'Model' => 'AM-SP-07',
        ])
        ->and($result['codes'])->toBe([
            'UNSPSC' => ['42241704'],
            'UMDNS' => ['16210'],
            'NFZ' => ['H.03.02.00', 'H.03.02.01'],
        ])
        ->and($result['short_description'])->toContain('Orteza palca')
        ->and($result['description_html'])->toContain('STABILIZACJA I OCHRONA PALCÓW DŁONI')
        ->and($result['images'])->toHaveCount(2)
        ->and($result['images'][0]['url'])->toBe('https://www.reh4mat.com/uploads/2026/05/4T7A2492-1.jpg')
        ->and($result['images'][1]['url'])->toBe('https://www.reh4mat.com/uploads/2021/11/aIMG_6625-1190x680.jpg')
        ->and($result['pictograms'])->toHaveCount(3)
        ->and($result['pictograms'][0])->toBe([
            'label' => 'Orteza palca',
            'image_url' => 'https://www.reh4mat.com/uploads/2021/04/orteza-palca.png',
            'description' => null,
            'source' => 'reh4mat_piktogram',
        ])
        ->and($result['pictograms'][2]['label'])->toBe('Wyrób medyczny kl.I')
        ->and($result['regulatory_icons'])->toHaveCount(2)
        ->and($result['regulatory_icons'][0]['label'])->toBe('CE')
        ->and($result['regulatory_icons'][1]['label'])->toBe('MD')
        ->and($result['downloads'])->toHaveCount(3)
        ->and($result['downloads'][0])->toBe([
            'label' => 'Deklaracja zgodności',
            'url' => 'https://reh4mat.com/deklaracje/pl/117.pdf',
            'type' => 'pdf',
        ])
        ->and($result['tabs'])->toHaveCount(3)
        ->and($result['tabs'][0]['title'])->toBe('Opis')
        ->and($result['tabs'][1]['title'])->toBe('Sposób zakładania')
        ->and($result['medical_device_notice'])->toBe('TO JEST WYRÓB MEDYCZNY. UŻYWAJ GO ZGODNIE Z INSTRUKCJĄ UŻYWANIA LUB ETYKIETĄ.')
        ->and($result['warnings'])->toBe([]);
});

it('extracts StabiloBed-style pictograms and tab downloads when the page contains those sections', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/pediatryczne-cala-konczyna-dolna/dzieciecy-separator-konczyn-dolnych-p-ss-28/' => Http::response(reh4MatStabilobedStyleProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://www.reh4mat.com/produkt/pediatryczne-cala-konczyna-dolna/dzieciecy-separator-konczyn-dolnych-p-ss-28/');

    expect($result['name'])->toBe('P-SS-28 Dziecięcy separator kończyn dolnych')
        ->and($result['sku'])->toBe('P-SS-28')
        ->and($result['images'][0]['url'])->toBe('https://www.reh4mat.com/media/k2/items/cache/36fdb1a35cd2f54f95cf2119fb5bc7ed_XL.jpg')
        ->and($result['pictograms'])->toHaveCount(2)
        ->and($result['pictograms'][0])->toBe([
            'label' => 'granulat',
            'image_url' => 'https://www.reh4mat.com/images/ikony/granulat.png',
            'description' => 'Produkt został wypełniony bardzo lekkimi kuleczkami polistyrenu.',
            'source' => 'stabilobed_zaleta',
        ])
        ->and($result['pictograms'][1]['image_url'])->toBe('https://www.reh4mat.com/images/ikony/puremed.png')
        ->and($result['tabs'])->toHaveCount(2)
        ->and($result['downloads'])->toBe([
            [
                'label' => 'Katalog STABILObed®',
                'url' => 'https://www.reh4mat.com/images/StabiloBED_05032025_PL-EN_katalog.pdf',
                'type' => 'pdf',
            ],
        ]);
});


it('scrapes BodyMap product page gallery images from the top product content gallery', function (): void {
    Http::fake([
        'https://bodymapsystem.pl/p/zaglowek-motylkowy-bodymap-dx/' => Http::response(reh4MatBodyMapProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://bodymapsystem.pl/p/zaglowek-motylkowy-bodymap-dx/');

    expect($result['source'])->toBe('reh4mat')
        ->and($result['source_url'])->toBe('https://bodymapsystem.pl/p/zaglowek-motylkowy-bodymap-dx/')
        ->and($result['external_product_id'])->toBe('651')
        ->and($result['slug'])->toBe('zaglowek-motylkowy-bodymap-dx')
        ->and($result['name'])->toBe('BodyMap® DX – Zagłówek motylkowy')
        ->and($result['images'])->toHaveCount(4)
        ->and($result['images'][0]['url'])->toBe('https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-stroller2.jpg')
        ->and($result['images'][1]['url'])->toBe('https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-2.jpg')
        ->and($result['images'][2]['url'])->toBe('https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma.jpg')
        ->and($result['images'][3]['url'])->toBe('https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-3.jpg')
        ->and($result['description_html'])->toContain('BodyMap<sup>®</sup> DX')
        ->and($result['description_html'])->toContain('Tabela rozmiarowa')
        ->and($result['warnings'])->toBe([]);
});

it('prints one Reh4Mat product as JSON', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('reh4mat:product', [
        'url' => 'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['source'])->toBe('reh4mat')
        ->and($decoded['name'])->toBe('AM-SP-07')
        ->and($decoded['pictograms'][0]['label'])->toBe('Orteza palca')
        ->and($decoded['downloads'][0]['label'])->toBe('Deklaracja zgodności');
});

it('saves one Reh4Mat product JSON under storage app', function (): void {
    Storage::disk('local')->delete('scrapers/reh4mat/products/am-sp-07.json');

    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/' => Http::response(reh4MatProductPageFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('reh4mat:product', [
        'url' => 'https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/',
        '--save' => 'scrapers/reh4mat/products/am-sp-07.json',
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $path = storage_path('app/scrapers/reh4mat/products/am-sp-07.json');

    expect(is_file($path))->toBeTrue();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['external_product_id'])->toBe('341026')
        ->and($decoded['regulatory_icons'])->toHaveCount(2);
});

it('records failed Reh4Mat product page requests', function (): void {
    Http::fake([
        'https://www.reh4mat.com/produkt/ortezy-dloni/missing-product/' => Http::response('', 403),
        '*' => Http::response('', 404),
    ]);

    $result = app(Reh4MatProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://www.reh4mat.com/produkt/ortezy-dloni/missing-product/');

    expect($result['name'])->toBe('')
        ->and($result['failed_urls'])->toBe([
            'https://www.reh4mat.com/produkt/ortezy-dloni/missing-product/' => 'HTTP 403',
        ])
        ->and($result['warnings'])->toContain('Unable to fetch Reh4Mat product page.');
});

function reh4MatProductPageFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>AM-SP-07 | Reh4Mat</title>
                <meta name="description" content="Orteza palca Alternatywa gipsu ER Innowacyjny Ortopedia Wyrób medyczny kl.I Marka: 4medic Kod UMDNS: 16210">
                <link rel="canonical" href="https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/">
                <link rel="shortlink" href="https://www.reh4mat.com/?p=341026">
            </head>
            <body class="single single-produkt postid-341026">
                <div id="crumbs">
                    <a href="https://www.reh4mat.com">Home</a> &raquo;
                    <a href="https://www.reh4mat.com/produkt/konczyna-gorna/">KOŃCZYNA GÓRNA</a> &raquo;
                    <a href="https://www.reh4mat.com/produkt/ortezy-dloni/">Ortezy dłoni</a> &raquo;
                    <a href="https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/">AM-SP-07</a>
                </div>
                <div id="content">
                    <div class="column" id="opis-produktu">
                        <h1 class="product-title"><a href="https://www.reh4mat.com/produkt/ortezy-dloni/am-sp-07/" rel="bookmark">AM-SP-07</a></h1>
                        <div style="position: relative;">
                            <img src="https://www.reh4mat.com/uploads/2026/05/4T7A2492-1.jpg" class="header-picture" alt="AM-SP-07">
                            <div class="header-watermark-container"><img src="https://www.reh4mat.com/uploads/2019/09/4medic.png" class="header-watermark" alt="4medic"></div>
                        </div>
                        <div class="piktogramy-container">
                            <div class="piktogramy-prawa">
                                <div class="piktogram"><img src="https://www.reh4mat.com/uploads/2021/04/orteza-palca.png" alt="Orteza palca"><span>Orteza palca</span></div>
                                <div class="piktogram"><img src="https://www.reh4mat.com/uploads/2021/04/STD-alternatywa-gipsu.png" alt="Alternatywa gipsu"><span>Alternatywa gipsu</span></div>
                                <div class="piktogram"><img src="https://www.reh4mat.com/uploads/2022/02/CLASS-I-MD.png" alt="Wyrób medyczny kl.I"><span>Wyrób medyczny kl.I</span></div>
                            </div>
                        </div>
                        <div id="kody">
                            <div class="kody-content">
                                Marka: <a href="https://www.reh4mat.com/marka/4medic/" rel="tag">4medic</a>
                                Kod UNSPSC: <a href="https://www.reh4mat.com/unspsc/42241704/" rel="tag">42241704</a>
                                Kod UMDNS: <a href="https://www.reh4mat.com/umdns/16210/" rel="tag">16210</a>
                                <div class="kody-column nfz24">Kod NFZ <span class="nfz24-content"><a href="https://www.reh4mat.com/nfz2024/h-03-02-00/">H.03.02.00</a><a href="https://www.reh4mat.com/nfz2024/h-03-02-01/">H.03.02.01</a></span></div>
                            </div>
                        </div>
                        <h2 class="tabtitle">Opis</h2>
                        <div class="tabcontent">
                            <h2>AM-SP-07 – ORTEZA NADGARSTKOWO-PALCOWA Z SZYNĄ STABILIZUJĄCĄ</h2>
                            <p><strong>STABILIZACJA I OCHRONA PALCÓW DŁONI</strong></p>
                            <p>Urazy palców dłoni wymagają stabilizacji.</p>
                            <img src="https://www.reh4mat.com/uploads/2021/11/aIMG_6625-1190x680.jpg" alt="Stalki aluminiowe płaskie" />
                        </div>
                        <h2 class="tabtitle">Sposób zakładania</h2>
                        <div class="tabcontent">
                            <embed src="https://www.reh4mat.com/uploads/2026/05/AM-SP-07-2026.pdf" width="100%" height="700">
                        </div>
                        <h2 class="tabtitle">Do pobrania</h2>
                        <div class="tabcontent">
                            <ul class="pdf">
                                <li><a href="https://reh4mat.com/deklaracje/pl/117.pdf">Deklaracja zgodności</a></li>
                                <li><a href="https://reh4mat.com/instrukcje/37.pdf">Instrukcja użytkowania</a></li>
                                <li><a href="https://www.reh4mat.com/uploads/2026/05/AM-SP-07-2026.pdf">Sposób zakładania</a></li>
                            </ul>
                        </div>
                        <div class="product-meta">
                            <table>
                                <tr><td><strong>Kod katalogowy</strong></td><td>AM-SP-07</td></tr>
                                <tr><td><strong>Nazwa handlowa</strong></td><td>ORTEZA PALCA RĘKI</td></tr>
                                <tr><td><strong>Model</strong></td><td>AM-SP-07</td></tr>
                            </table>
                        </div>
                        <p class="etykieta">TO JEST WYRÓB MEDYCZNY.<br>UŻYWAJ GO ZGODNIE Z INSTRUKCJĄ UŻYWANIA LUB ETYKIETĄ.</p>
                        <p class="ce"><img src="https://www.reh4mat.com/wp-content/themes/r4m-rwd/images/ce.png" alt="CE"><img src="https://www.reh4mat.com/wp-content/themes/r4m-rwd/images/md.png" alt="MD">Wyrób Medyczny klasy I zgodny z Rozporządzeniem.</p>
                    </div>
                </div>
            </body>
        </html>
    HTML;
}


function reh4MatBodyMapProductPageFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl_PL">
            <head>
                <title>BodyMap® DX &#8211; Zagłówek motylkowy | BodyMap®</title>
                <meta name="description" content="BodyMap DX to zagłówek ortopedyczny w kształcie motyla.">
                <link rel="canonical" href="https://bodymapsystem.pl/p/zaglowek-motylkowy-bodymap-dx/">
                <link rel="shortlink" href="https://bodymapsystem.pl/?p=651">
            </head>
            <body>
                <div id="container">
                    <img title="BodyMap® DX &#8211; Zagłówek motylkowy" class="wp-post-image" src="https://bodymapsystem.pl/wp-content/uploads/2014/10/baner-dx.jpg">
                    <div id="crumbs">
                        <div class="breadcrumbs">
                            <a href="https://bodymapsystem.pl" class="home">BodyMap®</a> &gt;
                            <a href="https://bodymapsystem.pl/p/">Produkty</a> &gt;
                            <span>BodyMap® DX &#8211; Zagłówek motylkowy</span>
                        </div>
                    </div>
                    <div id="content">
                        <h1 class="title"><a href="https://bodymapsystem.pl/p/zaglowek-motylkowy-bodymap-dx/">BodyMap® DX &#8211; Zagłówek motylkowy</a></h1>
                        <p>
                            <a href="https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-stroller2.jpg"><img class="alignleft size-thumbnail" src="https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-stroller2-250x250.jpg" alt="Zagłówek motylkowy BodyMap DX" width="250" height="250" /></a>
                            <a href="https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-2.jpg"><img class="alignleft size-thumbnail" src="https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-2-250x250.jpg" alt="BodyMap DX" width="250" height="250" /></a>
                            <a href="https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma.jpg"><img class="alignleft wp-image-4228 size-thumbnail" src="https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma-250x250.jpg" alt="" width="250" height="250" srcset="https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma-250x250.jpg 250w, https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma-400x400.jpg 400w, https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma-800x800.jpg 800w, https://bodymapsystem.pl/wp-content/uploads/2014/10/zaglowek-DX-tasma.jpg 1000w" /></a>
                            <a href="https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-3.jpg"><img class="alignleft size-thumbnail" src="https://bodymapsystem.pl/wp-content/uploads/2014/10/DX-3-250x250.jpg" alt="BodyMap DX" width="250" height="250" /></a>
                        </p>
                        <div class="clear"></div>
                        <p><strong>BodyMap<sup>®</sup> DX</strong> to zagłówek ortopedyczny w kształcie motyla dla podparcia głowy i szyi.</p>
                        <p><a href="https://bodymapsystem.pl/wp-content/uploads/2014/07/k62.png"><img class="aligncenter size-full" src="https://bodymapsystem.pl/wp-content/uploads/2014/07/k62.png" alt="k62" /></a></p>
                        <h3><strong>Tabela rozmiarowa</strong></h3>
                        <p><img class="alignnone" src="https://bodymapsystem.pl/wp-content/uploads/2014/10/1d3063374ca83e855f6b84a1f981ab5e.png" alt="" /></p>
                    </div>
                </div>
            </body>
        </html>
    HTML;
}

function reh4MatStabilobedStyleProductPageFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl">
            <head>
                <title>P-SS-28 Dziecięcy separator kończyn dolnych</title>
                <meta name="description" content="Dziecięcy separator kończyn dolnych zabezpiecza małego użytkownika.">
                <meta property="og:image" content="https://www.reh4mat.com/media/k2/items/cache/36fdb1a35cd2f54f95cf2119fb5bc7ed_M.jpg">
                <link rel="canonical" href="https://www.reh4mat.com/produkt/pediatryczne-cala-konczyna-dolna/dzieciecy-separator-konczyn-dolnych-p-ss-28/">
            </head>
            <body>
                <ol class="breadcrumb">
                    <li><a href="/pl/">Start</a></li>
                    <li><a href="/pl/produkty">Produkty</a></li>
                    <li><a href="/pl/produkty/stabilizacja-konczyny-dolnej">Stabilizacja kończyny dolnej</a></li>
                </ol>
                <div id="k2Container" class="itemView produkty-page">
                    <div class="itemHeader">
                        <h2 class="itemTitle">P-SS-28 Dziecięcy separator kończyn dolnych</h2>
                        <h4 class="symbol-produktu">Symbol produktu: P-SS-28</h4>
                    </div>
                    <div class="itemBody">
                        <div class="image-flex-wrapper">
                            <div class="zalety-wrapper">
                                <p class="zaleta-wrapper" title="Produkt został wypełniony bardzo lekkimi kuleczkami polistyrenu."><img src="/images/ikony/granulat.png" alt="granulat"></p>
                                <div class="hidden">Produkt został wypełniony bardzo lekkimi kuleczkami polistyrenu.</div>
                                <p class="zaleta-wrapper" title="PureMed™ to elastyczny materiał, bezpieczny dla użytkownika."><img src="/images/ikony/puremed.png" alt="PureMed"></p>
                            </div>
                            <div class="itemImageBlock">
                                <span class="itemImage">
                                    <a href="/media/k2/items/cache/36fdb1a35cd2f54f95cf2119fb5bc7ed_XL.jpg">
                                        <img src="/media/k2/items/cache/36fdb1a35cd2f54f95cf2119fb5bc7ed_L.jpg" alt="P-SS-28 Dziecięcy separator kończyn dolnych">
                                    </a>
                                </span>
                            </div>
                        </div>
                        <ul class="nav nav-tabs">
                            <li class="active"><a href="#opis" aria-controls="opis">Opis</a></li>
                            <li><a href="#do_pobrania" aria-controls="do_pobrania">Do pobrania</a></li>
                        </ul>
                        <div class="tab-content">
                            <div role="tabpanel" class="tab-pane fade in active" id="opis">
                                <div class="itemFullText"><p>Dziecięcy separator kończyn dolnych zabezpiecza małego użytkownika.</p></div>
                            </div>
                            <div role="tabpanel" class="tab-pane fade" id="do_pobrania">
                                <div class="itemAttachments"><p><a href="/images/StabiloBED_05032025_PL-EN_katalog.pdf">Katalog STABILObed®</a></p></div>
                            </div>
                        </div>
                    </div>
                </div>
            </body>
        </html>
    HTML;
}
