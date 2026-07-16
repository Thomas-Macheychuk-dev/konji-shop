<?php

use App\Services\Vaya\VayaCategoryUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers only the selected Vaya category trees and returns leaf product categories', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/' => Http::response(vayaCategoryNavigationFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaCategoryUrlScraper::class)->scrape();

    expect($result['source'])->toBe('vaya')
        ->and($result['top_categories'])->toHaveCount(4)
        ->and($result['categories'])->toHaveCount(16)
        ->and($result['category_urls'])->toHaveCount(16)
        ->and($result['product_category_urls'])->toBe([
            'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
            'https://www.vaya.com.pl/pl/c/Wkladki-na-modzele/132',
            'https://www.vaya.com.pl/pl/c/Kompresy-wlokninowe/165',
            'https://www.vaya.com.pl/pl/c/Kompresy-gazowe/166',
            'https://www.vaya.com.pl/pl/c/Kremy-i-preparaty/134',
            'https://www.vaya.com.pl/pl/c/Niebieskie/140',
            'https://www.vaya.com.pl/pl/c/Bezbarwne/138',
            'https://www.vaya.com.pl/preparaty-kosmetyki-scholl',
            'https://www.vaya.com.pl/kremy-dezodorant-scholl',
            'https://www.vaya.com.pl/wkladki-scholl',
        ])
        ->and($result['visited_urls'])->toBe([
            'https://www.vaya.com.pl/',
        ])
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'external_category_id' => '14',
        'name' => 'Wkładki ortopedyczne',
        'url' => 'https://www.vaya.com.pl/wkladki-medyczne-do-butow',
        'level' => 1,
        'parent_external_category_id' => null,
        'top_category_external_id' => '14',
        'path' => ['Wkładki ortopedyczne'],
        'has_children' => true,
        'is_product_category' => false,
    ]);

    expect($result['categories'][5])->toMatchArray([
        'external_category_id' => '155',
        'name' => 'Kompresy jałowe',
        'level' => 3,
        'parent_external_category_id' => '96',
        'top_category_external_id' => '122',
        'path' => ['Produkty Medyczne', 'Produkty opatrunkowe', 'Kompresy jałowe'],
        'has_children' => true,
        'is_product_category' => false,
    ]);

    expect($result['categories'][6])->toMatchArray([
        'external_category_id' => '165',
        'name' => 'Kompresy włókninowe',
        'slug' => 'Kompresy-wlokninowe',
        'level' => 4,
        'parent_external_category_id' => '155',
        'top_category_name' => 'Produkty Medyczne',
        'path' => ['Produkty Medyczne', 'Produkty opatrunkowe', 'Kompresy jałowe', 'Kompresy włókninowe'],
        'is_product_category' => true,
    ]);

    expect($result['category_urls'])->not->toContain('https://www.vaya.com.pl/pl/c/Durex/39');
});

it('normalizes Vaya hosts query strings and custom category paths', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133' => Http::response(<<<'HTML'
            <html><body>
                <ul>
                    <li class="parent" id="hcategory_14">
                        <h3><a id="headercategory14" title="Wkładki ortopedyczne" href="https://vaya.com.pl/wkladki-medyczne-do-butow/?menu=1">Wkładki ortopedyczne</a></h3>
                        <div class="submenu level2"><ul>
                            <li id="hcategory_133"><h3><a id="headercategory133" title="Wkładki na bunionette" href="//www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133/?horizontal=1">Wkładki na bunionette</a></h3></li>
                        </ul></div>
                    </li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaCategoryUrlScraper::class)->scrape([
        'https://vaya.com.pl/pl/c/Wkladki-na-bunionette/133?horizontal=1',
    ]);

    expect($result['start_urls'])->toBe([
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
    ])->and($result['category_urls'])->toBe([
        'https://www.vaya.com.pl/wkladki-medyczne-do-butow',
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
    ])->and($result['product_category_urls'])->toBe([
        'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
    ]);
});

it('records failed Vaya category discovery URLs', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(VayaCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe([
            'https://www.vaya.com.pl/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.vaya.com.pl/' => 'HTTP 500',
        ]);
});

it('runs the Vaya category discovery command and saves its JSON result', function (): void {
    Http::fake([
        'https://www.vaya.com.pl/' => Http::response(vayaCategoryNavigationFixture()),
        '*' => Http::response('', 404),
    ]);

    $relativePath = 'scrapers/vaya/categories-test.json';
    $absolutePath = storage_path('app/'.$relativePath);

    @unlink($absolutePath);

    $exitCode = Artisan::call('vaya:categories', [
        '--save' => $relativePath,
        '--request-delay-ms' => '0',
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Target top categories: 4')
        ->and($output)->toContain('Discovered category URLs: 16')
        ->and($output)->toContain('Product-scraping category URLs: 10')
        ->and($output)->toContain('Saved discovery result to storage/app/'.$relativePath)
        ->and(is_file($absolutePath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('vaya')
        ->and($saved['top_categories'])->toHaveCount(4)
        ->and($saved['product_category_urls'])->toHaveCount(10);

    @unlink($absolutePath);
});

function vayaCategoryNavigationFixture(): string
{
    return <<<'HTML'
        <html><body>
            <ul class="menu-list">
                <li class="parent" id="hcategory_14">
                    <h3><a href="/wkladki-medyczne-do-butow" title="Wkładki ortopedyczne" id="headercategory14"><span>Wkładki ortopedyczne</span></a></h3>
                    <div class="submenu level2"><ul class="level2">
                        <li id="hcategory_133"><h3><a href="/pl/c/Wkladki-na-bunionette/133" title="Wkładki na bunionette" id="headercategory133"><span>Wkładki na bunionette</span></a></h3></li>
                        <li id="hcategory_132"><h3><a href="/pl/c/Wkladki-na-modzele/132" title="Wkładki na modzele" id="headercategory132"><span>Wkładki na modzele</span></a></h3></li>
                    </ul></div>
                </li>
                <li class="parent" id="hcategory_122">
                    <h3><a href="/pl/c/Produkty-Medyczne/122" title="Produkty Medyczne" id="headercategory122"><span>Produkty Medyczne</span></a></h3>
                    <div class="submenu level2"><ul class="level2">
                        <li class="parent" id="hcategory_96">
                            <h3><a href="/pl/c/Produkty-opatrunkowe/96" title="Produkty opatrunkowe" id="headercategory96"><span>Produkty opatrunkowe</span></a></h3>
                            <div class="submenu level3"><ul class="level3">
                                <li class="parent" id="hcategory_155">
                                    <h3><a href="/pl/c/Kompresy-jalowe/155" title="Kompresy jałowe" id="headercategory155"><span>Kompresy jałowe</span></a></h3>
                                    <div class="submenu level4"><ul class="level4">
                                        <li id="hcategory_165"><h3><a href="/pl/c/Kompresy-wlokninowe/165" title="Kompresy włókninowe" id="headercategory165"><span>Kompresy włókninowe</span></a></h3></li>
                                        <li id="hcategory_166"><h3><a href="/pl/c/Kompresy-gazowe/166" title="Kompresy gazowe" id="headercategory166"><span>Kompresy gazowe</span></a></h3></li>
                                    </ul></div>
                                </li>
                            </ul></div>
                        </li>
                        <li id="hcategory_134"><h3><a href="/pl/c/Kremy-i-preparaty/134" title="Kremy i preparaty" id="headercategory134"><span>Kremy i preparaty</span></a></h3></li>
                    </ul></div>
                </li>
                <li class="parent" id="hcategory_125">
                    <h3><a href="/pl/c/Kompresy-zelowe/125" title="Kompresy żelowe" id="headercategory125"><span>Kompresy żelowe</span></a></h3>
                    <div class="submenu level2"><ul class="level2">
                        <li id="hcategory_140"><h3><a href="/pl/c/Niebieskie/140" title="Niebieskie" id="headercategory140"><span>Niebieskie</span></a></h3></li>
                        <li id="hcategory_138"><h3><a href="/pl/c/Bezbarwne/138" title="Bezbarwne" id="headercategory138"><span>Bezbarwne</span></a></h3></li>
                    </ul></div>
                </li>
                <li class="parent" id="hcategory_38">
                    <h3><a href="/produkty-scholl" title="Scholl" id="headercategory38"><span>Scholl</span></a></h3>
                    <div class="submenu level2"><ul class="level2">
                        <li id="hcategory_40"><h3><a href="/preparaty-kosmetyki-scholl" title="Aplikatory, preparaty, akcesoria i kosmetyki" id="headercategory40"><span>Aplikatory, preparaty, akcesoria i kosmetyki</span></a></h3></li>
                        <li id="hcategory_41"><h3><a href="/kremy-dezodorant-scholl" title="Kremy i dezodoranty" id="headercategory41"><span>Kremy i dezodoranty</span></a></h3></li>
                        <li id="hcategory_42"><h3><a href="/wkladki-scholl" title="Wkładki scholl" id="headercategory42"><span>Wkładki scholl</span></a></h3></li>
                    </ul></div>
                </li>
                <li id="hcategory_39"><h3><a href="/pl/c/Durex/39" title="Durex" id="headercategory39"><span>Durex</span></a></h3></li>
            </ul>
        </body></html>
        HTML;
}
