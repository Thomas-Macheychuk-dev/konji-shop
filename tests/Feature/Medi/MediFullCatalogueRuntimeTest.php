<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    File::deleteDirectory(storage_path('app/scrapers/medi/runtime-test'));
    File::deleteDirectory(storage_path('app/scrapers/medi/runtime-resume-test'));
    File::deleteDirectory(storage_path('app/scrapers/medi/runtime-import-test'));
    File::deleteDirectory(storage_path('app/scrapers/medi/runtime-failure-test'));
    File::deleteDirectory(storage_path('app/scrapers/medi/runtime-hash-test'));
    File::delete(storage_path('app/scrapers/medi/runtime-links-test.json'));
    File::delete(storage_path('app/scrapers/medi/runtime-links-resume-test.json'));
    File::delete(storage_path('app/scrapers/medi/runtime-links-import-test.json'));
    File::delete(storage_path('app/scrapers/medi/runtime-links-failure-test.json'));
    File::delete(storage_path('app/scrapers/medi/runtime-links-hash-test.json'));
});

afterEach(function (): void {
    foreach (glob(storage_path('logs/medi/full-catalogue-*.jsonl')) ?: [] as $path) {
        @unlink($path);
    }
});

it('saves a paused dry-run batch with an atomic manifest and operational log', function (): void {
    $urls = [
        'https://www.medi-polska.pl/shop/runtime-product-a.html',
        'https://www.medi-polska.pl/shop/runtime-product-b.html',
    ];
    writeMediRuntimeDiscovery('scrapers/medi/runtime-links-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(mediRuntimeProductFixture($urls[0], '71001', 'Runtime Product A', 'RUNTIME-A')),
        $urls[1] => Http::response(mediRuntimeProductFixture($urls[1], '71002', 'Runtime Product B', 'RUNTIME-B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Running Medi full catalogue runtime...')
        ->expectsOutputToContain('Batch 1: product URLs 1-1 of 2')
        ->expectsOutputToContain('Runtime paused after 1 batch(es). Resume with --resume.')
        ->assertSuccessful();

    $manifest = readMediRuntimeManifest('scrapers/medi/runtime-test/manifest.json');

    expect($manifest)->toMatchArray([
        'source' => 'medi',
        'status' => 'paused',
        'total_product_urls' => 2,
        'batch_size' => 1,
        'next_offset' => 1,
        'had_failures' => false,
    ])
        ->and($manifest['counts'])->toMatchArray([
            'batches_completed' => 1,
            'products_crawled' => 1,
            'products_imported' => 0,
            'crawl_failures' => 0,
            'import_failures' => 0,
        ])
        ->and($manifest['batches'][0]['status'])->toBe('dry_run_complete')
        ->and(is_file(storage_path('app/scrapers/medi/runtime-test/product-data/batch-000000-000000.json')))->toBeTrue()
        ->and(is_file(storage_path(substr($manifest['log_path'], strlen('storage/')))))->toBeTrue()
        ->and(Product::query()->count())->toBe(0);
});

it('resumes at the manifest next offset and completes the remaining Medi batches', function (): void {
    $urls = [
        'https://www.medi-polska.pl/shop/runtime-resume-a.html',
        'https://www.medi-polska.pl/shop/runtime-resume-b.html',
    ];
    writeMediRuntimeDiscovery('scrapers/medi/runtime-links-resume-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(mediRuntimeProductFixture($urls[0], '72001', 'Resume Product A', 'RESUME-A')),
        $urls[1] => Http::response(mediRuntimeProductFixture($urls[1], '72002', 'Resume Product B', 'RESUME-B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-resume-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-resume-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])->assertSuccessful();

    $this->artisan('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-resume-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-resume-test',
        '--batch-size' => '1',
        '--resume' => true,
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Starting offset: 1')
        ->expectsOutputToContain('Medi full catalogue runtime finished with status: completed')
        ->assertSuccessful();

    $manifest = readMediRuntimeManifest('scrapers/medi/runtime-resume-test/manifest.json');

    expect($manifest['status'])->toBe('completed')
        ->and($manifest['next_offset'])->toBe(2)
        ->and($manifest['counts']['batches_completed'])->toBe(2)
        ->and($manifest['counts']['products_crawled'])->toBe(2)
        ->and(array_keys($manifest['batches']))->toBe([0, 1]);

    Http::assertSentCount(2);
});

it('imports a complete Medi batch with the requested product status and no images', function (): void {
    $urls = [
        'https://www.medi-polska.pl/shop/runtime-import-a.html',
        'https://www.medi-polska.pl/shop/runtime-import-b.html',
    ];
    writeMediRuntimeDiscovery('scrapers/medi/runtime-links-import-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(mediRuntimeProductFixture($urls[0], '73001', 'Import Product A', 'IMPORT-A')),
        $urls[1] => Http::response(mediRuntimeProductFixture($urls[1], '73002', 'Import Product B', 'IMPORT-B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-import-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-import-test',
        '--batch-size' => '2',
        '--status' => 'active',
        '--no-images' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])
        ->expectsOutputToContain('Batch complete: crawled 2, imported 2, crawl failures 0, import failures 0')
        ->expectsOutputToContain('Products imported: 2')
        ->assertSuccessful();

    $manifest = readMediRuntimeManifest('scrapers/medi/runtime-import-test/manifest.json');

    expect($manifest['status'])->toBe('completed')
        ->and($manifest['counts']['products_imported'])->toBe(2)
        ->and(Product::query()->where('external_source', 'medi')->count())->toBe(2);

    Product::query()
        ->where('external_source', 'medi')
        ->get()
        ->each(function (Product $product): void {
            expect($product->status)->toBe(ProductStatus::ACTIVE)
                ->and($product->variants()->count())->toBe(1)
                ->and($product->images()->count())->toBe(0);
        });
});

it('retains the current offset when a Medi crawl batch fails', function (): void {
    $url = 'https://www.medi-polska.pl/shop/runtime-failure.html';
    writeMediRuntimeDiscovery('scrapers/medi/runtime-links-failure-test.json', [$url]);

    Http::fake([
        $url => Http::response('', 500),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-failure-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-failure-test',
        '--batch-size' => '1',
        '--attempts' => '1',
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
        '--show-failures' => true,
    ])
        ->expectsOutputToContain('Batch crawl failed. The next offset was retained for a resumable retry.')
        ->expectsOutputToContain('crawl '.$url.' — HTTP 500')
        ->assertFailed();

    $manifest = readMediRuntimeManifest('scrapers/medi/runtime-failure-test/manifest.json');

    expect($manifest['status'])->toBe('failed')
        ->and($manifest['next_offset'])->toBe(0)
        ->and($manifest['had_failures'])->toBeTrue()
        ->and($manifest['batches'][0]['status'])->toBe('crawl_failed')
        ->and($manifest['counts']['crawl_failures'])->toBe(1)
        ->and(Product::query()->count())->toBe(0);
});

it('refuses to resume when the product-link discovery source changed', function (): void {
    $urls = [
        'https://www.medi-polska.pl/shop/runtime-hash-a.html',
        'https://www.medi-polska.pl/shop/runtime-hash-b.html',
    ];
    writeMediRuntimeDiscovery('scrapers/medi/runtime-links-hash-test.json', $urls);

    Http::fake([
        $urls[0] => Http::response(mediRuntimeProductFixture($urls[0], '74001', 'Hash Product A', 'HASH-A')),
        $urls[1] => Http::response(mediRuntimeProductFixture($urls[1], '74002', 'Hash Product B', 'HASH-B')),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-hash-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-hash-test',
        '--batch-size' => '1',
        '--max-batches' => '1',
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ])->assertSuccessful();

    writeMediRuntimeDiscovery('scrapers/medi/runtime-links-hash-test.json', [
        ...$urls,
        'https://www.medi-polska.pl/shop/runtime-hash-c.html',
    ]);

    expect(fn (): int => Artisan::call('medi:full-catalogue', [
        '--from' => 'scrapers/medi/runtime-links-hash-test.json',
        '--runtime-dir' => 'scrapers/medi/runtime-hash-test',
        '--batch-size' => '1',
        '--resume' => true,
        '--dry-run' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
    ]))->toThrow(
        RuntimeException::class,
        'Medi product-link discovery JSON changed since this runtime started.',
    );
});

/**
 * @param  list<string>  $urls
 */
function writeMediRuntimeDiscovery(string $relativePath, array $urls): void
{
    $path = storage_path('app/'.$relativePath);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    $products = [];

    foreach ($urls as $url) {
        $products[] = [
            'source' => 'medi',
            'url' => $url,
            'external_id' => pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME),
        ];
    }

    file_put_contents($path, json_encode([
        'source' => 'medi',
        'product_urls' => $urls,
        'products' => $products,
        'category_results' => [
            [
                'name' => 'Akcesoria',
                'url' => 'https://www.medi-polska.pl/shop/kategoria-produktu/akcesoria.html',
                'category_path' => ['Akcesoria'],
                'product_urls' => $urls,
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function readMediRuntimeManifest(string $relativePath): array
{
    return json_decode(
        (string) file_get_contents(storage_path('app/'.$relativePath)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function mediRuntimeProductFixture(string $canonicalUrl, string $productId, string $name, string $sku): string
{
    $structuredData = json_encode([
        '@context' => 'https://schema.org/',
        '@type' => 'ItemPage',
        'mainEntity' => [
            '@context' => 'https://schema.org/',
            '@type' => 'Product',
            'name' => $name,
            'sku' => $sku,
            'description' => 'Runtime test accessory product.',
            'image' => 'https://s7e5a.scene7.com/is/image/medi/runtime-'.$productId.'?$Product-medical-2to3$',
            'brand' => ['@type' => 'Brand', 'name' => 'medi'],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    return <<<HTML
        <!doctype html>
        <html lang="pl">
        <head>
            <title>{$name} | medi sklep internetowy</title>
            <link rel="canonical" href="{$canonicalUrl}">
            <meta name="description" content="Runtime SEO description.">
            <meta property="og:description" content="Runtime accessory">
            <meta property="og:image" content="https://s7e5a.scene7.com/is/image/medi/runtime-{$productId}?\$Product-medical-2to3\$">
            <meta property="product:price:amount" content="119">
            <meta property="product:price:currency" content="PLN">
            <script type="application/ld+json">{$structuredData}</script>
        </head>
        <body class="page-product-simple catalog-product-view">
            <div class="breadcrumbs"><ul class="items"><li class="item home">Strona główna</li><li class="item">Akcesoria</li></ul></div>
            <div class="gallery-placeholder" data-gallery-role="gallery-placeholder">
                <img class="gallery-placeholder__image" alt="{$name}" src="https://s7e5a.scene7.com/is/image/medi/runtime-{$productId}?\$Product-medical-2to3\$&amp;wid=420&amp;hei=630">
            </div>
            <div class="product-info-main">
                <h1 class="product-title">{$name}<span class="subtitle">Runtime accessory</span></h1>
                <div class="product-info-price"><span data-price-type="finalPrice" data-price-amount="119"></span></div>
                <form id="product_addtocart_form" data-product-sku="{$sku}">
                    <input type="hidden" name="product" value="{$productId}">
                </form>
                <div class="stock available inStock"><span class="text">Wysyłka w ciągu 1-2 dni roboczych</span></div>
            </div>
            <div class="product info detailed">
                <div class="product data items">
                    <h2 id="tab-label-description"><a id="tab-label-description-title">Opis</a></h2>
                    <div class="data item content" id="description">
                        <div class="product attribute description"><div class="value"><p>Runtime test accessory product.</p></div></div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        HTML;
}
