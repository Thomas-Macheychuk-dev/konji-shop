<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

afterEach(function (): void {
    foreach (glob(storage_path('app/scrapers/microlife/runtime-*-test')) ?: [] as $path) {
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    foreach (glob(storage_path('app/scrapers/microlife/runtime-links-*-test.json')) ?: [] as $path) {
        @unlink($path);
    }

    foreach (glob(storage_path('logs/microlife/full-catalogue-*.jsonl')) ?: [] as $path) {
        @unlink($path);
    }
});

it('pauses and resumes a Microlife dry-run runtime from its manifest', function (): void {
    $urls = [
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-a',
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-b',
    ];

    writeMicrolifeRuntimeDiscovery('scrapers/microlife/runtime-links-resume-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(microlifeRuntimeProductFixture($urls[0], 'RT-A', 'Runtime A')),
        $urls[1] => Http::response(microlifeRuntimeProductFixture($urls[1], 'RT-B', 'Runtime B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('microlife:full-catalogue', [
        '--from' => 'scrapers/microlife/runtime-links-resume-test.json',
        '--runtime-dir' => 'scrapers/microlife/runtime-resume-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Runtime paused after 1 batch(es). Resume with --resume.')
        ->assertSuccessful();

    $paused = readMicrolifeRuntimeManifest('scrapers/microlife/runtime-resume-test/manifest.json');

    expect($paused['status'])->toBe('paused')
        ->and($paused['next_offset'])->toBe(1)
        ->and($paused['counts']['batches_completed'])->toBe(1);

    $this->artisan('microlife:full-catalogue', [
        '--from' => 'scrapers/microlife/runtime-links-resume-test.json',
        '--runtime-dir' => 'scrapers/microlife/runtime-resume-test',
        '--batch-size' => '1',
        '--resume' => true,
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Starting offset: 1')
        ->expectsOutputToContain('Microlife full catalogue runtime finished with status: completed')
        ->assertSuccessful();

    $manifest = readMicrolifeRuntimeManifest('scrapers/microlife/runtime-resume-test/manifest.json');

    expect($manifest['next_offset'])->toBe(2)
        ->and($manifest['counts']['products_crawled'])->toBe(2)
        ->and($manifest['counts']['products_imported'])->toBe(0)
        ->and($manifest['counts']['import_warnings'])->toBe(0);
});

it('imports Microlife batches and writes a passing final audit', function (): void {
    $urls = [
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-import-a',
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-import-b',
    ];

    writeMicrolifeRuntimeDiscovery('scrapers/microlife/runtime-links-import-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(microlifeRuntimeProductFixture($urls[0], 'RT-101', 'Runtime Import A')),
        $urls[1] => Http::response(microlifeRuntimeProductFixture($urls[1], 'RT-102', 'Runtime Import B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('microlife:full-catalogue', [
        '--from' => 'scrapers/microlife/runtime-links-import-test.json',
        '--runtime-dir' => 'scrapers/microlife/runtime-import-test',
        '--batch-size' => '2',
        '--status' => 'active',
        '--no-images' => true,
        '--no-documents' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Batch complete: crawled 2, imported 2, crawl failures 0, import failures 0, import warnings 0')
        ->expectsOutputToContain('Audit products: 2/2')
        ->expectsOutputToContain('Audit variants: 2/2')
        ->expectsOutputToContain('Audit products without categories: 0')
        ->assertSuccessful();

    $manifest = readMicrolifeRuntimeManifest('scrapers/microlife/runtime-import-test/manifest.json');
    $audit = readMicrolifeRuntimeManifest('scrapers/microlife/runtime-import-test/audit.json');

    expect($manifest['status'])->toBe('completed')
        ->and($manifest['audit']['passed'])->toBeTrue()
        ->and($audit['passed'])->toBeTrue()
        ->and($audit['database_products'])->toBe(2)
        ->and($audit['database_variants'])->toBe(2)
        ->and($audit['products_without_categories'])->toBe(0)
        ->and($audit['duplicate_variant_identities'])->toBe(0)
        ->and(Product::query()->where('external_source', 'microlife')->count())->toBe(2);

    Product::query()
        ->where('external_source', 'microlife')
        ->get()
        ->each(function (Product $product): void {
            expect($product->status)->toBe(ProductStatus::ACTIVE)
                ->and($product->variants()->count())->toBe(1)
                ->and($product->images()->count())->toBe(0)
                ->and($product->categories()->count())->toBeGreaterThan(0);
        });
});

it('retains the Microlife offset when a crawl batch fails', function (): void {
    $url = 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-failure';

    writeMicrolifeRuntimeDiscovery('scrapers/microlife/runtime-links-failure-test.json', [$url]);

    Http::fake([
        $url => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('microlife:full-catalogue', [
        '--from' => 'scrapers/microlife/runtime-links-failure-test.json',
        '--runtime-dir' => 'scrapers/microlife/runtime-failure-test',
        '--batch-size' => '1',
        '--attempts' => '1',
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
        '--show-failures' => true,
    ])
        ->expectsOutputToContain('Batch crawl failed. The next offset was retained for a resumable retry.')
        ->expectsOutputToContain('crawl '.$url.' — HTTP 500')
        ->assertFailed();

    $manifest = readMicrolifeRuntimeManifest('scrapers/microlife/runtime-failure-test/manifest.json');

    expect($manifest['status'])->toBe('failed')
        ->and($manifest['next_offset'])->toBe(0)
        ->and($manifest['batches'][0]['status'])->toBe('crawl_failed')
        ->and(Product::query()->count())->toBe(0);
});

it('refuses to resume a Microlife runtime after its discovery source changes', function (): void {
    $urls = [
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-hash-a',
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-hash-b',
    ];

    writeMicrolifeRuntimeDiscovery('scrapers/microlife/runtime-links-hash-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(microlifeRuntimeProductFixture($urls[0], 'RT-H1', 'Hash A')),
        $urls[1] => Http::response(microlifeRuntimeProductFixture($urls[1], 'RT-H2', 'Hash B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('microlife:full-catalogue', [
        '--from' => 'scrapers/microlife/runtime-links-hash-test.json',
        '--runtime-dir' => 'scrapers/microlife/runtime-hash-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])->assertSuccessful();

    writeMicrolifeRuntimeDiscovery('scrapers/microlife/runtime-links-hash-test.json', [
        ...$urls,
        'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne/runtime-hash-c',
    ]);

    expect(fn (): int => Artisan::call('microlife:full-catalogue', [
        '--from' => 'scrapers/microlife/runtime-links-hash-test.json',
        '--runtime-dir' => 'scrapers/microlife/runtime-hash-test',
        '--batch-size' => '1',
        '--resume' => true,
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]))->toThrow(
        RuntimeException::class,
        'Microlife product-link discovery JSON changed since this runtime started.',
    );
});

/**
 * @param  list<string>  $urls
 */
function writeMicrolifeRuntimeDiscovery(string $relativePath, array $urls): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $products = [];

    foreach ($urls as $index => $url) {
        $products[] = [
            'source' => 'microlife',
            'external_id' => hash('sha256', $url),
            'catalogue_type' => 'consumer',
            'slug' => basename($url),
            'name' => 'Runtime '.($index + 1),
            'url' => $url,
            'source_url' => $url,
            'canonical_url' => $url,
            'category' => 'Ciśnieniomierze automatyczne',
            'categories' => ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
            'category_paths' => [['Ciśnienie krwi', 'Ciśnieniomierze automatyczne']],
        ];
    }

    file_put_contents($path, json_encode([
        'source' => 'microlife',
        'product_urls' => $urls,
        'products' => $products,
        'source_categories' => [[
            'name' => 'Ciśnieniomierze automatyczne',
            'url' => 'https://www.microlife.pl/produkty/cisnienie-krwi/cisnieniomierze-automatyczne',
            'path' => ['Ciśnienie krwi', 'Ciśnieniomierze automatyczne'],
            'catalogue_type' => 'consumer',
            'is_product_category' => true,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function readMicrolifeRuntimeManifest(string $relativePath): array
{
    return json_decode(
        (string) file_get_contents(storage_path('app/'.$relativePath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function microlifeRuntimeProductFixture(string $canonicalUrl, string $code, string $name): string
{
    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$code} - Microlife AG</title>
                <link rel="canonical" href="{$canonicalUrl}">
                <meta name="description" content="Opis SEO {$name}">
            </head>
            <body>
                <main>
                    <div class="product-number-type"><span>BP</span> {$code}</div>
                    <h1 property="pagetitle">{$name}</h1>
                    <img class="product-hidden js-product-image"
                         src="/uploads/media/600x600/runtime-{$code}.png"
                         alt="{$name}">

                    <section class="product-features">
                        <h2>Funkcje</h2>
                        <p>Opis produktu testowego {$name}.</p>
                        <div rel="product_features" typeof="block">
                            <div property="title">Dokładny pomiar</div>
                            <div property="description">Pomiar przeznaczony do testu runtime.</div>
                        </div>
                    </section>

                    <section>
                        <h2>Specyfikacja</h2>
                        <ul>
                            <li><strong>Model:</strong> {$code}</li>
                            <li>Wyrób medyczny</li>
                        </ul>
                    </section>
                </main>
                <footer>To jest wyrób medyczny. Używaj go zgodnie z instrukcją.</footer>
            </body>
        </html>
        HTML;
}
