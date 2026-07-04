<?php

use App\Services\RelaxSan\RelaxSanCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts RelaxSan allowed category hierarchy from the Shoper menu', function (): void {
    Http::fake([
        'https://relaxsansklep.pl' => Http::response(<<<'HTML'
            <html><body>
                <ul class="menu-list large standard">
                    <li class="home-link-menu-li"><p class="h3"><a href="/"><span>Strona główna</span></a></p></li>
                    <li class="parent" id="hcategory_201">
                        <p class="h3"><a href="/wyroby-przeciwzylakowe"><span>Przeciwżylakowe</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li class="parent" id="hcategory_209">
                                <p class="h3"><a href="/podkolanowki-uciskowe"><span>Podkolanówki uciskowe</span></a></p>
                                <div class="submenu level2"><ul class="level2">
                                    <li id="hcategory_248"><p class="h3"><a href="/podkolanowki-uciskowe-profilaktyczne"><span>Podkolanówki uciskowe profilaktyczne</span></a></p></li>
                                    <li id="hcategory_212"><p class="h3"><a href="/podkolanowki-uciskowe-1-stopnia"><span>Podkolanówki uciskowe 1 stopnia</span></a></p></li>
                                </ul></div>
                            </li>
                            <li id="hcategory_232"><p class="h3"><a href="/skarpety-kompresyjne"><span>Skarpety kompresyjne</span></a></p></li>
                        </ul></div>
                    </li>
                    <li class="parent" id="hcategory_202">
                        <p class="h3"><a href="/wyroby-przeciwzakrzepowe"><span>Przeciwzakrzepowe</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li id="hcategory_217"><p class="h3"><a href="/podkolanowki-przeciwzakrzepowe"><span>Podkolanówki przeciwzakrzepowe</span></a></p></li>
                        </ul></div>
                    </li>
                    <li class="parent" id="hcategory_203">
                        <p class="h3"><a href="/bielizna"><span>Bielizna</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li class="parent" id="hcategory_254">
                                <p class="h3"><a href="/bielizna-termoaktywna"><span>Bielizna termoaktywna</span></a></p>
                                <div class="submenu level2"><ul class="level2">
                                    <li id="hcategory_255"><p class="h3"><a href="/bielizna-termoaktywna-damska"><span>Damska</span></a></p></li>
                                    <li id="hcategory_256"><p class="h3"><a href="/bielizna-termoaktywna-meska"><span>Męska</span></a></p></li>
                                </ul></div>
                            </li>
                        </ul></div>
                    </li>
                    <li class="parent" id="hcategory_156">
                        <p class="h3"><a href="/w-ciazy"><span>W ciąży</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li id="hcategory_158"><p class="h3"><a href="/bielizna-ciazowa"><span>Bielizna ciążowa</span></a></p></li>
                        </ul></div>
                    </li>
                    <li class="parent" id="hcategory_206">
                        <p class="h3"><a href="/dla-diabetykow"><span>Dla diabetyków</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li id="hcategory_233"><p class="h3"><a href="/skarpety-bezuciskowe"><span>Skarpety bezuciskowe</span></a></p></li>
                        </ul></div>
                    </li>
                    <li class="parent" id="hcategory_204">
                        <p class="h3"><a href="/produkty-ortopedyczne"><span>Ortopedyczne</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li id="hcategory_238"><p class="h3"><a href="/rekawy-uciskowe"><span>Rękawy kompresyjne</span></a></p></li>
                            <li id="hcategory_237"><p class="h3"><a href="/poduszki-ortopedyczne"><span>Poduszki ortopedyczne</span></a></p></li>
                        </ul></div>
                    </li>
                    <li><p class="h3"><a href="/pl/promotions"><span>Promocje</span></a></p></li>
                    <li><p class="h3"><a href="/pl/n/list"><span>Blog</span></a></p></li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanCategoryUrlScraper::class)->scrape();

    expect($result['source'])->toBe('relaxsan')
        ->and($result['category_urls'])->toBe([
            'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
            'https://relaxsansklep.pl/podkolanowki-uciskowe',
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
            'https://relaxsansklep.pl/podkolanowki-uciskowe-1-stopnia',
            'https://relaxsansklep.pl/skarpety-kompresyjne',
            'https://relaxsansklep.pl/wyroby-przeciwzakrzepowe',
            'https://relaxsansklep.pl/podkolanowki-przeciwzakrzepowe',
            'https://relaxsansklep.pl/bielizna',
            'https://relaxsansklep.pl/bielizna-termoaktywna',
            'https://relaxsansklep.pl/bielizna-termoaktywna-damska',
            'https://relaxsansklep.pl/bielizna-termoaktywna-meska',
            'https://relaxsansklep.pl/w-ciazy',
            'https://relaxsansklep.pl/bielizna-ciazowa',
            'https://relaxsansklep.pl/dla-diabetykow',
            'https://relaxsansklep.pl/skarpety-bezuciskowe',
            'https://relaxsansklep.pl/produkty-ortopedyczne',
            'https://relaxsansklep.pl/rekawy-uciskowe',
            'https://relaxsansklep.pl/poduszki-ortopedyczne',
        ])
        ->and($result['product_category_urls'])->toBe([
            'https://relaxsansklep.pl/podkolanowki-uciskowe-profilaktyczne',
            'https://relaxsansklep.pl/podkolanowki-uciskowe-1-stopnia',
            'https://relaxsansklep.pl/skarpety-kompresyjne',
            'https://relaxsansklep.pl/podkolanowki-przeciwzakrzepowe',
            'https://relaxsansklep.pl/bielizna-termoaktywna-damska',
            'https://relaxsansklep.pl/bielizna-termoaktywna-meska',
            'https://relaxsansklep.pl/bielizna-ciazowa',
            'https://relaxsansklep.pl/skarpety-bezuciskowe',
            'https://relaxsansklep.pl/rekawy-uciskowe',
            'https://relaxsansklep.pl/poduszki-ortopedyczne',
        ])
        ->and($result['top_categories'])->toHaveCount(6);

    expect($result['categories'][1])->toMatchArray([
        'source' => 'relaxsan',
        'external_category_id' => '209',
        'name' => 'Podkolanówki uciskowe',
        'source_name' => 'Podkolanówki uciskowe',
        'url' => 'https://relaxsansklep.pl/podkolanowki-uciskowe',
        'slug' => 'podkolanowki-uciskowe',
        'level' => 2,
        'parent_external_category_id' => '201',
        'path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe'],
    ]);

    expect($result['categories'][2])->toMatchArray([
        'external_category_id' => '248',
        'name' => 'Podkolanówki uciskowe profilaktyczne',
        'level' => 3,
        'parent_external_category_id' => '209',
        'path' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
    ]);
});

it('normalizes RelaxSan category links and ignores external and utility child links', function (): void {
    Http::fake([
        'https://relaxsansklep.pl' => Http::response(<<<'HTML'
            <html><body>
                <ul class="menu-list">
                    <li class="parent" id="hcategory_201">
                        <p class="h3"><a href="//www.relaxsansklep.pl/wyroby-przeciwzylakowe?utm=ignored"><span>Przeciwżylakowe</span></a></p>
                        <div class="submenu level1"><ul class="level1">
                            <li><p class="h3"><a href="/podkolanowki-uciskowe?ignored=yes"><span>Podkolanówki uciskowe</span></a></p></li>
                            <li><p class="h3"><a href="https://example.com/external"><span>External</span></a></p></li>
                            <li><p class="h3"><a href="mailto:test@example.com"><span>Email</span></a></p></li>
                            <li><p class="h3"><a href="#"><span>Hash</span></a></p></li>
                            <li><p class="h3"><a href="/pl/i/Kontakt/15"><span>Kontakt</span></a></p></li>
                            <li><p class="h3"><a href="/pl/promotions"><span>Promocje</span></a></p></li>
                        </ul></div>
                    </li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://relaxsansklep.pl/wyroby-przeciwzylakowe',
        'https://relaxsansklep.pl/podkolanowki-uciskowe',
    ]);

    expect($result['product_category_urls'])->toBe([
        'https://relaxsansklep.pl/podkolanowki-uciskowe',
    ]);
});

it('records failed RelaxSan category discovery URLs', function (): void {
    Http::fake([
        'https://relaxsansklep.pl' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(RelaxSanCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://relaxsansklep.pl',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://relaxsansklep.pl' => 'HTTP 500',
        ]);
});
