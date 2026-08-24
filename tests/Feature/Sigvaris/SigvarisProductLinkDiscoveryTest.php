<?php

declare(strict_types=1);

use App\Services\Sigvaris\SigvarisProductUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers and deduplicates paginated Sigvaris PrestaShop product URLs', function (): void {
    Http::fake([
        'https://www.sklep-sigvaris.com/17-wyroby-kompresyjne' => Http::response(<<<'HTML'
<html><body><div id="products"><div class="products">
<article class="product-miniature"><a href="/7881-94755-comfortable.html">Comfortable</a></article>
<article class="product-miniature"><a href="/7900-95000-other.html">Other</a></article>
</div></div><div>Pokazano 1-12 z 13 pozycji</div></body></html>
HTML),
        'https://www.sklep-sigvaris.com/17-wyroby-kompresyjne?page=2' => Http::response(<<<'HTML'
<html><body><div id="products"><div class="products">
<article class="product-miniature"><a href="/7881-94755-comfortable.html">Comfortable</a></article>
<article class="product-miniature"><a href="/7999-final.html">Final</a></article>
</div></div><div>Pokazano 13-13 z 13 pozycji</div></body></html>
HTML),
    ]);

    $discovery = [
        'categories' => [[
            'external_category_id' => '17',
            'name' => 'Wyroby kompresyjne',
            'url' => 'https://www.sklep-sigvaris.com/17-wyroby-kompresyjne',
            'path' => ['Wyroby kompresyjne'],
        ]],
    ];

    $result = app(SigvarisProductUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape($discovery);

    expect($result['failed_urls'])->toBe([])
        ->and($result['visited_urls'])->toHaveCount(2)
        ->and($result['product_urls'])->toHaveCount(3)
        ->and($result['products'][0]['external_product_id'])->toBe('7881')
        ->and($result['products'][0]['default_combination_id'])->toBe('94755')
        ->and($result['category_results'][0]['pages_scraped'])->toBe(2);
});
