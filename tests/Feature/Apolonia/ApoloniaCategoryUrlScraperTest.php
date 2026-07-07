<?php

use App\Services\Apolonia\ApoloniaCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts Apolonia target top categories and their nested categories from the IdoSell menu', function (): void {
    Http::fake([
        'https://www.apolonia.com.pl/' => Http::response(<<<'HTML'
            <html><body>
                <nav id="menu_categories">
                    <ul class="navbar-nav">
                        <li class="nav-item">
                            <span class="nav-link-wrapper">
                                <a href="/pol_m_Odziez-medyczna-241.html" title="Odzież medyczna" class="nav-link --l1">Odzież medyczna</a>
                            </span>
                            <ul class="navbar-subnav">
                                <li class="nav-item"><a href="/pol_m_Odziez-medyczna_Bluzy-medyczne-259.html" title="Bluzy medyczne" class="nav-link --l2">Bluzy medyczne</a></li>
                                <li class="nav-item"><a href="/pol_m_Odziez-medyczna_Bluzy-medyczne_Bluzy-medyczne-damskie-207.html" title="Bluzy medyczne damskie" class="nav-link --l3">Bluzy medyczne damskie</a></li>
                            </ul>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link-wrapper"><a href="/pol_m_Kolekcje_Kolekcja-Premium-415.html" class="nav-link --l1">Kolekcje</a></span>
                        </li>
                        <li class="nav-item">
                            <span class="nav-link-wrapper">
                                <a href="/pol_m_Obuwie-medyczne-230.html" title="Obuwie medyczne" class="nav-link --l1">Obuwie medyczne</a>
                            </span>
                            <ul class="navbar-subnav">
                                <li class="nav-item"><a href="/pol_m_Obuwie-medyczne_Obuwie-medyczne-damskie-193.html" title="Obuwie medyczne damskie" class="nav-link --l2">Obuwie medyczne damskie</a></li>
                                <li class="nav-item"><a href="/pol_m_Obuwie-medyczne_Obuwie-medyczne-meskie-194.html" title="Obuwie medyczne męskie" class="nav-link --l2">Obuwie medyczne męskie</a></li>
                            </ul>
                        </li>
                    </ul>
                </nav>
                <a href="/product-pol-2970-Bluza.html">Product link ignored</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ApoloniaCategoryUrlScraper::class)
        ->withMaxPages(1)
        ->scrape();

    expect($result['source'])->toBe('apolonia')
        ->and($result['product_category_urls'])->toBe([
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'https://www.apolonia.com.pl/pol_m_Obuwie-medyczne-230.html',
        ])
        ->and($result['category_urls'])->toBe([
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna_Bluzy-medyczne-259.html',
            'https://www.apolonia.com.pl/pol_m_Odziez-medyczna_Bluzy-medyczne_Bluzy-medyczne-damskie-207.html',
            'https://www.apolonia.com.pl/pol_m_Obuwie-medyczne-230.html',
            'https://www.apolonia.com.pl/pol_m_Obuwie-medyczne_Obuwie-medyczne-damskie-193.html',
            'https://www.apolonia.com.pl/pol_m_Obuwie-medyczne_Obuwie-medyczne-meskie-194.html',
        ])
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'apolonia',
        'external_category_id' => '241',
        'name' => 'Odzież medyczna',
        'source_name' => 'Odzież medyczna',
        'slug' => 'odziez-medyczna',
        'level' => 1,
        'parent_external_category_id' => null,
        'path' => ['Odzież medyczna'],
        'top_category_name' => 'Odzież medyczna',
    ]);

    expect($result['categories'][1])->toMatchArray([
        'external_category_id' => '259',
        'name' => 'Bluzy medyczne',
        'level' => 2,
        'parent_external_category_id' => '241',
        'path' => ['Odzież medyczna', 'Bluzy medyczne'],
        'top_category_name' => 'Odzież medyczna',
    ]);

    expect($result['categories'][4])->toMatchArray([
        'external_category_id' => '193',
        'name' => 'Obuwie medyczne damskie',
        'level' => 2,
        'parent_external_category_id' => '230',
        'path' => ['Obuwie medyczne', 'Obuwie medyczne damskie'],
        'top_category_name' => 'Obuwie medyczne',
    ]);
});

it('records failed Apolonia category discovery URLs', function (): void {
    Http::fake([
        'https://www.apolonia.com.pl/' => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $result = app(ApoloniaCategoryUrlScraper::class)->scrape();

    expect($result['product_category_urls'])->toBe([
        'https://www.apolonia.com.pl/pol_m_Odziez-medyczna-241.html',
        'https://www.apolonia.com.pl/pol_m_Obuwie-medyczne-230.html',
    ])
        ->and($result['visited_urls'])->toBe([
            'https://www.apolonia.com.pl/',
        ])
        ->and($result['failed_urls'])->toBe([
            'https://www.apolonia.com.pl/' => 'HTTP 500',
        ]);
});
