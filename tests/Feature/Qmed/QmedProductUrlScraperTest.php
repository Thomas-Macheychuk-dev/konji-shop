<?php

declare(strict_types=1);

use App\Services\Qmed\QmedProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('extracts product links without including manufacturer navigation', function (): void {
    $scraper = app(QmedProductUrlScraper::class);

    $html = <<<'HTML'
    <html>
      <ul class="mega-nav">
        <li><a href="/pl/oferta/szyja">Odcinek szyjny</a></li>
      </ul>
      <div class="catItemView">
        <span class="catItemImage">
          <a href="/pl/oferta/konczyna-dolna/staw-kolanowy/patella-stab">
            <img src="/images/patella.jpg">
          </a>
        </span>
        <h3 class="catItemTitle">
          <a href="/pl/oferta/konczyna-dolna/staw-kolanowy/patella-stab">
            PATELLA STAB
          </a>
        </h3>
      </div>
      <div class="k2Pagination">
        <a href="?start=20">2</a>
      </div>
    </html>
    HTML;

    $result = $scraper->extractLinks(
        $html,
        'https://qmedinfo.pl/pl/oferta/konczyna-dolna/staw-kolanowy',
        '/pl/oferta/konczyna-dolna/staw-kolanowy',
    );

    expect($result['products'])->toHaveCount(1)
        ->and($result['products'][0]['name'])->toBe('PATELLA STAB')
        ->and($result['products'][0]['url'])
        ->toBe('https://qmedinfo.pl/pl/oferta/konczyna-dolna/staw-kolanowy/patella-stab');
});

it('rejects product links to external domains', function (): void {
    $scraper = app(QmedProductUrlScraper::class);

    $result = $scraper->extractLinks(
        '<h3 class="catItemTitle"><a href="https://example.com/fake">Fake</a></h3>',
        'https://qmedinfo.pl/pl/oferta/szyja',
        '/pl/oferta/szyja',
    );

    expect($result['products'])->toBe([]);
});

it('does not fetch external category pages during discovery', function (): void {
    Http::fake([
        '*' => Http::response('<html><h3 class="catItemTitle"><a href="https://example.com/product">Fake</a></h3></html>'),
    ]);

    $result = app(QmedProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->discover();

    expect($result['category_count'])->toBe(13)
        ->and($result['product_count'])->toBe(0)
        ->and($result['failed_urls'])->toBe([]);

    Http::assertSentCount(13);
});
