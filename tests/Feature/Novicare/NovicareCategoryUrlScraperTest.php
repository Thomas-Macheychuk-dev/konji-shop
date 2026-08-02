<?php

declare(strict_types=1);

use App\Services\Novicare\NovicareCategoryUrlScraper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('discovers the Novicare product categories from the catalogue page', function (): void {
    Http::fake([
        'https://novicare.pl/produkty/' => Http::response(novicareCategoryCatalogueFixture()),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('novicare')
        ->and($result['start_urls'])->toBe(['https://novicare.pl/produkty/'])
        ->and($result['top_categories'])->toHaveCount(10)
        ->and($result['categories'])->toHaveCount(10)
        ->and($result['category_urls'])->toBe([
            'https://novicare.pl/produkty/tulow/',
            'https://novicare.pl/produkty/nadgarstek/',
            'https://novicare.pl/produkty/stopa/',
            'https://novicare.pl/produkty/bark/',
            'https://novicare.pl/produkty/kolano/',
            'https://novicare.pl/produkty/lokiec/',
            'https://novicare.pl/produkty/szyja/',
            'https://novicare.pl/produkty/akcesoria/',
            'https://novicare.pl/produkty/poduszki/',
            'https://novicare.pl/produkty/palce/',
        ])
        ->and($result['product_category_urls'])->toBe($result['category_urls'])
        ->and($result['visited_urls'])->toBe(['https://novicare.pl/produkty/'])
        ->and($result['failed_urls'])->toBe([]);

    expect($result['categories'][0])->toMatchArray([
        'source' => 'novicare',
        'external_category_id' => 'tulow',
        'slug' => 'tulow',
        'name' => 'Tułów',
        'path' => ['Tułów'],
        'level' => 1,
        'parent_external_category_id' => null,
        'top_category_external_id' => 'tulow',
        'top_category_name' => 'Tułów',
        'has_children' => false,
        'is_product_category' => true,
    ]);
});

it('normalizes Novicare hosts and ignores product detail and external links', function (): void {
    Http::fake([
        'https://novicare.pl/produkty/' => Http::response(<<<'HTML'
            <html><body>
                <nav>
                    <a href="https://www.novicare.pl/produkty/kolano/?utm_source=test">Kolano</a>
                    <a href="/produkty/kolano/orteza-stawu-kolanowego-6155/">Product</a>
                    <a href="https://example.com/produkty/stopa/">External</a>
                </nav>
                <div class="kb-section-has-link">
                    <div><h4>Łokieć</h4></div>
                    <a class="kb-section-link-overlay" href="/produkty/lokiec/"></a>
                </div>
            </body></html>
            HTML),
        '*' => Http::response('', 404),
    ]);

    $result = app(NovicareCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(['http://www.novicare.pl/produkty/?source=custom']);

    expect($result['start_urls'])->toBe(['https://novicare.pl/produkty/'])
        ->and($result['category_urls'])->toBe([
            'https://novicare.pl/produkty/kolano/',
            'https://novicare.pl/produkty/lokiec/',
        ])
        ->and($result['categories'][1]['name'])->toBe('Łokieć');
});

it('records failed Novicare category discovery URLs after retries are exhausted', function (): void {
    Http::fake([
        'https://novicare.pl/produkty/' => Http::response('', 503),
    ]);

    $result = app(NovicareCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->withAttempts(1)
        ->scrape();

    expect($result['category_urls'])->toBe([])
        ->and($result['product_category_urls'])->toBe([])
        ->and($result['visited_urls'])->toBe(['https://novicare.pl/produkty/'])
        ->and($result['failed_urls'])->toBe([
            'https://novicare.pl/produkty/' => 'HTTP 503',
        ]);
});

it('runs the Novicare category discovery command and saves JSON', function (): void {
    Http::fake([
        'https://novicare.pl/produkty/' => Http::response(novicareCategoryCatalogueFixture()),
        '*' => Http::response('', 404),
    ]);

    $relativePath = 'scrapers/novicare/tests/categories-'.uniqid('', true).'.json';
    $absolutePath = storage_path('app/'.$relativePath);

    try {
        $exitCode = Artisan::call('novicare:categories', [
            '--save' => $relativePath,
            '--request-delay-ms' => 0,
            '--retry-delay-ms' => 0,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Discovered category URLs: 10')
            ->and($output)->toContain('Product-scraping category URLs: 10')
            ->and($output)->toContain('Saved discovery result to storage/app/'.$relativePath)
            ->and(is_file($absolutePath))->toBeTrue();

        $saved = json_decode((string) file_get_contents($absolutePath), true, 512, JSON_THROW_ON_ERROR);

        expect($saved['source'])->toBe('novicare')
            ->and($saved['categories'])->toHaveCount(10)
            ->and($saved['product_category_urls'])->toHaveCount(10);
    } finally {
        @unlink($absolutePath);
    }
});

function novicareCategoryCatalogueFixture(): string
{
    $categories = [
        ['tulow', 'Tułów'],
        ['nadgarstek', 'Nadgarstek'],
        ['stopa', 'Stopa'],
        ['bark', 'Bark'],
        ['kolano', 'Kolano'],
        ['lokiec', 'Łokieć'],
        ['szyja', 'Szyja'],
        ['akcesoria', 'Akcesoria'],
        ['poduszki', 'Poduszki'],
        ['palce', 'Palce'],
    ];

    $navigation = '';
    $cards = '';

    foreach ($categories as [$slug, $name]) {
        $navigation .= sprintf(
            '<a href="https://novicare.pl/produkty/%s/">%s</a>',
            $slug,
            htmlspecialchars($name, ENT_QUOTES),
        );
        $cards .= sprintf(
            '<div class="wp-block-kadence-column kb-section-has-link">'.
            '<div class="kt-inside-inner-col"><h4>%s</h4></div>'.
            '<a class="kb-section-link-overlay" href="/produkty/%s/"></a>'.
            '</div>',
            htmlspecialchars($name, ENT_QUOTES),
            $slug,
        );
    }

    return '<html><body><nav>'.$navigation.'</nav><main>'.$cards.
        '<a href="/produkty/kolano/orteza-stawu-kolanowego-6155/">Product</a>'.
        '<a href="https://example.com/produkty/external/">External</a>'.
        '</main></body></html>';
}
