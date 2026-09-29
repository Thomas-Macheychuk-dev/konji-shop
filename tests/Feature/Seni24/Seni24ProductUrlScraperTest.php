<?php

declare(strict_types=1);

use App\Services\Seni24\Seni24ProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('normalizes Seni24 product and category URLs without crossing hosts', function (): void {
    $scraper = app(Seni24ProductUrlScraper::class);

    expect($scraper->normalizeProductUrl(
        '/seni-super-test_24170-26340?utm_source=test#fragment',
        'https://www.seni24.pl/nietrzymanie-moczu/',
    ))->toBe('https://www.seni24.pl/seni-super-test_24170-26340')
        ->and($scraper->normalizeProductUrl(
            'https://seni24.pl/seni-super-test_24170-26340/',
        ))->toBe('https://www.seni24.pl/seni-super-test_24170-26340')
        ->and($scraper->normalizeProductUrl(
            'https://example.com/seni-super-test_24170-26340',
        ))->toBeNull()
        ->and($scraper->normalizeCategoryUrl(
            '/nietrzymanie-moczu/?page=3&order=product.price.asc',
            'https://www.seni24.pl/',
        ))->toBe('https://www.seni24.pl/nietrzymanie-moczu/?page=3')
        ->and($scraper->normalizeCategoryUrl(
            '/dofinansowanie-nfz/',
            'https://www.seni24.pl/',
        ))->toBeNull();
});

it('extracts Seni24 product links and nested category links while ignoring site chrome', function (): void {
    $html = <<<'HTML'
        <html><body>
            <header>
                <a href="/promocje/">Promocje</a>
                <a href="/header-product_999-999">Header product</a>
            </header>

            <main>
                <section class="products">
                    <article>
                        <a href="/seni-super-one_100-1000">
                            <img src="/img/one.jpg" alt="Seni Super One">
                            Seni Super One
                        </a>
                        <span>19,25 zł</span>
                    </article>

                    <article>
                        <a href="https://www.seni24.pl/seni-super-two_101-1001">
                            Seni Super Two
                        </a>
                        <span>1 234,50 zł</span>
                    </article>
                </section>

                <a href="/nietrzymanie-moczu/pieluchomajtki/">Pieluchomajtki</a>
                <a href="https://example.com/foreign/">Foreign</a>
            </main>
        </body></html>
    HTML;

    $result = app(Seni24ProductUrlScraper::class)->extractLinks(
        $html,
        'https://www.seni24.pl/nietrzymanie-moczu/',
    );

    expect($result['products'])->toHaveCount(2)
        ->and($result['products'][0]['url'])->toBe(
            'https://www.seni24.pl/seni-super-one_100-1000',
        )
        ->and($result['products'][0]['price_gross_amount'])->toBe(19.25)
        ->and($result['products'][1]['price_gross_amount'])->toBe(1234.50)
        ->and($result['category_urls'])->toContain(
            'https://www.seni24.pl/nietrzymanie-moczu/pieluchomajtki/',
        )
        ->and($result['product_urls'] ?? null)->toBeNull();
});

it('tracks Seni24 approved root provenance during recursive discovery', function (): void {
    Http::fake([
        'https://www.seni24.pl/nietrzymanie-moczu/' => Http::response(
            '<a href="/seni-one_100-1000">Seni One</a><a href="/nietrzymanie-moczu/pieluchomajtki/">Pieluchomajtki</a>',
            200,
        ),
        'https://www.seni24.pl/nietrzymanie-moczu/pieluchomajtki/' => Http::response(
            '<a href="/seni-one_100-1000">Seni One</a><a href="/seni-two_101-1001">Seni Two</a>',
            200,
        ),
    ]);

    $result = app(Seni24ProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([
            'https://www.seni24.pl/nietrzymanie-moczu/',
        ]);

    expect($result['product_count'])->toBe(2)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['stopped_at_category_limit'])->toBeFalse();

    $one = collect($result['products'])
        ->firstWhere('url', 'https://www.seni24.pl/seni-one_100-1000');

    expect($one)->not->toBeNull()
        ->and($one['listing_roots'])->toBe([
            'https://www.seni24.pl/nietrzymanie-moczu/',
        ])
        ->and($one['listing_pages'])->toContain(
            'https://www.seni24.pl/nietrzymanie-moczu/',
            'https://www.seni24.pl/nietrzymanie-moczu/pieluchomajtki/',
        );
});
