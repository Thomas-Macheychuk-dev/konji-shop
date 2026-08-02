<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(function (): void {
    foreach (glob(storage_path('app/scrapers/novicare/runtime-*-test')) ?: [] as $path) {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    foreach (glob(storage_path('app/scrapers/novicare/runtime-links-*-test.json')) ?: [] as $path) {
        @unlink($path);
    }
});

it('pauses and resumes a Novicare dry-run runtime from its manifest', function (): void {
    $urls = [
        'https://novicare.pl/produkty/kolano/runtime-a/',
        'https://novicare.pl/produkty/kolano/runtime-b/',
    ];

    writeNovicareRuntimeDiscovery('scrapers/novicare/runtime-links-resume-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(novicareRuntimeProductFixture($urls[0], 'RT-A', 'Runtime A')),
        $urls[1] => Http::response(novicareRuntimeProductFixture($urls[1], 'RT-B', 'Runtime B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('novicare:full-catalogue', [
        '--from' => 'scrapers/novicare/runtime-links-resume-test.json',
        '--runtime-dir' => 'scrapers/novicare/runtime-resume-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Runtime paused after 1 batch(es). Resume with --resume.')
        ->assertSuccessful();

    $paused = readNovicareRuntimeManifest('scrapers/novicare/runtime-resume-test/manifest.json');

    expect($paused['status'])->toBe('paused')
        ->and($paused['next_offset'])->toBe(1)
        ->and($paused['counts']['batches_completed'])->toBe(1);

    $this->artisan('novicare:full-catalogue', [
        '--from' => 'scrapers/novicare/runtime-links-resume-test.json',
        '--runtime-dir' => 'scrapers/novicare/runtime-resume-test',
        '--batch-size' => '1',
        '--resume' => true,
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Starting offset: 1')
        ->expectsOutputToContain('Novicare full catalogue runtime finished with status: completed')
        ->assertSuccessful();

    $manifest = readNovicareRuntimeManifest('scrapers/novicare/runtime-resume-test/manifest.json');

    expect($manifest['next_offset'])->toBe(2)
        ->and($manifest['counts']['products_crawled'])->toBe(2)
        ->and($manifest['counts']['products_imported'])->toBe(0);
});

it('imports Novicare batches and writes a passing final audit', function (): void {
    $urls = [
        'https://novicare.pl/produkty/kolano/runtime-import-a/',
        'https://novicare.pl/produkty/kolano/runtime-import-b/',
    ];

    writeNovicareRuntimeDiscovery('scrapers/novicare/runtime-links-import-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(novicareRuntimeProductFixture($urls[0], 'RT-101', 'Runtime Import A')),
        $urls[1] => Http::response(novicareRuntimeProductFixture($urls[1], 'RT-102', 'Runtime Import B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('novicare:full-catalogue', [
        '--from' => 'scrapers/novicare/runtime-links-import-test.json',
        '--runtime-dir' => 'scrapers/novicare/runtime-import-test',
        '--batch-size' => '2',
        '--status' => 'active',
        '--no-images' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Batch complete: crawled 2, imported 2, crawl failures 0, import failures 0')
        ->expectsOutputToContain('Audit products: 2/2')
        ->expectsOutputToContain('Audit variants: 2/2')
        ->assertSuccessful();

    $manifest = readNovicareRuntimeManifest('scrapers/novicare/runtime-import-test/manifest.json');
    $audit = readNovicareRuntimeManifest('scrapers/novicare/runtime-import-test/audit.json');

    expect($manifest['status'])->toBe('completed')
        ->and($manifest['audit']['passed'])->toBeTrue()
        ->and($audit['passed'])->toBeTrue()
        ->and($audit['database_products'])->toBe(2)
        ->and($audit['database_variants'])->toBe(2)
        ->and(Product::query()->where('external_source', 'novicare')->count())->toBe(2);

    Product::query()
        ->where('external_source', 'novicare')
        ->get()
        ->each(function (Product $product): void {
            expect($product->status)->toBe(ProductStatus::ACTIVE)
                ->and($product->variants()->count())->toBe(1)
                ->and($product->images()->count())->toBe(0);
        });
});

it('retains the Novicare offset when a crawl batch fails', function (): void {
    $url = 'https://novicare.pl/produkty/kolano/runtime-failure/';

    writeNovicareRuntimeDiscovery('scrapers/novicare/runtime-links-failure-test.json', [$url]);

    Http::fake([
        $url => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('novicare:full-catalogue', [
        '--from' => 'scrapers/novicare/runtime-links-failure-test.json',
        '--runtime-dir' => 'scrapers/novicare/runtime-failure-test',
        '--batch-size' => '1',
        '--attempts' => '1',
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
        '--show-failures' => true,
    ])
        ->expectsOutputToContain('Batch crawl failed. The next offset was retained for a resumable retry.')
        ->expectsOutputToContain('crawl '.$url.' — HTTP 500')
        ->assertFailed();

    $manifest = readNovicareRuntimeManifest('scrapers/novicare/runtime-failure-test/manifest.json');

    expect($manifest['status'])->toBe('failed')
        ->and($manifest['next_offset'])->toBe(0)
        ->and($manifest['batches'][0]['status'])->toBe('crawl_failed')
        ->and(Product::query()->count())->toBe(0);
});

it('refuses to resume a Novicare runtime after its discovery source changes', function (): void {
    $urls = [
        'https://novicare.pl/produkty/kolano/runtime-hash-a/',
        'https://novicare.pl/produkty/kolano/runtime-hash-b/',
    ];

    writeNovicareRuntimeDiscovery('scrapers/novicare/runtime-links-hash-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(novicareRuntimeProductFixture($urls[0], 'RT-H1', 'Hash A')),
        $urls[1] => Http::response(novicareRuntimeProductFixture($urls[1], 'RT-H2', 'Hash B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('novicare:full-catalogue', [
        '--from' => 'scrapers/novicare/runtime-links-hash-test.json',
        '--runtime-dir' => 'scrapers/novicare/runtime-hash-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])->assertSuccessful();

    writeNovicareRuntimeDiscovery('scrapers/novicare/runtime-links-hash-test.json', [
        ...$urls,
        'https://novicare.pl/produkty/kolano/runtime-hash-c/',
    ]);

    expect(fn (): int => Artisan::call('novicare:full-catalogue', [
        '--from' => 'scrapers/novicare/runtime-links-hash-test.json',
        '--runtime-dir' => 'scrapers/novicare/runtime-hash-test',
        '--batch-size' => '1',
        '--resume' => true,
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]))->toThrow(
        RuntimeException::class,
        'Novicare product-link discovery JSON changed since this runtime started.',
    );
});

/**
 * @param  list<string>  $urls
 */
function writeNovicareRuntimeDiscovery(string $relativePath, array $urls): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $products = [];

    foreach ($urls as $index => $url) {
        $products[] = [
            'source' => 'novicare',
            'url' => $url,
            'external_id' => hash('sha256', $url),
            'product_code' => 'RT-'.($index + 1),
            'category' => 'Kolano',
            'category_paths' => [['Kolano']],
        ];
    }

    file_put_contents($path, json_encode([
        'source' => 'novicare',
        'product_urls' => $urls,
        'products' => $products,
        'category_results' => [
            [
                'name' => 'Kolano',
                'url' => 'https://novicare.pl/produkty/kolano/',
                'category_path' => ['Kolano'],
                'product_urls' => $urls,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function readNovicareRuntimeManifest(string $relativePath): array
{
    return json_decode(
        (string) file_get_contents(storage_path('app/'.$relativePath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function novicareRuntimeProductFixture(string $canonicalUrl, string $code, string $name): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl-PL">
            <head>
                <title>{$name} - NOVICARE</title>
                <link rel="canonical" href="{$canonicalUrl}">
                <meta name="description" content="Opis SEO {$name}">
            </head>
            <body>
                <main id="main">
                    <article>
                        <div class="entry-content single-content">
                            <a href="/produkty/kolano/"><h6>Kolano</h6></a>
                            <h2>{$name} {$code}</h2>
                            <figure class="wp-block-kadence-image">
                                <img src="/wp-content/uploads/2024/09/{$code}.webp" alt="{$name}">
                            </figure>
                            <h2>{$name} {$code}</h2>
                            <h3>Opis</h3>
                            <div class="wp-block-kadence-iconlist"><ul>
                                <li><span class="kt-svg-icon-list-text">Opis produktu testowego.</span></li>
                            </ul></div>
                            <h3>Wskazania</h3>
                            <div class="wp-block-kadence-iconlist"><ul>
                                <li><span class="kt-svg-icon-list-text">Stabilizacja stawu.</span></li>
                            </ul></div>
                            <h3>Dostępne rozmiary</h3>
                            <figure class="wp-block-table">
                                <table>
                                    <thead><tr><th>Rozmiar</th><th>UNI</th></tr></thead>
                                    <tbody><tr><td>cm</td><td>UNI</td></tr></tbody>
                                </table>
                            </figure>
                            <h3>Detale produktu</h3>
                        </div>
                    </article>
                </main>
            </body>
        </html>
    HTML;
}
