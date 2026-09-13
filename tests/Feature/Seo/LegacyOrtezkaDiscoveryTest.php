<?php

declare(strict_types=1);

use App\Services\Seo\LegacyOrtezkaDiscoveryCrawler;
use Illuminate\Support\Facades\Http;

it('builds a bounded legacy Ortezka inventory with products categories content and assets', function (): void {
    Http::fake([
        'https://ortezka.pl/robots.txt' => Http::response("User-agent: *\nSitemap: https://www.ortezka.pl/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
        'https://ortezka.pl/sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">
                <sm:url><sm:loc>https://ortezka.pl/</sm:loc></sm:url>
                <sm:url><sm:loc>https://ortezka.pl/reh4mat-aparat-konczyny-dolnej-am-kdx-011re-id-3083</sm:loc></sm:url>
            </sm:urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
        'https://ortezka.pl/1_index_sitemap.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/sitemap_index.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/' => Http::response(<<<'HTML'
            <!doctype html>
            <html>
            <head>
                <title>Internetowy sklep medyczny Ortezka.pl</title>
                <link rel="canonical" href="https://www.ortezka.pl/">
            </head>
            <body>
                <h1>Internetowy sklep medyczny Ortezka.pl</h1>
                <a href="/reh4mat-aparat-konczyny-dolnej-am-kdx-011re-id-3083?utm_source=test">Produkt</a>
                <a href="/pasy-przepuklinowe-cat-185">Kategoria</a>
                <a href="/content/15-kontakt">Kontakt</a>
                <a href="/dane/instrukcje/UMBRELLA.pdf">Instrukcja</a>
                <a href="/cart">Koszyk</a>
                <a href="/pasy-przepuklinowe-cat-185?page=2">Strona 2</a>
                <a href="/pasy-przepuklinowe-cat-185?q=Rozmiar-S">Facet</a>
                <a href="https://example.com/outside">External</a>
            </body>
            </html>
            HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        'https://ortezka.pl/reh4mat-aparat-konczyny-dolnej-am-kdx-011re-id-3083' => Http::response(<<<'HTML'
            <!doctype html>
            <html>
            <head>
                <title>AM-KDX-01/1RE - Orteza kończyny dolnej</title>
                <link rel="canonical" href="https://ortezka.pl/reh4mat-aparat-konczyny-dolnej-am-kdx-011re-id-3083">
                <meta name="robots" content="index,follow">
            </head>
            <body>
                <nav class="breadcrumb"><a href="/">Strona główna</a><a href="/ortezy-cat-10">Ortezy</a></nav>
                <h1>AM-KDX-01/1RE - Orteza kończyny dolnej</h1>
                <div class="product-manufacturer">Reh4Mat</div>
                <span itemprop="sku">AM-KDX-01/1RE</span>
            </body>
            </html>
            HTML, 200, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/pasy-przepuklinowe-cat-185' => Http::response('<!doctype html><html><head><title>Pasy przepuklinowe</title></head><body><h1>Pasy przepuklinowe</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/pasy-przepuklinowe-cat-185?page=2' => Http::response('<!doctype html><html><head><title>Pasy - strona 2</title></head><body><h1>Pasy</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/content/15-kontakt' => Http::response('<!doctype html><html><head><title>Kontakt</title></head><body><h1>Kontakt</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/ortezy-cat-10' => Http::response('<!doctype html><html><head><title>Ortezy</title></head><body><h1>Ortezy</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404, ['Content-Type' => 'text/html']),
    ]);

    $result = app(LegacyOrtezkaDiscoveryCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->crawl(maxUrls: 100);

    $records = collect($result['urls'])->keyBy('url');

    expect($result['database_writes'])->toBeFalse()
        ->and($result['canonical_host'])->toBe('ortezka.pl')
        ->and($result['summary']['url_limit_reached'])->toBeFalse()
        ->and($result['summary']['products'])->toBe(1)
        ->and($result['summary']['categories'])->toBe(4)
        ->and($result['summary']['content_pages'])->toBe(1)
        ->and($result['summary']['assets'])->toBe(1)
        ->and($result['summary']['operational_urls'])->toBe(1)
        ->and($result['summary']['query_variants_discovered'])->toBe(2)
        ->and($result['summary']['query_variants_fetched'])->toBe(1)
        ->and($result['summary']['sitemaps_available'])->toBe(1)
        ->and($result['summary']['sitemap_entries_parsed'])->toBe(2)
        ->and($result['summary']['sitemap_urls_discovered'])->toBe(2)
        ->and($records)->toHaveKey('https://ortezka.pl/dane/instrukcje/UMBRELLA.pdf')
        ->and($records['https://ortezka.pl/dane/instrukcje/UMBRELLA.pdf']['fetched'])->toBeFalse()
        ->and($records['https://ortezka.pl/cart']['fetched'])->toBeFalse()
        ->and($records['https://ortezka.pl/pasy-przepuklinowe-cat-185?q=Rozmiar-S']['fetched'])->toBeFalse();

    $product = $records['https://ortezka.pl/reh4mat-aparat-konczyny-dolnej-am-kdx-011re-id-3083'];

    expect($product['type'])->toBe('product')
        ->and($product['legacy_id'])->toBe(3083)
        ->and($product['index'])->toBe('AM-KDX-01/1RE')
        ->and($product['brand'])->toBe('Reh4Mat')
        ->and($product['meta_robots'])->toBe('index,follow')
        ->and($product['breadcrumbs'])->toBe(['Strona główna', 'Ortezy'])
        ->and($product['canonical'])->toBe('https://ortezka.pl/reh4mat-aparat-konczyny-dolnej-am-kdx-011re-id-3083');
});

it('records existing redirects and queues their final same-site destinations without following them implicitly', function (): void {
    Http::fake([
        'https://ortezka.pl/old-id-10' => Http::response('', 301, ['Location' => '/new-id-11']),
        'https://ortezka.pl/new-id-11' => Http::response('<!doctype html><html><head><title>New</title></head><body><h1>New</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404, ['Content-Type' => 'text/html']),
    ]);

    $result = app(LegacyOrtezkaDiscoveryCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->crawl('https://www.ortezka.pl/old-id-10', 10, false);

    $records = collect($result['urls'])->keyBy('url');

    expect($result['summary']['redirects'])->toBe(1)
        ->and($records['https://ortezka.pl/old-id-10']['status'])->toBe(301)
        ->and($records['https://ortezka.pl/old-id-10']['redirect_target'])->toBe('https://ortezka.pl/new-id-11')
        ->and($records['https://ortezka.pl/new-id-11']['status'])->toBe(200)
        ->and($records['https://ortezka.pl/new-id-11']['discovery_methods'])->toContain('redirect');
});

it('parses a large namespace-prefixed sitemap without document-wide PCRE matching', function (): void {
    $locations = collect(range(1, 2500))
        ->map(fn (int $number): string => '<sm:url><sm:loc>https://ortezka.pl/dane/instrukcje/file-'.$number.'.pdf</sm:loc></sm:url>')
        ->implode("\n");

    $sitemap = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<sm:urlset xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">'
        .$locations
        .'</sm:urlset>';

    Http::fake([
        'https://ortezka.pl/robots.txt' => Http::response("User-agent: *\nSitemap: https://ortezka.pl/1_pl_0_sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
        'https://ortezka.pl/1_pl_0_sitemap.xml' => Http::response($sitemap, 200, ['Content-Type' => 'application/xml']),
        'https://ortezka.pl/sitemap.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/1_index_sitemap.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/sitemap_index.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/' => Http::response('<!doctype html><html><head><title>Start</title></head><body><h1>Start</h1></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404, ['Content-Type' => 'text/html']),
    ]);

    $result = app(LegacyOrtezkaDiscoveryCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->crawl(maxUrls: 3000);

    expect($result['summary']['sitemap_entries_parsed'])->toBe(2500)
        ->and($result['summary']['sitemap_urls_discovered'])->toBe(2500)
        ->and($result['summary']['assets'])->toBe(2500)
        ->and($result['summary']['url_limit_reached'])->toBeFalse();
});

it('saves JSON and CSV inventory artifacts through the seo legacy discovery command', function (): void {
    $jsonPath = storage_path('app/scrapers/seo/ortezka/test-legacy-inventory.json');
    $csvPath = storage_path('app/scrapers/seo/ortezka/test-legacy-inventory.csv');
    $checkpointPath = storage_path('app/scrapers/seo/ortezka/test-legacy-discovery.checkpoint.jsonl');

    @unlink($jsonPath);
    @unlink($csvPath);
    @unlink($checkpointPath);

    Http::fake([
        'https://ortezka.pl/' => Http::response(<<<'HTML'
            <!doctype html><html><head><title>Ortezka</title></head><body>
                <h1>Ortezka</h1>
                <a href="/sample-id-123">Sample product</a>
            </body></html>
            HTML, 200, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/sample-id-123' => Http::response('<!doctype html><html><head><title>Sample</title></head><body><h1>Sample</h1><span itemprop="sku">SKU-123</span></body></html>', 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404, ['Content-Type' => 'text/html']),
    ]);

    $this->artisan('seo:legacy-discover', [
        '--skip-sitemaps' => true,
        '--request-delay-ms' => '0',
        '--retry-delay-ms' => '0',
        '--no-progress' => true,
        '--checkpoint' => 'scrapers/seo/ortezka/test-legacy-discovery.checkpoint.jsonl',
        '--save' => 'scrapers/seo/ortezka/test-legacy-inventory.json',
        '--csv' => 'scrapers/seo/ortezka/test-legacy-inventory.csv',
    ])->assertExitCode(0);

    expect(is_file($jsonPath))->toBeTrue()
        ->and(is_file($csvPath))->toBeTrue()
        ->and(is_file($checkpointPath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);
    $csv = (string) file_get_contents($csvPath);
    $checkpoint = (string) file_get_contents($checkpointPath);

    @unlink($jsonPath);
    @unlink($csvPath);
    @unlink($checkpointPath);

    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['summary']['products'])->toBe(1)
        ->and($csv)->toContain('legacy_id')
        ->and($csv)->toContain('https://ortezka.pl/sample-id-123')
        ->and($csv)->toContain('SKU-123')
        ->and($checkpoint)->toContain('https://ortezka.pl/sample-id-123');
});

it('resumes discovered state without refetching URLs already completed in the checkpoint', function (): void {
    Http::fake([
        'https://ortezka.pl/pending-id-456' => Http::response(
            '<!doctype html><html><head><title>Pending</title></head><body><h1>Pending</h1><span itemprop="sku">SKU-456</span></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        '*' => Http::response('', 500),
    ]);

    $resumeRecords = [
        'https://ortezka.pl/' => [
            'url' => 'https://ortezka.pl/',
            'path' => '/',
            'query' => null,
            'type' => 'home',
            'legacy_id' => null,
            'fetched' => true,
            'status' => 200,
            'content_type' => 'text/html',
            'is_html' => true,
            'redirect_target' => null,
            'canonical' => 'https://ortezka.pl/',
            'title' => 'Ortezka',
            'h1' => 'Ortezka',
            'meta_robots' => null,
            'index' => null,
            'brand' => null,
            'breadcrumbs' => [],
            'internal_link_count' => 1,
            'discovery_methods' => ['start_url'],
            'discovered_from' => [],
            'fetch_error' => null,
        ],
        'https://ortezka.pl/pending-id-456' => [
            'url' => 'https://ortezka.pl/pending-id-456',
            'path' => '/pending-id-456',
            'query' => null,
            'type' => 'product',
            'legacy_id' => 456,
            'fetched' => false,
            'status' => null,
            'content_type' => null,
            'is_html' => false,
            'redirect_target' => null,
            'canonical' => null,
            'title' => null,
            'h1' => null,
            'meta_robots' => null,
            'index' => null,
            'brand' => null,
            'breadcrumbs' => [],
            'internal_link_count' => 0,
            'discovery_methods' => ['html_link'],
            'discovered_from' => ['https://ortezka.pl/'],
            'fetch_error' => null,
        ],
    ];

    $result = app(LegacyOrtezkaDiscoveryCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->withResumeRecords($resumeRecords)
        ->crawl('https://ortezka.pl/', 100, false);

    $records = collect($result['urls'])->keyBy('url');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://ortezka.pl/pending-id-456');

    expect($result['summary']['urls_fetched'])->toBe(2)
        ->and($records['https://ortezka.pl/']['title'])->toBe('Ortezka')
        ->and($records['https://ortezka.pl/pending-id-456']['fetched'])->toBeTrue()
        ->and($records['https://ortezka.pl/pending-id-456']['index'])->toBe('SKU-456');
});

it('prunes query and media checkpoint records before hydrating reconciliation state', function (): void {
    $checkpointPath = storage_path('app/scrapers/seo/ortezka/test-memory-bounded-reconcile.checkpoint.jsonl');

    @unlink($checkpointPath);
    @mkdir(dirname($checkpointPath), 0755, true);

    $records = [
        [
            'url' => 'https://ortezka.pl/retained-id-1',
            'path' => '/retained-id-1',
            'query' => null,
            'type' => 'product',
            'legacy_id' => 1,
            'fetched' => true,
            'status' => 200,
            'content_type' => 'text/html',
            'is_html' => true,
            'title' => 'Retained',
            'discovery_methods' => ['html_link'],
        ],
        [
            'url' => 'https://ortezka.pl/category-cat-1?page=2',
            'path' => '/category-cat-1',
            'query' => 'page=2',
            'type' => 'category',
            'legacy_id' => 1,
            'fetched' => true,
            'status' => 200,
            'discovery_methods' => ['html_link'],
        ],
        [
            'url' => 'https://ortezka.pl/13542-large_default/photo.jpg',
            'path' => '/13542-large_default/photo.jpg',
            'query' => null,
            'type' => 'other',
            'legacy_id' => null,
            'fetched' => false,
            'status' => null,
            'discovery_methods' => ['sitemap'],
        ],
    ];

    file_put_contents(
        $checkpointPath,
        implode(PHP_EOL, array_map(
            static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $records,
        )).PHP_EOL,
    );

    try {
        $this->artisan('seo:legacy-discover', [
            '--skip-sitemaps' => true,
            '--resume' => true,
            '--sitemap-reconcile-only' => true,
            '--request-delay-ms' => '0',
            '--retry-delay-ms' => '0',
            '--no-progress' => true,
            '--checkpoint' => 'scrapers/seo/ortezka/test-memory-bounded-reconcile.checkpoint.jsonl',
            '--save' => '',
            '--csv' => '',
        ])
            ->expectsOutputToContain('Resuming from 1 checkpointed URL records retained in memory.')
            ->expectsOutputToContain('Checkpoint query variants pruned before hydration: 1')
            ->expectsOutputToContain('Checkpoint media URLs pruned before hydration: 1')
            ->expectsOutputToContain('Reconciliation query variants pruned: 1')
            ->expectsOutputToContain('Reconciliation media URLs pruned: 1')
            ->assertSuccessful();
    } finally {
        @unlink($checkpointPath);
    }
});

it('reconciles sitemap URLs without refetching media or recursively expanding HTML links', function (): void {
    Http::fake([
        'https://ortezka.pl/robots.txt' => Http::response("User-agent: *\nSitemap: https://ortezka.pl/1_pl_0_sitemap.xml\n", 200, ['Content-Type' => 'text/plain']),
        'https://ortezka.pl/1_pl_0_sitemap.xml' => Http::response(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
                <url>
                    <loc>https://ortezka.pl/sitemap-only-id-999</loc>
                    <image:image><image:loc>https://ortezka.pl/large_default/photo.jpg</image:loc></image:image>
                </url>
            </urlset>
            XML, 200, ['Content-Type' => 'application/xml']),
        'https://ortezka.pl/sitemap.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/1_index_sitemap.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/sitemap_index.xml' => Http::response('', 404, ['Content-Type' => 'text/html']),
        'https://ortezka.pl/sitemap-only-id-999' => Http::response(
            '<!doctype html><html><head><title>Sitemap only</title></head><body><h1>Sitemap only</h1><a href="/should-not-expand-id-1000">Do not expand</a></body></html>',
            200,
            ['Content-Type' => 'text/html'],
        ),
        '*' => Http::response('', 500),
    ]);

    $resumeRecords = [
        'https://ortezka.pl/category-cat-1?page=2' => [
            'url' => 'https://ortezka.pl/category-cat-1?page=2',
            'path' => '/category-cat-1',
            'query' => 'page=2',
            'type' => 'category',
            'legacy_id' => 1,
            'fetched' => true,
            'status' => 200,
            'discovery_methods' => ['html_link'],
        ],
        'https://ortezka.pl/large_default/old-photo.jpg' => [
            'url' => 'https://ortezka.pl/large_default/old-photo.jpg',
            'path' => '/large_default/old-photo.jpg',
            'query' => null,
            'type' => 'other',
            'legacy_id' => null,
            'fetched' => false,
            'status' => null,
            'discovery_methods' => ['sitemap'],
        ],
    ];

    $result = app(LegacyOrtezkaDiscoveryCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->withRetryDelayMilliseconds(0)
        ->withResumeRecords($resumeRecords)
        ->withSitemapReconcileOnly(true)
        ->crawl('https://ortezka.pl/', 100, true, 10);

    $records = collect($result['urls'])->keyBy('url');

    expect($result['summary']['sitemap_entries_parsed'])->toBe(1)
        ->and($result['summary']['sitemap_urls_discovered'])->toBe(1)
        ->and($result['summary']['reconciliation_query_variants_pruned'])->toBe(1)
        ->and($result['summary']['reconciliation_media_urls_pruned'])->toBe(1)
        ->and($records)->toHaveKey('https://ortezka.pl/sitemap-only-id-999')
        ->and($records)->not->toHaveKey('https://ortezka.pl/large_default/photo.jpg')
        ->and($records)->not->toHaveKey('https://ortezka.pl/should-not-expand-id-1000')
        ->and($records['https://ortezka.pl/sitemap-only-id-999']['fetched'])->toBeTrue();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'photo.jpg'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'should-not-expand-id-1000'));
});
