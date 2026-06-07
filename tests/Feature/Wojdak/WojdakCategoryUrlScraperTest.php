<?php

use App\Services\Wojdak\WojdakCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('lists the hard coded Wojdak shop category URLs without making HTTP requests', function (): void {
    Http::fake();

    $result = app(WojdakCategoryUrlScraper::class)->scrape();

    expect($result['category_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-damskie/',
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-meska/',
        'https://sklep.wojdak.pl/kategoria-produktu/obuwie-medyczne/obuwie-meskie/',
    ])
        ->and($result['visited_urls'])->toBe([])
        ->and($result['failed_urls'])->toBe([]);

    Http::assertNothingSent();
});

it('normalizes custom Wojdak shop category URLs and ignores non shop category URLs', function (): void {
    $result = app(WojdakCategoryUrlScraper::class)->scrape(
        startUrls: [
            'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska',
            'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/page/2/',
            'https://wojdak.pl/produkty/odziez-medyczna-damska/',
            'https://example.com/kategoria-produktu/odziez-medyczna/odziez-damska/',
        ],
        includeHardCodedCategories: false,
    );

    expect($result['category_urls'])->toBe([
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/',
        'https://sklep.wojdak.pl/kategoria-produktu/odziez-medyczna/odziez-damska/page/2/',
    ]);
});
