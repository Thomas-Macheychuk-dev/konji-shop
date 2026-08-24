<?php

declare(strict_types=1);

use App\Services\Sigvaris\SigvarisCombinationEnumerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function sigvarisCombinationFixture(string $selectedLength = '10', string $selectedSize = '20'): string
{
    $lengthA = $selectedLength === '10' ? ' selected' : '';
    $lengthB = $selectedLength === '11' ? ' selected' : '';
    $sizeS = $selectedSize === '20' ? ' selected' : '';
    $sizeM = $selectedSize === '21' ? ' selected' : '';

    return <<<HTML
<div class="product-variants">
  <div class="product-variants-item">
    <span class="control-label">Długość:</span>
    <select name="group[1]" data-product-attribute="1">
      <option value="10"{$lengthA}>Długa</option>
      <option value="11"{$lengthB}>Standardowa</option>
    </select>
  </div>
  <div class="product-variants-item">
    <span class="control-label">Rozmiar:</span>
    <select name="group[2]" data-product-attribute="2">
      <option value="20"{$sizeS}>S</option>
      <option value="21"{$sizeM}>M</option>
    </select>
  </div>
</div>
HTML;
}

function sigvarisRefreshResponse(string $combinationId, string $length, string $size, string $reference, int $stock, float $price): array
{
    return [
        'id_product_attribute' => $combinationId,
        'product_url' => "https://www.sklep-sigvaris.com/500-{$combinationId}-example.html",
        'product_variants' => sigvarisCombinationFixture($length, $size),
        'product_details' => "<div class=\"product-reference\"><span>Indeks {$reference}</span></div><div>W magazynie {$stock} Przedmioty</div>",
        'product_prices' => '<div class="current-price"><span class="current-price-value">'.number_format($price, 2, ',', '').' zł</span></div>',
        'product_add_to_cart' => '<div class="product-availability">W magazynie</div>',
    ];
}

function fakeSigvarisCombinationEndpoint(string $url): void
{
    Http::fake(function ($request) use ($url) {
        if ($request->method() === 'GET') {
            return Http::response(<<<HTML
<!doctype html><html><body>
<h1>Combination Example</h1>
<div class="product-actions"><form action="{$url}">
<input type="hidden" name="id_product" value="500">
<input type="hidden" name="token" value="abc">
</form></div>
HTML.sigvarisCombinationFixture('10', '20').<<<'HTML'
<div class="product-reference"><span>Indeks REF-S</span></div>
<div>W magazynie 5 Przedmioty</div>
<div class="current-price"><span class="current-price-value">100,00 zł</span></div>
</body></html>
HTML);
        }

        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $group = $query['group'] ?? [];
        $length = (string) ($group[1] ?? '10');
        $size = (string) ($group[2] ?? '20');

        return match ($length.'-'.$size) {
            '10-20' => Http::response(sigvarisRefreshResponse('100', '10', '20', 'REF-S', 5, 100.0)),
            '10-21' => Http::response(sigvarisRefreshResponse('101', '10', '21', 'REF-M', 4, 105.0)),
            '11-20' => Http::response(sigvarisRefreshResponse('102', '11', '20', 'REF-STD-S', 3, 110.0)),
            // This selector combination does not exist; PrestaShop normalises it back to 102.
            '11-21' => Http::response(sigvarisRefreshResponse('102', '11', '20', 'REF-STD-S', 3, 110.0)),
            default => Http::response([], 422),
        };
    });
}

it('enumerates only concrete combinations returned by the PrestaShop refresh endpoint', function (): void {
    $url = 'https://www.sklep-sigvaris.com/500-100-example.html';
    fakeSigvarisCombinationEndpoint($url);

    $result = app(SigvarisCombinationEnumerator::class)
        ->withRequestDelayMilliseconds(0)
        ->enumerate($url, 20);

    expect($result)->not->toBeNull()
        ->and($result['external_product_id'])->toBe('500')
        ->and($result['default_combination_id'])->toBe('100')
        ->and($result['combination_count'])->toBe(3)
        ->and($result['truncated'])->toBeFalse()
        ->and($result['failed_requests'])->toBe([])
        ->and(collect($result['combinations'])->pluck('external_variant_id')->sort()->values()->all())
        ->toBe(['100', '101', '102'])
        ->and(collect($result['combinations'])->firstWhere('external_variant_id', '101')['reference'])
        ->toBe('REF-M')
        ->and(collect($result['combinations'])->firstWhere('external_variant_id', '101')['attributes'])
        ->toContain([
            'external_group_id' => '2',
            'label' => 'Rozmiar',
            'external_attribute_id' => '21',
            'value' => 'M',
        ]);
});

it('stops combination enumeration when the request safety limit is reached', function (): void {
    $url = 'https://www.sklep-sigvaris.com/500-100-example.html';
    fakeSigvarisCombinationEndpoint($url);

    $result = app(SigvarisCombinationEnumerator::class)
        ->withRequestDelayMilliseconds(0)
        ->enumerate($url, 1);

    expect($result)->not->toBeNull()
        ->and($result['truncated'])->toBeTrue()
        ->and($result['refresh_request_count'])->toBe(1)
        ->and($result['warnings'])->not->toBe([]);
});

it('saves a read-only Sigvaris combination enumeration report', function (): void {
    $url = 'https://www.sklep-sigvaris.com/500-100-example.html';
    fakeSigvarisCombinationEndpoint($url);

    $save = 'scrapers/sigvaris/test-combinations.json';
    @unlink(storage_path('app/'.$save));

    $exit = Artisan::call('sigvaris:combinations', [
        '--url' => [$url],
        '--max-requests-per-product' => 20,
        '--request-delay-ms' => 0,
        '--save' => $save,
        '--no-progress' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Concrete combinations: 3')
        ->and($output)->toContain('Database writes: NO')
        ->and(is_file(storage_path('app/'.$save)))->toBeTrue();

    $saved = json_decode((string) file_get_contents(storage_path('app/'.$save)), true, 512, JSON_THROW_ON_ERROR);
    expect($saved['combination_count'])->toBe(3)
        ->and($saved['database_writes'])->toBeFalse();
});
