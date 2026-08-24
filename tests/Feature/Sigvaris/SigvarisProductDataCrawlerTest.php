<?php

declare(strict_types=1);

use App\Services\Sigvaris\SigvarisProductDataCrawler;
use Illuminate\Support\Facades\Http;

it('extracts Sigvaris product pricing attributes assets medical flag and observed PrestaShop combination', function (): void {
    $url = 'https://www.sklep-sigvaris.com/7881-94755-comfortable-comfort-ccl1.html';

    Http::fake([$url => Http::response(<<<'HTML'
<!doctype html><html><head><link rel="canonical" href="https://www.sklep-sigvaris.com/7881-94755-comfortable-comfort-ccl1.html"></head><body>
<h1>COMFORTABLE (COMFORT) CCL1</h1>
<div class="current-price"><span class="current-price-value">231,48 zł</span></div>
<script>var history={"2026-08-23":{"price_tax_included":"250.000000","price_tax_excluded":"231.481481","lowest":true}};</script>
<div class="product-variants">
 <div class="product-variants-item"><span class="control-label">Kolor:</span><select><option>Cielisty</option><option selected>Czarny</option></select></div>
 <div class="product-variants-item"><span class="control-label">Rozmiar:</span><select><option>L</option><option selected>XXS</option></select></div>
</div>
<div class="product-cover"><img src="/img/p/1/main.webp" alt="Main"></div>
<ul class="product-images"><li><img class="js-thumb" data-image-large-src="https://www.sklep-sigvaris.com/img/p/1/second.webp" alt="Second"></li></ul>
<a class="size-chart" href="/img/cms/tabela-rozmiarow.png">TABELA ROZMIARÓW</a>
<section id="description"><div class="product-description"><p>Opis produktu</p><a href="/download/srodki-ostroznosci.pdf">ŚRODKI OSTROŻNOŚCI</a></div></section>
<dl class="data-sheet"><dt>skład</dt><dd>Elastan 35%; Poliamid 65%</dd></dl>
<div>Indeks 68098</div><div>W magazynie 500 Przedmioty</div>
<div>WAŻNE: To jest wyrób medyczny. Przed użyciem zapoznaj się z instrukcją.</div>
<div>Producent / Importer SIGVARIS S.A. Ulica: Mazowiecka 11/49</div>
</body></html>
HTML)]);

    $result = app(SigvarisProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$url]);

    $product = $result['products'][0];

    expect($result['product_count'])->toBe(1)
        ->and($product['external_product_id'])->toBe('7881')
        ->and($product['default_combination_id'])->toBe('94755')
        ->and($product['name'])->toBe('COMFORTABLE (COMFORT) CCL1')
        ->and($product['display_price_amount'])->toBe(231.48)
        ->and($product['price_gross_amount'])->toBe(250.0)
        ->and($product['price_net_amount'])->toBe(231.481481)
        ->and($product['tax_rate_percent'])->toBe(8.0)
        ->and($product['reference'])->toBe('68098')
        ->and($product['stock_quantity'])->toBe(500)
        ->and($product['availability'])->toBe('in_stock')
        ->and($product['is_medical_device'])->toBeTrue()
        ->and($product['manufacturer'])->toBe('SIGVARIS S.A.')
        ->and($product['attributes'])->toHaveCount(2)
        ->and($product['observed_combination']['external_variant_id'])->toBe('94755')
        ->and($product['images'])->toHaveCount(2)
        ->and($product['downloads'])->toHaveCount(1)
        ->and($product['size_chart'])->toBe([
            'url' => 'https://www.sklep-sigvaris.com/img/cms/tabela-rozmiarow.png',
            'label' => 'TABELA ROZMIARÓW',
        ])
        ->and($product['features'])->toContain(['label' => 'skład', 'value' => 'Elastan 35%; Poliamid 65%']);
});

it('does not absorb the W from adjacent W magazynie text into the visible product reference', function (): void {
    $url = 'https://www.sklep-sigvaris.com/7000-90000-reference-boundary.html';

    Http::fake([$url => Http::response(<<<'HTML'
<!doctype html><html><body>
<h1>Reference boundary</h1>
<div class="current-price"><span class="current-price-value">100,00 zł</span></div>
<div>Indeks 68098</div><div>W magazynie 1 Przedmioty</div>
</body></html>
HTML)]);

    $result = app(SigvarisProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$url]);

    expect($result['products'][0]['reference'])->toBe('68098');
});

it('extracts gross net and VAT from HTML-encoded PrestaShop price history', function (): void {
    $url = 'https://www.sklep-sigvaris.com/97625-99269-compreflex-standard-wrap-udo.html';

    Http::fake([$url => Http::response(<<<'HTML'
<!doctype html><html><body>
<h1>Compreflex Standard – Wrap Udo</h1>
<div class="current-price"><span class="current-price-value">346,30 zł</span></div>
<script type="application/json">{&quot;2026-08-23&quot;:{&quot;price_tax_included&quot;:&quot;374.000000&quot;,&quot;price_tax_excluded&quot;:&quot;346.296296&quot;,&quot;lowest&quot;:true}}</script>
<div>Indeks 351266</div><div>W magazynie 499 Przedmioty</div>
<div>WAŻNE: To jest wyrób medyczny. Przed użyciem zapoznaj się z instrukcją.</div>
</body></html>
HTML)]);

    $result = app(SigvarisProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$url]);

    $product = $result['products'][0];

    expect($product['display_price_amount'])->toBe(346.3)
        ->and($product['price_gross_amount'])->toBe(374.0)
        ->and($product['price_net_amount'])->toBe(346.296296)
        ->and($product['tax_rate_percent'])->toBe(8.0);
});

it('extracts PrestaDog GPSR attachment downloads outside the product description', function (): void {
    $url = 'https://www.sklep-sigvaris.com/97625-99269-compreflex-standard-wrap-udo.html';

    Http::fake([$url => Http::response(<<<'HTML'
<!doctype html><html><body>
<h1>Compreflex Standard – Wrap Udo</h1>
<div class="current-price"><span class="current-price-value">374,00 zł</span></div>
<div>Indeks 351266</div><div>W magazynie 499 Przedmioty</div>
<section class="gpsr-documents">
    <h4>Dokumenty</h4>
    <a href="/module/prestadogpsrmanager/download?id_attachment=10&amp;id_product=97625">ŚRODKI OSTROŻNOŚCI</a>
</section>
</body></html>
HTML)]);

    $result = app(SigvarisProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$url]);

    expect($result['products'][0]['downloads'])->toBe([
        [
            'url' => 'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id_attachment=10&id_product=97625',
            'label' => 'ŚRODKI OSTROŻNOŚCI',
        ],
    ]);
});

it('extracts tax prices when price-history JSON quotes are backslash escaped', function (): void {
    $url = 'https://www.sklep-sigvaris.com/9000-9001-escaped-history.html';

    Http::fake([$url => Http::response(<<<'HTML'
<!doctype html><html><body>
<h1>Escaped history</h1>
<div class="current-price"><span class="current-price-value">100,00 zł</span></div>
<script>window.historyJson="{\"price_tax_included\":\"108.000000\",\"price_tax_excluded\":\"100.000000\"}";</script>
</body></html>
HTML)]);

    $result = app(SigvarisProductDataCrawler::class)
        ->withRequestDelayMilliseconds(0)
        ->crawlProductUrls([$url]);

    $product = $result['products'][0];

    expect($product['price_gross_amount'])->toBe(108.0)
        ->and($product['price_net_amount'])->toBe(100.0)
        ->and($product['tax_rate_percent'])->toBe(8.0);
});
