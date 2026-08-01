<?php

declare(strict_types=1);

use App\Services\Vermeiren\VermeirenProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts Vermeiren descriptions images specifications colors options and documents', function (): void {
    $url = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupW%C3%B3zki%20manualneSubGroupSpecjalneSelectedD200%2030%C2%B0';
    $product = app(VermeirenProductScraper::class)->extract(
        vermeirenDetailedProductFixture('D200 30°'),
        $url,
        [
            'external_id' => 'vermeiren-d200',
            'name' => 'D200 30°',
            'selected_name' => 'D200 30°',
            'product_group' => 'Wózki manualne',
            'sub_group' => 'Specjalne',
            'sub_sub_group' => '',
            'category_urls' => ['https://www.vermeiren.pl/category/special'],
            'category_paths' => [['Wózki manualne', 'Specjalne']],
        ],
    );

    expect($product)->toMatchArray([
        'source' => 'vermeiren',
        'external_product_id' => 'vermeiren-d200',
        'name' => 'D200 30°',
        'selected_name' => 'D200 30°',
        'brand' => 'Vermeiren',
        'product_group' => 'Wózki manualne',
        'sub_group' => 'Specjalne',
        'category' => 'Specjalne',
        'category_paths' => [['Wózki manualne', 'Specjalne']],
        'short_description' => 'Wózek specjalny',
        'sku' => 'D200 30°',
        'price_gross_amount' => null,
        'availability' => 'unknown',
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ]);

    expect($product['description'])->toContain('Aluminiowy wózek')
        ->and($product['description_html'])->toContain('https://www.vermeiren.pl/web/web.nsf/media/detail.jpg')
        ->and($product['images'])->toHaveCount(2)
        ->and($product['images'][0])->toMatchArray([
            'url' => 'https://www.vermeiren.pl/product/picture.nsf/O/MAIN/$FILE/web_D200.jpg',
            'is_primary' => true,
        ])
        ->and($product['images'][1]['url'])->toBe('https://domino03.vermeiren.be/product/picture.nsf/SECOND/$File/D200-side.jpg')
        ->and($product['technical_specifications'])->toHaveCount(2)
        ->and($product['technical_specifications'][0])->toMatchArray([
            'key' => 'users_weight',
            'label' => 'Maksymalna waga użytkownika',
            'source_label' => 'users weight',
            'value' => '130',
        ])
        ->and($product['attributes'])->toBe([
            'Maksymalna waga użytkownika' => '130',
            'Szerokość całkowita' => '580 600',
        ])
        ->and($product['colors'])->toHaveCount(2)
        ->and($product['colors'][0])->toMatchArray([
            'type' => 'upholstery',
            'name' => 'Upholstery black nylon',
        ])
        ->and($product['options'])->toHaveCount(1)
        ->and($product['options'][0]['name'])->toBe('B02 podłokietnik długi.')
        ->and($product['documents'])->toHaveCount(4)
        ->and(array_column($product['documents'], 'type'))->toBe([
            'brochure',
            'manual',
            'certificate',
            'spare_part',
        ]);
});

it('uses the source short description when a Vermeiren page has no long description', function (): void {
    $url = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupPodp%C3%B3rki%2FPomoce%20lokomocyjneSubGroupLaskiSelectedMONICA';
    $html = <<<'HTML'
        <!DOCTYPE html>
        <html lang="pl">
        <head><title>MONICA | Vermeiren Polska</title></head>
        <body>
            <div>TO JEST WYRÓB MEDYCZNY. UŻYWAJ GO ZGODNIE Z INSTRUKCJĄ UŻYWANIA LUB ETYKIETĄ.</div>
            <span id="view:_id1:picture">
                <img src="https://www.vermeiren.pl/product/picture.nsf/O/MONICA/\$FILE/MONICA.jpg" alt="MONICA">
            </span>
            <span id="view:_id1:prodNaam"><b>MONICA</b></span>
            <span id="view:_id1:label1">Laska nieskładana</span>
        </body>
        </html>
        HTML;

    $product = app(VermeirenProductScraper::class)->extract($html, $url);

    expect($product['short_description'])->toBe('Laska nieskładana')
        ->and($product['description'])->toBe('Laska nieskładana')
        ->and($product['description_html'])->toBe('<p>Laska nieskładana</p>')
        ->and($product['warnings'])->toBe([]);
});

it('retries a transient Vermeiren product failure with TLS verification disabled', function (): void {
    $url = 'https://www.vermeiren.pl/web/web.nsf/detailproduct.xsp?CountryPLPLProductGroupSkuterySubGroupSelectedEon';

    Http::fakeSequence()
        ->push('', 503)
        ->push(vermeirenDetailedProductFixture('EON'), 200);

    $product = app(VermeirenProductScraper::class)
        ->withTlsVerification(false)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrape($url);

    expect($product['name'])->toBe('EON')
        ->and($product['failed_urls'])->toBe([])
        ->and($product['warnings'])->toBe([]);

    Http::assertSentCount(2);
});

function vermeirenDetailedProductFixture(string $name): string
{
    return <<<HTML
        <!DOCTYPE html>
        <html lang="pl">
        <head>
            <title>{$name} | Vermeiren Polska</title>
            <meta name="description" content="Vermeiren product {$name}">
        </head>
        <body>
            <div>TO JEST WYRÓB MEDYCZNY. UŻYWAJ GO ZGODNIE Z INSTRUKCJĄ UŻYWANIA LUB ETYKIETĄ.</div>
            <div class="col-md-6 column">
                <span id="view:_id1:picture">
                    <img src="https://www.vermeiren.pl/product/picture.nsf/O/MAIN/\$FILE/web_D200.jpg" alt="{$name}">
                </span>
                <div id="gallery" style="display:none">
                    <img data-image="https://domino03.vermeiren.be/product/picture.nsf/MAIN/\$File/D200.jpg" src="https://domino03.vermeiren.be/product/picture.nsf/MAIN/\$File/th_D200.jpg" alt="{$name}">
                    <img data-image="https://domino03.vermeiren.be/product/picture.nsf/SECOND/\$File/D200-side.jpg" src="https://domino03.vermeiren.be/product/picture.nsf/SECOND/\$File/th_D200-side.jpg" alt="Widok z boku">
                </div>
            </div>
            <div class="well well-sm offer">
                <h4><span id="view:_id1:prodNaam"><b>{$name}</b></span></h4>
                <meta itemprop="brand" content="Vermeiren">
                <h5><span id="view:_id1:label1">Wózek specjalny</span></h5>
                <div id="view:_id1:repeat5">
                    <div class="xspInputFieldRichText">
                        <p>Aluminiowy wózek ze stabilizacją głowy i pleców.</p>
                        <img src="/web/web.nsf/media/detail.jpg" alt="Detal">
                    </div>
                </div>
            </div>
            <div><h5>Kolory tapicerki</h5><img src="https://www.vermeiren.pl/product/colors.nsf/O/BLACK/\$FILE/Upholstery black nylon.jpg"></div>
            <div><h5>Kolory ramy</h5><img src="https://www.vermeiren.pl/product/colors.nsf/O/C86/\$FILE/C86 carbon grey.jpg"></div>
            <div id="techn">
                <img src="https://www.vermeiren.pl/product/technicaldetails.nsf/O/WEIGHT/\$FILE/wheelchair users weight.jpg">
                <img src="https://www.vermeiren.pl/product/technicaldetails.nsf/O/WIDTH/\$FILE/wheelchair total width.jpg">
                <div id="view:_id1:repeat3:0:inputRichText1"><p>130</p></div>
                <div id="view:_id1:repeat3:1:inputRichText1"><p>580</p><p>600</p></div>
            </div>
            <div id="doc_brochures"><a href="https://www.vermeiren.pl/product/brochure.nsf/O/ONE/\$FILE/D200.pdf">D200.pdf</a></div>
            <div id="doc_gebr"><a href="/product/manuals.nsf/O/TWO/\$FILE/User-manual.pdf">User manual</a></div>
            <div id="doc_cert"><a href="https://www.vermeiren.pl/product/certificate.nsf/O/THREE/\$FILE/CE-D200.pdf">CE D200</a></div>
            <div id="doc_ond"><a href="https://www.vermeiren.pl/product/spareparts.nsf/O/FOUR/\$FILE/D200-frame.pdf">D200 frame</a></div>
            <div id="opt">
                <div class="well well-sm offer">
                    <img src="https://www.vermeiren.pl/product/picture.nsf/O/B02/\$FILE/th_B02.jpg">
                    <span data-original="https://www.vermeiren.pl/product/picture.nsf/O/B02/\$FILE/B02.jpg"></span>
                    <div>B02 podłokietnik długi.</div>
                </div>
            </div>
        </body>
        </html>
        HTML;
}
