<?php

declare(strict_types=1);

use App\Services\Qmed\QmedProductDataCrawler;
use Illuminate\Support\Facades\Http;

it('preserves Qmed descriptions, measurement tables, originals and AJAX attachment metadata', function (): void {
    $html = <<<'HTML'
    <html><body>
    <h2 class="itemTitle">ACL RECOVER Orteza</h2>
    <span class="itemImage"><img src="/images/k2/main.jpg"></span>
    <div class="itemFullText">
      <h4>ZASTOSOWANIE</h4><p>Rehabilitacja stawu kolanowego.</p>
      <h4>CECHY PRODUKTU</h4><ul><li>Wersja prawa i lewa.</li></ul>
      <h4>PARAMETRY TECHNICZNE</h4>
      <table><tr><th>Rozmiar</th><th>S</th><th>M</th></tr>
      <tr><td>Obwód uda (cm)</td><td>39-47</td><td>47-53</td></tr></table>
    </div>
    <div class="itemImageGallery"><a href="/images/com_droppics/20/full/a.png?123"><img src="/images/com_droppics/20/thumbnails/a.png"></a></div>
    <div class="itemAttachmentsBlock"><form class="jfile" action="/index.php" method="post">
      <a class="__title" href="#">Instrukcja używania</a>
      <input type="hidden" name="option" value="com_ajax">
      <input type="hidden" name="plugin" value="jmpattachments">
      <input type="hidden" name="id" value="1043">
    </form></div></body></html>
    HTML;
    $url = 'https://qmedinfo.pl/pl/oferta/konczyna-dolna/staw-kolanowy/acl-recover';
    $product = app(QmedProductDataCrawler::class)->parseProduct($html, $url);

    $expectedId = 'qmed-'.hash('sha256', $url);

    expect($product['external_id'])->toBe($expectedId)
        ->and($product['sku'])->toBeNull()
        ->and($product['name'])->toBe('ACL RECOVER Orteza')
        ->and($product['description_sections_html']['ZASTOSOWANIE'])->toContain('Rehabilitacja')
        ->and($product['technical_tables'][0]['rows'][1])->toBe(['Obwód uda (cm)', '39-47', '47-53'])
        ->and($product['images'])->toHaveCount(2)
        ->and($product['attachments'][0]['request_fields']['id'])->toBe('1043')
        ->and($product['attachments'][0]['download_url'])->toBeNull()
        ->and($product['variant_candidates'])->toBe([])
        ->and($product['variants_unresolved'])->toBeTrue();
});

it('rejects missing product content rather than creating an incomplete product', function (): void {
    app(QmedProductDataCrawler::class)->parseProduct(
        '<h2 class="itemTitle">Title</h2>',
        'https://qmedinfo.pl/pl/oferta/szyja/invalid',
    );
})->throws(RuntimeException::class);

it('crawls discovered Qmed products and reports failures', function (): void {
    $url = 'https://qmedinfo.pl/pl/oferta/szyja/example';
    Http::fake([$url => Http::response('<h2 class="itemTitle">Example</h2><div class="itemFullText"><p>Opis</p></div>')]);
    $result = app(QmedProductDataCrawler::class)->withRequestDelayMilliseconds(0)->crawl([
        'source' => 'qmed',
        'products' => [['url' => $url, 'categories' => [['path' => '/pl/oferta/szyja', 'name' => 'Odcinek szyjny']]]],
    ]);
    expect($result['product_count'])->toBe(1)
        ->and($result['failed_urls'])->toBe([])
        ->and($result['products'][0]['categories'])->toHaveCount(1);
    Http::assertSentCount(1);
});

it('generates deterministic Qmed identities without treating them as manufacturer SKUs', function (): void {
    $crawler = app(QmedProductDataCrawler::class);

    $html = <<<'HTML'
    <h2 class="itemTitle">Qmed Test Product</h2>
    <div class="itemFullText"><p>Manufacturer product description.</p></div>
    HTML;

    $url = 'https://qmedinfo.pl/pl/oferta/szyja/test-product';

    $first = $crawler->parseProduct($html, $url);
    $second = $crawler->parseProduct($html, $url);

    $expected = 'qmed-'.hash('sha256', $url);

    expect($first['external_id'])->toBe($expected)
        ->and($second['external_id'])->toBe($expected)
        ->and($first['external_id'])->toBe($second['external_id'])
        ->and($first['sku'])->toBeNull()
        ->and($second['sku'])->toBeNull();
});
