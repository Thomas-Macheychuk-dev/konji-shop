<?php

declare(strict_types=1);

use App\Services\Sigvaris\SigvarisCategoryUrlScraper;
use Illuminate\Support\Facades\Http;

it('discovers nested PrestaShop Sigvaris categories without database writes', function (): void {
    Http::fake([
        'https://www.sklep-sigvaris.com/' => Http::response(<<<'HTML'
<!doctype html><html><body>
<ul id="top-menu">
  <li><a href="https://www.sklep-sigvaris.com/17-wyroby-kompresyjne">Wyroby kompresyjne</a>
    <ul><li><a href="/18-sigvaris-medical">Sigvaris Medical</a>
      <ul><li><a href="/20-podkolanowki-uciskowe-sigvaris">Podkolanówki uciskowe Sigvaris</a></li></ul>
    </li></ul>
  </li>
  <li><a href="/39-wyroby-ortopedyczne">Wyroby ortopedyczne</a></li>
  <li><a href="/334-kompresja-profilaktyczna">Kompresja profilaktyczna</a></li>
  <li><a href="/242-akcesoria">Akcesoria</a></li>
</ul>
<a href="/7881-94755-product.html">Product should not become category</a>
</body></html>
HTML),
    ]);

    $result = app(SigvarisCategoryUrlScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape();

    expect($result['source'])->toBe('sigvaris')
        ->and($result['platform'])->toBe('prestashop')
        ->and($result['failed_urls'])->toBe([])
        ->and($result['category_urls'])->toHaveCount(6)
        ->and(collect($result['categories'])->firstWhere('external_category_id', '7881'))->toBeNull()
        ->and(collect($result['categories'])->firstWhere('external_category_id', '20')['path'])
        ->toBe(['Wyroby kompresyjne', 'Sigvaris Medical', 'Podkolanówki uciskowe Sigvaris']);
});
