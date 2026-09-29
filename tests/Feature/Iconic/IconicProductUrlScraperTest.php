<?php

declare(strict_types=1);

use App\Services\Iconic\IconicProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('recursively discovers and deduplicates Iconic product URLs', function (): void {
    $root = <<<'HTML'
        <html><body>
            <nav>
                <a href="/produkty/podologia">Podologia</a>
            </nav>
            <section>
                <a href="/produkty/obuwie-darco">DARCO - Obuwie</a>
                <article>
                    <a href="/produkty/szyna-tas.html">Szyna TAS 89.00 zł</a>
                </article>
            </section>
        </body></html>
    HTML;

    $subCategory = <<<'HTML'
        <html><body>
            <section>
                <article>
                    <a href="/produkty/szyna-tas.html">Szyna TAS</a>
                    <span>89.00 zł</span>
                </article>
                <article>
                    <a href="https://sklep.iconic.pl/produkty/relief-dual.html">
                        But Pooperacyjny DARCO - Relief Dual
                    </a>
                    <span>176,00 zł</span>
                </article>
            </section>
        </body></html>
    HTML;

    $podologia = '<html><body><a href="/produkty/obuwie-darco">DARCO</a></body></html>';

    Http::fake([
        'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej' => Http::response($root),
        'https://sklep.iconic.pl/produkty/podologia' => Http::response($podologia),
        'https://sklep.iconic.pl/produkty/obuwie-darco' => Http::response($subCategory),
    ]);

    $result = app(IconicProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([
            'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej',
        ]);

    expect($result['source'])->toBe('iconic')
        ->and($result['product_count'])->toBe(2)
        ->and($result['product_urls'])->toBe([
            'https://sklep.iconic.pl/produkty/szyna-tas.html',
            'https://sklep.iconic.pl/produkty/relief-dual.html',
        ])
        ->and($result['visited_urls'])->toContain(
            'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej',
            'https://sklep.iconic.pl/produkty/obuwie-darco',
        )
        ->and($result['visited_urls'])->not->toContain(
            'https://sklep.iconic.pl/produkty/podologia',
        )
        ->and($result['products'][0]['listing_roots'])->toBe([
            'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej',
        ])
        ->and($result['products'][0]['name'])->toBe('Szyna TAS')
        ->and($result['products'][0]['price_gross_amount'])->toBe(89.0)
        ->and($result['products'][1]['price_gross_amount'])->toBe(176.0)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['stopped_at_category_limit'])->toBeFalse();
});

it('records approved root provenance when a product belongs to multiple Iconic trees', function (): void {
    Http::fake([
        'https://sklep.iconic.pl/produkty/root-a' => Http::response(
            '<main><a href="/produkty/shared.html">Shared 10.00 zł</a></main>'
        ),
        'https://sklep.iconic.pl/produkty/root-b' => Http::response(
            '<main><a href="/produkty/shared.html">Shared 10.00 zł</a></main>'
        ),
    ]);

    $result = app(IconicProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape([
            'https://sklep.iconic.pl/produkty/root-a',
            'https://sklep.iconic.pl/produkty/root-b',
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['products'][0]['listing_roots'])->toBe([
            'https://sklep.iconic.pl/produkty/root-a',
            'https://sklep.iconic.pl/produkty/root-b',
        ]);
});

it('normalizes only Iconic product and category URLs on the approved host', function (): void {
    $scraper = app(IconicProductUrlScraper::class);

    expect($scraper->normalizeProductUrl(
        '/produkty/relief-dual.html',
        'https://sklep.iconic.pl/produkty/obuwie-darco',
    ))->toBe('https://sklep.iconic.pl/produkty/relief-dual.html')
        ->and($scraper->normalizeProductUrl(
            '../../../../produkty/relief-dual.html',
            'https://sklep.iconic.pl/produkty/zaopatrzenie-ran-stopy-cukrzycowej/darco',
        ))->toBe('https://sklep.iconic.pl/produkty/relief-dual.html')
        ->and($scraper->normalizeProductUrl(
            '/produkty/../../../../produkty/relief-dual.html',
            'https://sklep.iconic.pl/produkty/podologia',
        ))->toBe('https://sklep.iconic.pl/produkty/relief-dual.html')
        ->and($scraper->normalizeProductUrl(
            'https://example.com/produkty/relief-dual.html',
        ))->toBeNull()
        ->and($scraper->normalizeProductUrl(
            'https://sklep.iconic.pl/produkty/obuwie-darco',
        ))->toBeNull()
        ->and($scraper->normalizeCategoryUrl(
            '/produkty/obuwie-darco',
            'https://sklep.iconic.pl/produkty/podologia',
        ))->toBe('https://sklep.iconic.pl/produkty/obuwie-darco')
        ->and($scraper->normalizeCategoryUrl(
            'https://sklep.iconic.pl/produkty/relief-dual.html',
        ))->toBeNull()
        ->and($scraper->normalizeCategoryUrl(
            'https://sklep.iconic.pl/',
        ))->toBeNull();
});

it('fails closed when the Iconic category traversal safety limit is reached', function (): void {
    Http::fake([
        'https://sklep.iconic.pl/produkty/podologia' => Http::response(
            '<a href="/produkty/level-two">Next</a><a href="/produkty/tool.html">Tool 35.00 zł</a>'
        ),
    ]);

    $result = app(IconicProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxCategoryPages(1)
        ->scrape([
            'https://sklep.iconic.pl/produkty/podologia',
        ]);

    expect($result['product_count'])->toBe(1)
        ->and($result['stopped_at_category_limit'])->toBeTrue()
        ->and($result['queued_category_urls_remaining'])
        ->toBe(['https://sklep.iconic.pl/produkty/level-two']);
});
