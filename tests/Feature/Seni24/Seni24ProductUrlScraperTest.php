<?php

declare(strict_types=1);

use App\Services\Seni24\Seni24ProductUrlScraper;

it('discovers Seni24 product URLs and catalogue pagination without following site chrome', function (): void {
    $html = <<<'HTML'
        <html><body>
            <header>
                <a href="/promo_999-9999">Promo in header</a>
            </header>
            <main>
                <article>
                    <a href="/kubek-pojnik-z-ustnikiem-200-ml_338-16501">
                        Kubek pojnik z ustnikiem 200 ml
                    </a>
                    <span>Cena 1 opak. 9,39 zł</span>
                </article>
                <article>
                    <a href="https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630">
                        Skarpety bezuciskowe z wełną merynosową DeoMed Wool
                    </a>
                    <span>20,28 zł</span>
                </article>
                <a href="/strona-glowna/?page=2&cacheables=1">2</a>
            </main>
        </body></html>
    HTML;

    $result = app(Seni24ProductUrlScraper::class)->extractLinks(
        $html,
        'https://www.seni24.pl/strona-glowna/',
        'https://www.seni24.pl/strona-glowna/',
    );

    expect($result['products'])->toHaveCount(2)
        ->and(array_column($result['products'], 'url'))->toBe([
            'https://www.seni24.pl/kubek-pojnik-z-ustnikiem-200-ml_338-16501',
            'https://www.seni24.pl/skarpety-medyczne-deomed-wool-dla-diabetykow-z-welna-merynosowa_10858-18630',
        ])
        ->and($result['pagination_urls'])->toBe([
            'https://www.seni24.pl/strona-glowna/?cacheables=1&page=2',
        ]);
});

it('rejects non-Seni24 and non-product URLs', function (): void {
    $scraper = app(Seni24ProductUrlScraper::class);

    expect($scraper->normalizeProductUrl('https://example.com/item_1-2'))->toBeNull()
        ->and($scraper->normalizeProductUrl('https://www.seni24.pl/pomoce-codzienne/'))->toBeNull()
        ->and($scraper->normalizeProductUrl('/item_12-34', 'https://www.seni24.pl/strona-glowna/'))
        ->toBe('https://www.seni24.pl/item_12-34');
});
