<?php

use App\Services\Neoxmed\NeoxmedCategoryUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers the seven NeoxMed catalogue pages and ignores non-catalogue links', function (): void {
    Http::fake([
        'https://neoxmed.com/' => Http::response(neoxmedHomeFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(NeoxmedCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('neoxmed')
        ->and($result['category_urls'])->toBe([
            'https://neoxmed.com/ortezy-konczyn-dolnych/',
            'https://neoxmed.com/ortezy-konczyn-gornych/',
            'https://neoxmed.com/ortezy-tulowia/',
            'https://neoxmed.com/ortezy-szyi/',
            'https://neoxmed.com/ortezy-barku/',
            'https://neoxmed.com/temblaki/',
            'https://neoxmed.com/opaski-elastyczne/',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['categories'])->toHaveCount(7)
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'external_category_id' => 'ortezy-konczyn-dolnych',
        'name' => 'Ortezy kończyn dolnych',
        'slug' => 'ortezy-konczyn-dolnych',
        'level' => 1,
        'is_product_category' => true,
        'path' => ['Ortezy kończyn dolnych'],
    ]);
});

it('normalizes NeoxMed hosts and strips query strings and fragments', function (): void {
    $scraper = app(NeoxmedCategoryUrlScraper::class);

    expect($scraper->normalizeUrl('http://www.neoxmed.com/ortezy-barku?utm_source=test#produkty'))
        ->toBe('https://neoxmed.com/ortezy-barku/')
        ->and($scraper->normalizeUrl('/temblaki/', 'https://neoxmed.com/'))
        ->toBe('https://neoxmed.com/temblaki/')
        ->and($scraper->normalizeUrl('https://example.com/temblaki/'))->toBeNull();
});

it('retries temporary NeoxMed category discovery failures', function (): void {
    Http::fake([
        'https://neoxmed.com/' => Http::sequence()
            ->push('', 503)
            ->push(neoxmedHomeFixture()),
    ]);

    $result = app(NeoxmedCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrape();

    expect($result['category_urls'])->toHaveCount(7)
        ->and($result['failed_urls'])->toBe([]);

    Http::assertSentCount(2);
});

it('runs the NeoxMed category command and saves JSON', function (): void {
    Http::fake([
        'https://neoxmed.com/' => Http::response(neoxmedHomeFixture()),
        '*' => Http::response('', 404),
    ]);

    $relativePath = 'scrapers/neoxmed/categories-test.json';
    $absolutePath = storage_path('app/'.$relativePath);
    @unlink($absolutePath);

    $exitCode = Artisan::call('neoxmed:categories', [
        '--save' => $relativePath,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Discovered category URLs: 7')
        ->and(is_file($absolutePath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR);

    expect($saved['source'])->toBe('neoxmed')
        ->and($saved['categories'])->toHaveCount(7);

    @unlink($absolutePath);
});

function neoxmedHomeFixture(): string
{
    return <<<'HTML'
        <!doctype html>
        <html lang="pl"><body>
            <nav>
                <a href="/ortezy-konczyn-dolnych/">Ortezy kończyn dolnych</a>
                <a href="https://www.neoxmed.com/ortezy-konczyn-gornych/?menu=1">Ortezy kończyn górnych</a>
                <a href="/ortezy-tulowia/">Ortezy tułowia</a>
                <a href="/ortezy-szyi/">Ortezy szyi</a>
                <a href="/ortezy-barku/">Ortezy barku</a>
                <a href="/temblaki/">Temblaki</a>
                <a href="/opaski-elastyczne/">Opaski elastyczne</a>
                <a href="/ortezy-na-wymiar/">Produkty na wymiar</a>
                <a href="/kontakt/">Kontakt</a>
            </nav>
        </body></html>
        HTML;
}
