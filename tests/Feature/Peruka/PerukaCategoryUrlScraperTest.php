<?php

use App\Services\Peruka\PerukaCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers Peruka category links from the horizontal category navigation', function (): void {
    Http::fake([
        'https://www.peruka.pl/' => Http::response(<<<'HTML'
            <html><body>
                <ul class="nav navbar-nav horizontal-categories">
                    <li class="dropdown">
                        <a href="/category/peruki" class="dropdown-toggle category-link">Peruki</a>
                        <ul class="dropdown-menu">
                            <li><a href="/category/peruki-damskie" class="category-link">Peruki damskie</a></li>
                            <li><a href="/category/peruki-meskie" class="category-link">Peruki męskie</a></li>
                            <li><a href="/category/peruki-dzieciece" class="category-link">Peruki dziecięce</a></li>
                            <li><a href="/category/wyprzedaz" class="category-link">Wyprzedaż</a></li>
                        </ul>
                    </li>
                    <li class="dropdown">
                        <a href="/category/turbany" class="dropdown-toggle category-link">Turbany</a>
                        <ul class="dropdown-menu">
                            <li><a href="/category/turbany-bamboo-islands" class="category-link">BAMBOO ISLANDS</a></li>
                            <li><a href="/category/turbany-city" class="category-link">CITY</a></li>
                        </ul>
                    </li>
                    <li>
                        <a href="/category/kosmetyki" class="category-link">Kosmetyki do peruk</a>
                    </li>
                    <li><a href="/webpage/katalogi.html">Katalogi</a></li>
                    <li><a href="https://www.peruka.pl/blog">Blog</a></li>
                </ul>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(PerukaCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://www.peruka.pl/category/peruki',
        'https://www.peruka.pl/category/peruki-damskie',
        'https://www.peruka.pl/category/peruki-meskie',
        'https://www.peruka.pl/category/peruki-dzieciece',
        'https://www.peruka.pl/category/wyprzedaz',
        'https://www.peruka.pl/category/turbany',
        'https://www.peruka.pl/category/turbany-bamboo-islands',
        'https://www.peruka.pl/category/turbany-city',
        'https://www.peruka.pl/category/kosmetyki',
    ]);

    expect($result['categories'][1])->toMatchArray([
        'name' => 'Peruki damskie',
        'url' => 'https://www.peruka.pl/category/peruki-damskie',
        'parent_name' => 'Peruki',
        'parent_url' => 'https://www.peruka.pl/category/peruki',
        'level' => 1,
    ]);

    expect($result['categories'][8])->toMatchArray([
        'name' => 'Kosmetyki do peruk',
        'url' => 'https://www.peruka.pl/category/kosmetyki',
        'parent_name' => null,
        'parent_url' => null,
        'level' => 0,
    ]);

    expect($result['visited_urls'])->toBe([
        'https://www.peruka.pl/',
    ]);

    expect($result['failed_urls'])->toBe([]);
});

it('normalizes Peruka category links and ignores non-category links', function (): void {
    Http::fake([
        'https://www.peruka.pl/category/peruki-damskie' => Http::response(<<<'HTML'
            <html><body>
                <a href="https://peruka.pl/category/peruki-damskie?horizontal" class="category-link">Peruki damskie</a>
                <a href="/category/tupety-naturalne/" class="category-link">Tupety naturalne</a>
                <a href="/orbit-chocolate-mix.html" class="category-link">Product</a>
                <a href="mailto:test@example.com" class="category-link">Email</a>
                <a href="https://example.com/category/external" class="category-link">External</a>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(PerukaCategoryUrlScraper::class)->scrape([
        'https://www.peruka.pl/category/peruki-damskie?horizontal',
    ]);

    expect($result['category_urls'])->toBe([
        'https://www.peruka.pl/category/peruki-damskie',
        'https://www.peruka.pl/category/tupety-naturalne',
    ]);
});
