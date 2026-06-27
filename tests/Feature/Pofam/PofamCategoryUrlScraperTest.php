<?php

use App\Services\Pofam\PofamCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Pofam top-level category links from the Comarch category navigation', function (): void {
    Http::fake([
        'https://sklep.pofam.pl/' => Http::response(<<<'HTML'
            <html><body>
                <div class="category-content-ui">
                    <div class="sliding-categories-ui sliding-categories-lq">
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/artykuly-chlonne,2,124">
                                <span class="category-name-ui">Artykuły chłonne</span>
                                <small class="category-amount-ui">(20)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/dla-amazonek,2,128">
                                <span class="category-name-ui">Dla Amazonek</span>
                                <small class="category-amount-ui">(16)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/dla-personelu-medycznego,2,125">
                                <span class="category-name-ui">Dla personelu medycznego</span>
                                <small class="category-amount-ui">(6)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/dla-placowek-medycznych,2,122">
                                <span class="category-name-ui">Dla placówek medycznych</span>
                                <small class="category-amount-ui">(7)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/drobny-sprzet-medyczny,2,126">
                                <span class="category-name-ui">Drobny sprzęt medyczny</span>
                                <small class="category-amount-ui">(12)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/obuwie,2,138">
                                <span class="category-name-ui">Obuwie</span>
                                <small class="category-amount-ui">(16)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/ortopedia-i-rehabilitacja,2,131">
                                <span class="category-name-ui">Ortopedia i rehabilitacja</span>
                                <small class="category-amount-ui">(56)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/otolaryngologia,2,120">
                                <span class="category-name-ui">Otolaryngologia</span>
                                <small class="category-amount-ui">(5)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/sprzet-przeciwodlezynowy,2,96648">
                                <span class="category-name-ui">Sprzęt przeciwodleżynowy</span>
                                <small class="category-amount-ui">(9)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/sprzet-sanitarny,2,96657">
                                <span class="category-name-ui">Sprzęt sanitarny</span>
                                <small class="category-amount-ui">(4)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/stomia,2,87113">
                                <span class="category-name-ui">Stomia</span>
                                <small class="category-amount-ui">(27)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/pulmonologia,2,123">
                                <span class="category-name-ui">Tlenoterapia</span>
                                <small class="category-amount-ui">(3)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/urologia,2,129">
                                <span class="category-name-ui">Urologia</span>
                                <small class="category-amount-ui">(14)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq has-nodes-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/wyroby-medyczne,2,130">
                                <span class="category-name-ui">Wyroby medyczne</span>
                                <small class="category-amount-ui">(21)</small>
                            </a>
                        </div>
                        <div class="category-links-ui clear-after-ui first-level-category-lq">
                            <a class="category-label-ui inline-flex-ui vertically-centered-ui" href="produkty/zywienie-w-chorobie,2,96635">
                                <span class="category-name-ui">Żywienie w chorobie</span>
                                <small class="category-amount-ui">(3)</small>
                            </a>
                        </div>
                    </div>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(PofamCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://sklep.pofam.pl/produkty/artykuly-chlonne,2,124',
        'https://sklep.pofam.pl/produkty/dla-amazonek,2,128',
        'https://sklep.pofam.pl/produkty/dla-personelu-medycznego,2,125',
        'https://sklep.pofam.pl/produkty/dla-placowek-medycznych,2,122',
        'https://sklep.pofam.pl/produkty/drobny-sprzet-medyczny,2,126',
        'https://sklep.pofam.pl/produkty/obuwie,2,138',
        'https://sklep.pofam.pl/produkty/ortopedia-i-rehabilitacja,2,131',
        'https://sklep.pofam.pl/produkty/otolaryngologia,2,120',
        'https://sklep.pofam.pl/produkty/sprzet-przeciwodlezynowy,2,96648',
        'https://sklep.pofam.pl/produkty/sprzet-sanitarny,2,96657',
        'https://sklep.pofam.pl/produkty/stomia,2,87113',
        'https://sklep.pofam.pl/produkty/pulmonologia,2,123',
        'https://sklep.pofam.pl/produkty/urologia,2,129',
        'https://sklep.pofam.pl/produkty/wyroby-medyczne,2,130',
        'https://sklep.pofam.pl/produkty/zywienie-w-chorobie,2,96635',
    ]);

    expect($result['categories'][0])->toMatchArray([
        'name' => 'Artykuły chłonne',
        'url' => 'https://sklep.pofam.pl/produkty/artykuly-chlonne,2,124',
        'count' => 20,
        'parent_name' => null,
        'parent_url' => null,
        'level' => 0,
    ]);

    expect($result['categories'][11])->toMatchArray([
        'name' => 'Tlenoterapia',
        'url' => 'https://sklep.pofam.pl/produkty/pulmonologia,2,123',
        'count' => 3,
    ]);

    expect($result['visited_urls'])->toBe([
        'https://sklep.pofam.pl/',
    ]);

    expect($result['failed_urls'])->toBe([]);
});

it('normalizes Pofam category links and ignores product, external and utility links', function (): void {
    Http::fake([
        'https://sklep.pofam.pl/produkty/obuwie,2,138' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://sklep.pofam.pl/produkty/obuwie,2,138?horizontal" class="category-label-ui">Obuwie (16)</a>
                <a href="//sklep.pofam.pl/produkty/urologia,2,129/" class="category-label-ui">Urologia</a>
                <a href="/ice-power-sport-spray-125ml-op-6-szt,3,130,12387" class="category-label-ui">Product</a>
                <a href="produkty,2" class="category-label-ui">Generic products page</a>
                <a href="mailto:test@example.com" class="category-label-ui">Email</a>
                <a href="https://example.com/produkty/obuwie,2,138" class="category-label-ui">External</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(PofamCategoryUrlScraper::class)->scrape([
        'https://sklep.pofam.pl/produkty/obuwie,2,138?horizontal',
    ]);

    expect($result['category_urls'])->toBe([
        'https://sklep.pofam.pl/produkty/obuwie,2,138',
        'https://sklep.pofam.pl/produkty/urologia,2,129',
    ]);

    expect($result['categories'][0])->toMatchArray([
        'name' => 'Obuwie',
        'count' => 16,
    ]);
});

it('records failed Pofam category discovery URLs', function (): void {
    Http::fake([
        'https://sklep.pofam.pl/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(PofamCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://sklep.pofam.pl/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://sklep.pofam.pl/' => 'HTTP 500',
        ]);
});
