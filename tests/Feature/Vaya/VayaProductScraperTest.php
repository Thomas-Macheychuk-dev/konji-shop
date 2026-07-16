<?php

use App\Services\Vaya\VayaProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts Vaya product data variants full-size images and category context', function (): void {
    $url = 'https://www.vaya.com.pl/pl/p/Zelowe-poduszki-na-zrogowacenia/895';

    $product = app(VayaProductScraper::class)->extract(
        vayaProductPageFixture(
            canonicalUrl: $url,
            externalProductId: '895',
            name: 'Żelowe poduszki na zrogowacenia 5 w 1',
            sku: 'VAYA-1314',
            price: '52.40',
            imageIds: ['4497', '4502'],
            variants: ['943' => 'S/M', '944' => 'L/XL'],
        ),
        $url,
        [
            'url' => $url,
            'name' => 'Żelowe poduszki na zrogowacenia 5 w 1',
            'category_urls' => [
                'https://www.vaya.com.pl/pl/c/Wkladki-na-modzele/132',
                'https://www.vaya.com.pl/pl/c/Wkladki-na-bunionette/133',
            ],
            'category_paths' => [
                ['Wkładki ortopedyczne', 'Wkładki na modzele'],
                ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
            ],
        ],
    );

    expect($product)->toMatchArray([
        'source' => 'vaya',
        'source_url' => $url,
        'canonical_url' => $url,
        'external_product_id' => '895',
        'slug' => 'Zelowe-poduszki-na-zrogowacenia',
        'name' => 'Żelowe poduszki na zrogowacenia 5 w 1',
        'brand' => ['name' => 'Vaya Medical', 'slug' => 'vaya-medical'],
        'category' => 'Wkładki na modzele',
        'categories' => ['Wkładki ortopedyczne', 'Wkładki na modzele'],
        'price_gross_amount' => 52.40,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'duża ilość',
        'shipping_time' => '24 godziny',
        'sku' => 'VAYA-1314',
        'ean' => null,
        'source_category_name' => 'Wkładki na modzele',
        'source_category_url' => 'https://www.vaya.com.pl/pl/c/Wkladki-na-modzele/132',
        'source_top_category_name' => 'Wkładki ortopedyczne',
        'source_category_path' => ['Wkładki ortopedyczne', 'Wkładki na modzele'],
        'source_category_paths' => [
            ['Wkładki ortopedyczne', 'Wkładki na modzele'],
            ['Wkładki ortopedyczne', 'Wkładki na bunionette'],
        ],
        'is_medical_device' => true,
        'failed_urls' => [],
    ])->and($product['images'])->toBe([
        [
            'url' => 'https://www.vaya.com.pl/userdata/public/gfx/4497/product-4497.png',
            'alt' => 'Żelowe poduszki na zrogowacenia 5 w 1',
        ],
        [
            'url' => 'https://www.vaya.com.pl/userdata/public/gfx/4502/product-4502.png',
            'alt' => 'Żelowe poduszki na zrogowacenia 5 w 1',
        ],
    ])->and($product['variant_candidates'])->toBe([
        [
            'external_variant_id' => '943',
            'sku' => null,
            'label' => 'S/M',
            'attributes' => [['label' => 'Rozmiar', 'value' => 'S/M']],
            'price_gross_amount' => 52.40,
            'currency' => 'PLN',
        ],
        [
            'external_variant_id' => '944',
            'sku' => null,
            'label' => 'L/XL',
            'attributes' => [['label' => 'Rozmiar', 'value' => 'L/XL']],
            'price_gross_amount' => 52.40,
            'currency' => 'PLN',
        ],
    ])->and($product['description_html'])->toContain('Opis produktu medycznego')
        ->and($product['safety_html'])->toContain('Posiada oznaczenie CE')
        ->and($product['tabs'])->toHaveKeys(['opis', 'parametry', 'bezpieczenstwo'])
        ->and(collect($product['images'])->pluck('url')->contains(
            fn (string $imageUrl): bool => str_contains($imageUrl, '/environment/cache/images/')
        ))->toBeFalse();
});

it('extracts Vaya EAN and additional-information attributes', function (): void {
    $url = 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577';

    $product = app(VayaProductScraper::class)->extract(
        vayaProductPageFixture(
            canonicalUrl: $url,
            externalProductId: '577',
            name: 'Termometr elektroniczny medyczny',
            sku: 'HK-902',
            price: '11.40',
            ean: '6932053201728',
            imageIds: ['4337'],
            attributes: [
                'Zakres pomiaru' => '32,0-42,9°C',
                'Wyświetlacz' => 'LCD',
                'Waga' => '10g',
            ],
        ),
        $url,
    );

    expect($product['ean'])->toBe('6932053201728')
        ->and($product['variant_candidates'])->toBe([])
        ->and($product['attributes'])->toContain(
            ['code' => 'zakres-pomiaru', 'label' => 'Zakres pomiaru', 'value' => '32,0-42,9°C', 'slug' => '32-0-42-9-c'],
            ['code' => 'wyswietlacz', 'label' => 'Wyświetlacz', 'value' => 'LCD', 'slug' => 'lcd'],
            ['code' => 'waga', 'label' => 'Waga', 'value' => '10g', 'slug' => '10g'],
        )
        ->and($product['images'])->toHaveCount(1)
        ->and($product['warnings'])->toBe([]);
});

it('normalizes only Vaya product-detail URLs', function (): void {
    $scraper = app(VayaProductScraper::class);

    expect($scraper->normalizeProductUrl('http://vaya.com.pl/pl/p/Termometr-elektroniczny/577?utm_source=test#opis'))
        ->toBe('https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577')
        ->and($scraper->normalizeProductUrl('/pl/p/Produkt-z-przecinkiem%2C-test/895', 'https://www.vaya.com.pl/'))
        ->toBe('https://www.vaya.com.pl/pl/p/Produkt-z-przecinkiem%2C-test/895')
        ->and($scraper->normalizeProductUrl('https://www.vaya.com.pl/pl/c/Termometry/112'))->toBeNull()
        ->and($scraper->normalizeProductUrl('https://example.com/pl/p/Termometr/577'))->toBeNull();
});

it('retries temporary Vaya product-page failures', function (): void {
    $url = 'https://www.vaya.com.pl/pl/p/Termometr-elektroniczny/577';

    Http::fake([
        $url => Http::sequence()
            ->push('', 503)
            ->push(vayaProductPageFixture(
                canonicalUrl: $url,
                externalProductId: '577',
                name: 'Termometr elektroniczny medyczny',
                sku: 'HK-902',
                price: '11.40',
                imageIds: ['4337'],
            )),
    ]);

    $product = app(VayaProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->withMaxAttempts(2, 0)
        ->scrape($url);

    expect($product['name'])->toBe('Termometr elektroniczny medyczny')
        ->and($product['failed_urls'])->toBe([]);

    Http::assertSentCount(2);
});

/**
 * @param  array<int, string>  $imageIds
 * @param  array<string, string>  $variants
 * @param  array<string, string>  $attributes
 */
function vayaProductPageFixture(
    string $canonicalUrl,
    string $externalProductId,
    string $name,
    string $sku,
    string $price,
    ?string $ean = null,
    array $imageIds = [],
    array $variants = [],
    array $attributes = ['Materiał' => 'medyczny polimer żelowy TPE'],
): string {
    $imageLinks = '';
    $cachedImages = '';

    foreach ($imageIds as $imageId) {
        $imageLinks .= '<link itemprop="image" href="/userdata/public/gfx/'.$imageId.'/product-'.$imageId.'.png">';
        $cachedImages .= '<img src="/environment/cache/images/productGfx_'.$imageId.'_500_500/product-'.$imageId.'.webp">';
    }

    $eanMeta = $ean === null ? '' : '<meta itemprop="gtin" content="'.$ean.'">';
    $variantHtml = '';

    if ($variants !== []) {
        $variantHtml = '<div class="stocks"><label for="option_172">Rozmiar:</label><select id="option_172" name="option_172">';

        foreach ($variants as $variantId => $variantName) {
            $variantHtml .= '<option value="'.$variantId.'">'.$variantName.'</option>';
        }

        $variantHtml .= '</select></div>';
    }

    $attributeRows = '';

    foreach ($attributes as $label => $value) {
        $attributeRows .= '<tr><th>'.$label.'</th><td>'.$value.'</td></tr>';
    }

    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} | Vaya Medical</title>
                <meta name="description" content="Opis SEO {$name}">
                <meta property="og:image" content="https://www.vaya.com.pl/environment/cache/images/productGfx_{$externalProductId}_500_500/product.webp">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body class="shop_product" id="shop_product{$externalProductId}">
                <div class="breadcrumbs">
                    <a href="/">Strona główna</a>
                    <a href="/wkladki-medyczne-do-butow">Wkładki ortopedyczne</a>
                    <a href="/pl/c/Wkladki-na-modzele/132">Wkładki na modzele</a>
                </div>
                <div id="box_productfull">
                    <div class="productimg">{$imageLinks}{$cachedImages}</div>
                    <div class="product-container">
                        <h1 itemprop="name">{$name}</h1>
                        <div class="manufacturer"><em>Producent:</em><a class="brand">Vaya Medical</a></div>
                        <div class="code"><em>Kod produktu:</em><span>{$sku}</span></div>
                        <div class="availability">
                            <meta itemprop="sku" content="{$sku}">
                            {$eanMeta}
                            <div class="availability"><span class="first">Dostępność:</span><span class="second">duża ilość</span></div>
                            <div class="delivery"><span class="first">Wysyłka w:</span><span class="second">24 godziny</span></div>
                            <meta itemprop="brand" content="Vaya Medical">
                        </div>
                        <div itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                            <link itemprop="url" href="{$canonicalUrl}">
                            <div class="price"><em class="main-price">{$price} zł</em><span itemprop="price">{$price}</span></div>
                            <meta itemprop="priceCurrency" content="PLN">
                            <link itemprop="availability" href="https://schema.org/InStock">
                            <form class="form-basket">
                                {$variantHtml}
                                <input type="hidden" name="product_id" value="{$externalProductId}">
                            </form>
                        </div>
                    </div>
                </div>
                <div id="box_description"><div class="innerbox"><div itemprop="description"><p>Opis produktu medycznego {$name}.</p><p>Materiał: medyczny polimer żelowy TPE</p></div></div></div>
                <div id="box_productdata"><div class="innerbox"><table>{$attributeRows}</table></div></div>
                <div id="box_productsafety"><div class="innerbox"><p>Posiada oznaczenie CE (zgodność z normami UE).</p></div></div>
            </body>
        </html>
    HTML;
}
