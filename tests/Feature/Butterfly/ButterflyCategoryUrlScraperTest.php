<?php

use App\Services\Butterfly\ButterflyCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts Butterfly categories hidden under the NASZE PRODUKTY Shoper menu', function (): void {
    Http::fake([
        'https://butterfly-mag.com' => Http::response(<<<'HTML'
            <html><body>
                <ul class="menu-list large standard">
                    <li class="home-link-menu-li"><p class="zah3"><a href="/"><span>Strona główna</span></a></p></li>
                    <li class="parent" id="hcategory_0">
                        <p class="zah3"><a href="#" title="NASZE PRODUKTY"><span>NASZE PRODUKTY</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li class="parent" id="hcategory_55">
                                <p class="zah3drop"><a href="/pl/c/Zdrowie/55"><span>Zdrowie</span></a></p>
                                <div class="submenu level2"><ul class="level2">
                                    <li id="hcategory_65"><p class="zah3drop"><a href="/pl/c/Akcesoria-biomagnetyczne/65"><span>Akcesoria biomagnetyczne</span></a></p></li>
                                </ul></div>
                            </li>
                            <li id="hcategory_18"><p class="zah3drop"><a href="/pl/c/Magnetyczne-poduszki-ortopedyczne/18"><span>Magnetyczne poduszki ortopedyczne</span></a></p></li>
                            <li class="parent" id="hcategory_19">
                                <p class="zah3drop"><a href="/pl/c/Magnetyczne-pasy-na-kregoslup/19"><span>Magnetyczne pasy na kręgosłup</span></a></p>
                                <div class="submenu level2"><ul class="level2">
                                    <li id="hcategory_20"><p class="zah3drop"><a href="/pl/c/Pasy-Euromag/20"><span>Pasy Euromag</span></a></p></li>
                                    <li id="hcategory_22"><p class="zah3drop"><a href="/pl/c/Pasy-Harmonium/22"><span>Pasy Harmonium</span></a></p></li>
                                </ul></div>
                            </li>
                            <li class="parent" id="hcategory_23">
                                <p class="zah3drop"><a href="/pl/c/Magnetyczne-opaski-na-reke/23"><span>Magnetyczne opaski na rękę</span></a></p>
                                <div class="submenu level2"><ul class="level2">
                                    <li id="hcategory_35"><p class="zah3drop"><a href="/pl/c/Opaski-na-nadgarstek/35"><span>Opaski na nadgarstek</span></a></p></li>
                                    <li id="hcategory_36"><p class="zah3drop"><a href="/pl/c/Opaski-na-lokiec/36"><span>Opaski na łokieć</span></a></p></li>
                                </ul></div>
                            </li>
                        </ul></div>
                    </li>
                    <li><p class="zah3"><a href="/pl/i/Certyfikaty-i-bezpieczenstwo/31"><span>Certyfikaty i bezpieczeństwo</span></a></p></li>
                    <li><p class="zah3"><a href="/pl/n/list"><span>Blog</span></a></p></li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyCategoryUrlScraper::class)->scrape();

    expect($result['source'])->toBe('butterfly')
        ->and($result['category_urls'])->toBe([
            'https://butterfly-mag.com/pl/c/Zdrowie/55',
            'https://butterfly-mag.com/pl/c/Akcesoria-biomagnetyczne/65',
            'https://butterfly-mag.com/pl/c/Magnetyczne-poduszki-ortopedyczne/18',
            'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19',
            'https://butterfly-mag.com/pl/c/Pasy-Euromag/20',
            'https://butterfly-mag.com/pl/c/Pasy-Harmonium/22',
            'https://butterfly-mag.com/pl/c/Magnetyczne-opaski-na-reke/23',
            'https://butterfly-mag.com/pl/c/Opaski-na-nadgarstek/35',
            'https://butterfly-mag.com/pl/c/Opaski-na-lokiec/36',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['top_categories'])->toHaveCount(4);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'butterfly',
        'external_category_id' => '55',
        'name' => 'Zdrowie',
        'source_name' => 'Zdrowie',
        'url' => 'https://butterfly-mag.com/pl/c/Zdrowie/55',
        'slug' => '55',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Zdrowie'],
    ]);

    expect($result['categories'][1])->toMatchArray([
        'external_category_id' => '65',
        'name' => 'Akcesoria biomagnetyczne',
        'level' => 2,
        'parent_external_category_id' => '55',
        'path' => ['Zdrowie', 'Akcesoria biomagnetyczne'],
    ]);
});

it('normalizes Butterfly category links and ignores external and utility links', function (): void {
    Http::fake([
        'https://butterfly-mag.com' => Http::response(<<<'HTML'
            <html><body>
                <ul class="menu-list">
                    <li class="parent" id="hcategory_0">
                        <p class="zah3"><a href="/#box_mainproducts"><span>Menu</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li id="hcategory_55"><p class="zah3drop"><a href="//www.butterfly-mag.com/pl/c/Zdrowie/55?utm=ignored"><span>Zdrowie</span></a></p></li>
                            <li><p class="zah3drop"><a href="/pl/c/Magnetyczne-pasy-na-kregoslup/19#box_mainproducts"><span>Magnetyczne pasy na kręgosłup</span></a></p></li>
                            <li><p class="zah3drop"><a href="/pl/i/Certyfikaty-i-bezpieczenstwo/31"><span>Info page</span></a></p></li>
                            <li><p class="zah3drop"><a href="/pl/p/Magnetyczna-poduszka-ortopedyczna-Ort-Butterfly/78"><span>Product</span></a></p></li>
                            <li><p class="zah3drop"><a href="https://example.com/external"><span>External</span></a></p></li>
                            <li><p class="zah3drop"><a href="mailto:test@example.com"><span>Email</span></a></p></li>
                        </ul></div>
                    </li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://butterfly-mag.com/pl/c/Zdrowie/55',
        'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19',
    ]);

    expect($result['product_category_urls'])->toBe([
        'https://butterfly-mag.com/pl/c/Zdrowie/55',
        'https://butterfly-mag.com/pl/c/Magnetyczne-pasy-na-kregoslup/19',
    ]);
});

it('records failed Butterfly category discovery URLs', function (): void {
    Http::fake([
        'https://butterfly-mag.com' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(ButterflyCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://butterfly-mag.com',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://butterfly-mag.com' => 'HTTP 500',
        ]);
});
