<?php

use App\Services\TwojaPeruka\TwojaPerukaCategoryScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('extracts only allowed TwojaPeruka top categories and their descendants', function (): void {
    Http::fake([
        'https://twojaperuka.pl/peruki' => Http::response(twojaPerukaCategoryMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaCategoryScraper::class)->scrape();

    expect(array_column($result['top_categories'], 'name'))->toBe([
        'Zagęszczanie włosów',
        'Peruki',
        'Toppery',
        'Turbany i chusty',
    ]);

    expect($result['top_categories'][0])->toMatchArray([
        'external_category_id' => '41',
        'name' => 'Zagęszczanie włosów',
        'url' => 'https://twojaperuka.pl/pl/c/Zageszczanie-wlosow/41',
        'slug' => 'Zageszczanie-wlosow',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Zagęszczanie włosów'],
    ]);

    expect(array_column($result['top_categories'][0]['children'], 'name'))->toBe([
        'Kucyki doczepiane',
        'Włosy doczepiane typu clip in',
        'Włosy doczepiane typu flip in',
    ]);

    expect($result['top_categories'][1]['children'][0])->toMatchArray([
        'external_category_id' => '48',
        'name' => 'Peruki syntetyczne',
        'url' => 'https://twojaperuka.pl/pl/c/Peruki-syntetyczne/48',
        'level' => 2,
        'parent_external_category_id' => '13',
        'path' => ['Peruki', 'Peruki syntetyczne'],
    ]);

    expect(array_column($result['top_categories'][1]['children'][0]['children'], 'name'))->toBe([
        'Peruki Alternative Hair',
        'Peruki Flower Collection',
        'Peruki Stardust',
        'Peruki Be Unique',
        'Peruki Wyprzedaż',
    ]);

    expect($result['top_categories'][2]['children'])->toHaveCount(2)
        ->and(array_column($result['top_categories'][2]['children'], 'name'))->toBe([
            'Toppery syntetyczne',
            'Toppery naturalne',
        ])
        ->and($result['top_categories'][3]['children'])->toHaveCount(1)
        ->and($result['top_categories'][3]['children'][0]['name'])->toBe('Zodiac Headwear');

    expect(array_column($result['categories'], 'name'))
        ->not->toContain('Akcesoria')
        ->not->toContain('Zestawy')
        ->not->toContain('Serwis')
        ->not->toContain('Nowości')
        ->not->toContain('Promocje');
});

it('returns leaf category URLs for later product-link scraping', function (): void {
    Http::fake([
        'https://twojaperuka.pl/peruki' => Http::response(twojaPerukaCategoryMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaCategoryScraper::class)->scrape();

    expect($result['product_category_urls'])->toBe([
        'https://twojaperuka.pl/pl/c/Kucyki-doczepiane/42',
        'https://twojaperuka.pl/pl/c/Wlosy-doczepiane-typu-clip-in/46',
        'https://twojaperuka.pl/pl/c/Wlosy-doczepiane-typu-flip-in/47',
        'https://twojaperuka.pl/alternative',
        'https://twojaperuka.pl/flower',
        'https://twojaperuka.pl/pl/c/Peruki-Stardust/34',
        'https://twojaperuka.pl/beunique',
        'https://twojaperuka.pl/pl/c/Peruki-Wyprzedaz/38',
        'https://twojaperuka.pl/NAHNature',
        'https://twojaperuka.pl/peruki_w-odcieniach_blond_twojaperuka.pl',
        'https://twojaperuka.pl/brazowe_peruki_twoja_peruka_pl',
        'https://twojaperuka.pl/peruki_damskie_odcienie_rude',
        'https://twojaperuka.pl/pl/c/Peruki-dlugie/55',
        'https://twojaperuka.pl/pl/c/Peruki-krotkie/53',
        'https://twojaperuka.pl/pl/c/Peruki-do-ramion/54',
        'https://twojaperuka.pl/pl/c/Toppery-syntetyczne/36',
        'https://twojaperuka.pl/pl/c/Toppery-naturalne/37',
        'https://twojaperuka.pl/zodiac',
    ]);
});

it('records failed TwojaPeruka category page requests', function (): void {
    Http::fake([
        'https://twojaperuka.pl/peruki' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(TwojaPerukaCategoryScraper::class)->scrape();

    expect($result['top_categories'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://twojaperuka.pl/peruki',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://twojaperuka.pl/peruki' => 'HTTP 500',
        ]);
});

it('can print the TwojaPeruka category discovery result as JSON', function (): void {
    Http::fake([
        'https://twojaperuka.pl/peruki' => Http::response(twojaPerukaCategoryMenuFixture()),
        '*' => Http::response('', 404),
    ]);

    $exitCode = Artisan::call('twojaperuka:categories', [
        '--json' => true,
        '--request-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0);

    $output = Artisan::output();

    expect($output)
        ->toContain('"source": "twojaperuka"')
        ->toContain('"name": "Peruki"')
        ->toContain('"name": "Zagęszczanie włosów"')
        ->toContain('"name": "Toppery"')
        ->toContain('"name": "Turbany i chusty"');

    $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($decoded['top_categories'], 'name'))->toBe([
        'Zagęszczanie włosów',
        'Peruki',
        'Toppery',
        'Turbany i chusty',
    ]);
});

function twojaPerukaCategoryMenuFixture(): string
{
    return <<<'HTML'
        <html>
            <body>
                <div class="sft-sidebar-menu" id="sft-sidebar-menu-549">
                    <ul class="sft-menu sft-category-menu sft-category-menu--level-1">
                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-45">
                            <div class="sft-category-link">
                                <a href="/pl/c/Zageszczanie-wlosow/45" title="Zagęszczanie włosów"><p class="head">Zagęszczanie włosów</p></a>
                            </div>
                        </li>

                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-13">
                            <div class="sft-category-link">
                                <a href="/peruki" title="Peruki"><p class="head">Peruki</p></a>
                            </div>
                            <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-2">
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-48">
                                    <div class="sft-category-link">
                                        <a href="/pl/c/Peruki-syntetyczne/48" title="Peruki syntetyczne"><p class="head">Peruki syntetyczne</p></a>
                                    </div>
                                    <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-3">
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-16"><div class="sft-category-link"><a href="/alternative" title="Peruki Alternative Hair"><p class="head">Peruki Alternative Hair</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-17"><div class="sft-category-link"><a href="/flower" title="Peruki Flower Collection"><p class="head">Peruki Flower Collection</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-34"><div class="sft-category-link"><a href="/pl/c/Peruki-Stardust/34" title="Peruki Stardust"><p class="head">Peruki Stardust</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-18"><div class="sft-category-link"><a href="/beunique" title="Peruki Be Unique"><p class="head">Peruki Be Unique</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-38"><div class="sft-category-link"><a href="/pl/c/Peruki-Wyprzedaz/38" title="Peruki Wyprzedaż"><p class="head">Peruki Wyprzedaż</p></a></div></li>
                                    </ul>
                                </li>
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-49">
                                    <div class="sft-category-link">
                                        <a href="/peruki-naturalne-tanie-z-prawdziwych-naturalnych-wlosow" title="Peruki naturalne"><p class="head">Peruki naturalne</p></a>
                                    </div>
                                    <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-3">
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-22"><div class="sft-category-link"><a href="/NAHNature" title="Peruki NAH Nature"><p class="head">Peruki NAH Nature</p></a></div></li>
                                    </ul>
                                </li>
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-43">
                                    <div class="sft-category-link">
                                        <a href="/pl/c/Peruki-kolorowe/43" title="Peruki kolorowe"><p class="head">Peruki kolorowe</p></a>
                                    </div>
                                    <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-3">
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-44"><div class="sft-category-link"><a href="/peruki_w-odcieniach_blond_twojaperuka.pl" title="Peruki odcienie blond"><p class="head">Peruki odcienie blond</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-51"><div class="sft-category-link"><a href="/brazowe_peruki_twoja_peruka_pl" title="Peruki odcienie brązu"><p class="head">Peruki odcienie brązu</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-56"><div class="sft-category-link"><a href="/peruki_damskie_odcienie_rude" title="Peruki odcienie rude"><p class="head">Peruki odcienie rude</p></a></div></li>
                                    </ul>
                                </li>
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-52">
                                    <div class="sft-category-link">
                                        <a href="/pl/c/Peruki-dlugosc-wlosa/52" title="Peruki długość włosa"><p class="head">Peruki długość włosa</p></a>
                                    </div>
                                    <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-3">
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-55"><div class="sft-category-link"><a href="/pl/c/Peruki-dlugie/55" title="Peruki długie"><p class="head">Peruki długie</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-53"><div class="sft-category-link"><a href="/pl/c/Peruki-krotkie/53" title="Peruki krótkie"><p class="head">Peruki krótkie</p></a></div></li>
                                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-54"><div class="sft-category-link"><a href="/pl/c/Peruki-do-ramion/54" title="Peruki do ramion"><p class="head">Peruki do ramion</p></a></div></li>
                                    </ul>
                                </li>
                            </ul>
                        </li>

                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-35">
                            <div class="sft-category-link">
                                <a href="/pl/c/Toppery/35" title="Toppery"><p class="head">Toppery</p></a>
                            </div>
                            <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-2">
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-36"><div class="sft-category-link"><a href="/pl/c/Toppery-syntetyczne/36" title="Toppery syntetyczne"><p class="head">Toppery syntetyczne</p></a></div></li>
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-37"><div class="sft-category-link"><a href="/pl/c/Toppery-naturalne/37" title="Toppery naturalne"><p class="head">Toppery naturalne</p></a></div></li>
                            </ul>
                        </li>

                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-14">
                            <div class="sft-category-link">
                                <a href="/turbanyichusty" title="Turbany i chusty"><p class="head">Turbany i chusty</p></a>
                            </div>
                            <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-2">
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-19"><div class="sft-category-link"><a href="/zodiac" title="Zodiac Headwear"><p class="head">Zodiac Headwear</p></a></div></li>
                            </ul>
                        </li>

                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-41">
                            <div class="sft-category-link">
                                <a href="/pl/c/Zageszczanie-wlosow/41" title="Zagęszczanie włosów"><p class="head">Zagęszczanie włosów</p></a>
                            </div>
                            <ul class="sft-menu sft-subcategory-menu sft-category-menu--level-2">
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-42"><div class="sft-category-link"><a href="/pl/c/Kucyki-doczepiane/42" title="Kucyki doczepiane"><p class="head">Kucyki doczepiane</p></a></div></li>
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-46"><div class="sft-category-link"><a href="/pl/c/Wlosy-doczepiane-typu-clip-in/46" title="Włosy doczepiane typu clip in"><p class="head">Włosy doczepiane typu clip in</p></a></div></li>
                                <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-47"><div class="sft-category-link"><a href="/pl/c/Wlosy-doczepiane-typu-flip-in/47" title="Włosy doczepiane typu flip in"><p class="head">Włosy doczepiane typu flip in</p></a></div></li>
                            </ul>
                        </li>

                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-15">
                            <div class="sft-category-link"><a href="/akcesoria" title="Akcesoria"><p class="head">Akcesoria</p></a></div>
                        </li>
                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-50">
                            <div class="sft-category-link"><a href="/zestawy-kosmetyki-i-akcesoria-do-peruk" title="Zestawy"><p class="head">Zestawy</p></a></div>
                        </li>
                        <li class="sft-menu__item sft-category-menu__item" id="sft-category-item-23">
                            <div class="sft-category-link"><a href="/serwis" title="Serwis"><p class="head">Serwis</p></a></div>
                        </li>
                        <li class="sft-menu__item sft-category-menu__item">
                            <div class="sft-newProduct-link"><a href="/pl/new" title="Nowości"><p class="head">Nowości</p></a></div>
                        </li>
                        <li class="sft-menu__item sft-category-menu__item">
                            <div class="sft-promotion-link"><a href="/pl/promotions" title="Promocje"><p class="head">Promocje</p></a></div>
                        </li>
                    </ul>
                </div>
            </body>
        </html>
    HTML;
}
