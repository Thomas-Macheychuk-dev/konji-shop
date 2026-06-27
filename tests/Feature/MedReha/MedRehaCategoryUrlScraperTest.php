<?php

use App\Services\MedReha\MedRehaCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts MedReha allowed category hierarchy from the Shoper menu', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/c' => Http::response(<<<'HTML'
            <html><body>
                <ul class="menu-list large standard">
                    <li class="parent" id="hcategory_0">
                        <h3><a href="#"><span>Menu</span></a></h3>
                        <div class="submenu level1">
                            <ul class="level1">
                                <li id="hcategory_188" class="parent">
                                    <h3><a href="/pl/c/ORTEZY-I-STABILIZATORY/188"><span>ORTEZY I STABILIZATORY</span></a></h3>
                                    <div class="submenu level2">
                                        <ul class="level2">
                                            <li id="hcategory_145" class="parent">
                                                <h3><a href="/pl/c/PASY-ORTOPEDYCZNE/145"><span>PASY ORTOPEDYCZNE</span></a></h3>
                                                <div class="submenu level3">
                                                    <ul class="level3">
                                                        <li id="hcategory_169"><h3><a href="/pl/c/PASY-NA-KREGOSLUP/169"><span>PASY NA KRĘGOSŁUP</span></a></h3></li>
                                                        <li id="hcategory_167"><h3><a href="/pl/c/PASY-BRZUSZNE/167?ignored=yes"><span>PASY BRZUSZNE</span></a></h3></li>
                                                    </ul>
                                                </div>
                                            </li>
                                            <li id="hcategory_148"><h3><a href="/pl/c/ORTEZY-KONCZYNY-GORNEJ/148"><span>ORTEZY KOŃCZYNY GÓRNEJ</span></a></h3></li>
                                        </ul>
                                    </div>
                                </li>
                                <li id="hcategory_189" class="parent">
                                    <h3><a href="/sprzet-rehabilitacyjny"><span>SPRZĘT REHABILITACYJNY</span></a></h3>
                                    <div class="submenu level2">
                                        <ul class="level2">
                                            <li id="hcategory_156"><h3><a href="/akcesoria-do-rehabilitacji"><span>AKCESORIA DO REHABILITACJI</span></a></h3></li>
                                        </ul>
                                    </div>
                                </li>
                                <li id="hcategory_190"><h3><a href="/sprzet-medyczny"><span>SPRZĘT MEDYCZNY</span></a></h3></li>
                                <li id="hcategory_191"><h3><a href="/sprzet-pomocniczy-dla-osob-starszych"><span>SPRZĘT POMOCNICZY DLA OSÓB STARSZYCH</span></a></h3></li>
                                <li id="hcategory_192" class="parent">
                                    <h3><a href="/sprzet-sportowy"><span>SPRZĘT SPORTOWY</span></a></h3>
                                    <div class="submenu level2">
                                        <ul class="level2">
                                            <li id="hcategory_157" class="parent">
                                                <h3><a href="/pl/c/OPASKI-SPORTOWE/157"><span>OPASKI SPORTOWE</span></a></h3>
                                                <div class="submenu level3">
                                                    <ul class="level3">
                                                        <li id="hcategory_176"><h3><a href="/pl/c/OPASKI-NA-KOLANO/176"><span>OPASKI NA KOLANO</span></a></h3></li>
                                                        <li id="hcategory_177"><h3><a href="/pl/c/OPASKI-NA-KOSTKE/177"><span>OPASKI NA KOSTKĘ</span></a></h3></li>
                                                    </ul>
                                                </div>
                                            </li>
                                        </ul>
                                    </div>
                                </li>
                                <li id="hcategory_999"><h3><a href="/pl/i/Kontakt/9"><span>Kontakt</span></a></h3></li>
                                <li id="hcategory_998"><h3><a href="/promocje"><span>Promocje</span></a></h3></li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaCategoryUrlScraper::class)->scrape();

    expect($result['source'])->toBe('medreha')
        ->and($result['category_urls'])->toBe([
            'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
            'https://sklep.medreha.pl/pl/c/PASY-ORTOPEDYCZNE/145',
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
            'https://sklep.medreha.pl/pl/c/PASY-BRZUSZNE/167',
            'https://sklep.medreha.pl/pl/c/ORTEZY-KONCZYNY-GORNEJ/148',
            'https://sklep.medreha.pl/sprzet-rehabilitacyjny',
            'https://sklep.medreha.pl/akcesoria-do-rehabilitacji',
            'https://sklep.medreha.pl/sprzet-medyczny',
            'https://sklep.medreha.pl/sprzet-pomocniczy-dla-osob-starszych',
            'https://sklep.medreha.pl/sprzet-sportowy',
            'https://sklep.medreha.pl/pl/c/OPASKI-SPORTOWE/157',
            'https://sklep.medreha.pl/pl/c/OPASKI-NA-KOLANO/176',
            'https://sklep.medreha.pl/pl/c/OPASKI-NA-KOSTKE/177',
        ])
        ->and($result['product_category_urls'])->toBe([
            'https://sklep.medreha.pl/pl/c/PASY-NA-KREGOSLUP/169',
            'https://sklep.medreha.pl/pl/c/PASY-BRZUSZNE/167',
            'https://sklep.medreha.pl/pl/c/ORTEZY-KONCZYNY-GORNEJ/148',
            'https://sklep.medreha.pl/akcesoria-do-rehabilitacji',
            'https://sklep.medreha.pl/sprzet-medyczny',
            'https://sklep.medreha.pl/sprzet-pomocniczy-dla-osob-starszych',
            'https://sklep.medreha.pl/pl/c/OPASKI-NA-KOLANO/176',
            'https://sklep.medreha.pl/pl/c/OPASKI-NA-KOSTKE/177',
        ])
        ->and($result['top_categories'])->toHaveCount(5);

    expect($result['categories'][1])->toMatchArray([
        'source' => 'medreha',
        'external_category_id' => '145',
        'name' => 'PASY ORTOPEDYCZNE',
        'source_name' => 'PASY ORTOPEDYCZNE',
        'url' => 'https://sklep.medreha.pl/pl/c/PASY-ORTOPEDYCZNE/145',
        'slug' => '145',
        'level' => 2,
        'parent_external_category_id' => '188',
        'path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE'],
    ]);

    expect($result['categories'][2])->toMatchArray([
        'external_category_id' => '169',
        'name' => 'PASY NA KRĘGOSŁUP',
        'level' => 3,
        'parent_external_category_id' => '145',
        'path' => ['ORTEZY I STABILIZATORY', 'PASY ORTOPEDYCZNE', 'PASY NA KRĘGOSŁUP'],
    ]);
});

it('normalizes MedReha category links and ignores external and utility child links', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/c' => Http::response(<<<'HTML'
            <html><body>
                <ul class="menu-list">
                    <li class="parent">
                        <h3><a href="#"><span>Menu</span></a></h3>
                        <div class="submenu level1">
                            <ul class="level1">
                                <li id="hcategory_188">
                                    <h3><a href="//sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188/?utm=ignored"><span>ORTEZY I STABILIZATORY</span></a></h3>
                                    <div class="submenu level2">
                                        <ul class="level2">
                                            <li><h3><a href="/pl/c/PASY-ORTOPEDYCZNE/145"><span>PASY ORTOPEDYCZNE</span></a></h3></li>
                                            <li><h3><a href="https://example.com/pl/c/External/1"><span>External</span></a></h3></li>
                                            <li><h3><a href="mailto:test@example.com"><span>Email</span></a></h3></li>
                                            <li><h3><a href="#"><span>Hash</span></a></h3></li>
                                        </ul>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://sklep.medreha.pl/pl/c/ORTEZY-I-STABILIZATORY/188',
        'https://sklep.medreha.pl/pl/c/PASY-ORTOPEDYCZNE/145',
    ]);

    expect($result['product_category_urls'])->toBe([
        'https://sklep.medreha.pl/pl/c/PASY-ORTOPEDYCZNE/145',
    ]);
});

it('records failed MedReha category discovery URLs', function (): void {
    Http::fake([
        'https://sklep.medreha.pl/pl/c' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(MedRehaCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://sklep.medreha.pl/pl/c',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://sklep.medreha.pl/pl/c' => 'HTTP 500',
        ]);
});
