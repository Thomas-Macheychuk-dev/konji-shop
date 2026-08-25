<?php

declare(strict_types=1);

use App\Services\Armedical\ArmedicalCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers ARmedical catalogue categories without following non-category links', function (): void {
    Http::fake([
        'https://armedical.pl/katalog/' => Http::response(<<<'HTML'
            <html><body>
                <main>
                    <a href="/kategoria-produktow/produkty-ortopedyczne/">Produkty ortopedyczne</a>
                    <a href="/kategoria-produktow/produkty-rehabilitacyjne/">Produkty rehabilitacyjne</a>
                    <a href="/kategoria-produktow/srodki-pomocnicze/">Środki pomocnicze</a>
                    <a href="/kategoria-produktow/produkty-medyczne/">Produkty medyczne</a>
                    <a href="/kategoria-produktow/lokiec/">Łokieć</a>
                    <a href="/kategoria-produktow/staw-kolanowy/">Staw kolanowy</a>
                    <a href="/oferta/balkonik-aluminiowy/">Nie jest kategorią</a>
                    <a href="https://example.org/external">External</a>
                </main>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(ArmedicalCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxPages(1)
        ->scrape();

    expect($result['source'])->toBe('armedical')
        ->and($result['top_categories'])->toHaveCount(4)
        ->and($result['category_urls'])->toBe([
            'https://armedical.pl/kategoria-produktow/produkty-ortopedyczne/',
            'https://armedical.pl/kategoria-produktow/produkty-rehabilitacyjne/',
            'https://armedical.pl/kategoria-produktow/srodki-pomocnicze/',
            'https://armedical.pl/kategoria-produktow/produkty-medyczne/',
            'https://armedical.pl/kategoria-produktow/lokiec/',
            'https://armedical.pl/kategoria-produktow/staw-kolanowy/',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['visited_urls'])->toBe(['https://armedical.pl/katalog/'])
        ->and($result['failed_urls'])->toBe([])
        ->and($result['stopped_early'])->toBeTrue();

    expect($result['top_categories'][0])->toMatchArray([
        'external_category_id' => 'produkty-ortopedyczne',
        'name' => 'Produkty ortopedyczne',
        'level' => 1,
        'is_product_category' => true,
    ]);
});
