<?php

use App\Services\RelaxSan\RelaxSanProductScraper;
use Illuminate\Support\Facades\Http;

it('extracts RelaxSan product details from a Shoper product page with size and colour variants', function (): void {
    $html = relaxSanProductPageDataFixture(
        canonicalUrl: 'https://relaxsansklep.pl/pl/p/Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber/103',
        externalProductId: '103',
        name: 'Podkolanówki przeciwżylakowe profilaktyczne 70 DEN z mikrofibry ucisk 12-17 mmHg RelaxSan Microfiber',
        sku: 'Art. 750M',
        price: '53,00 zł',
        availability: 'Dostępny',
        categoryTrail: ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
    );

    $result = app(RelaxSanProductScraper::class)->extract(
        $html,
        'https://relaxsansklep.pl/pl/p/Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber/103',
    );

    expect($result)->toMatchArray([
        'source' => 'relaxsan',
        'source_url' => 'https://relaxsansklep.pl/pl/p/Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber/103',
        'canonical_url' => 'https://relaxsansklep.pl/pl/p/Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber/103',
        'external_product_id' => '103',
        'slug' => 'Podkolanowki-przeciwzylakowe-profilaktyczne-70-DEN-z-mikrofibry-ucisk-12-17-mmHg-RelaxSan-Microfiber',
        'name' => 'Podkolanówki przeciwżylakowe profilaktyczne 70 DEN z mikrofibry ucisk 12-17 mmHg RelaxSan Microfiber',
        'brand' => [
            'name' => 'RelaxSan',
            'slug' => 'relaxsan',
        ],
        'category' => 'Podkolanówki uciskowe profilaktyczne',
        'categories' => ['Przeciwżylakowe', 'Podkolanówki uciskowe', 'Podkolanówki uciskowe profilaktyczne'],
        'seo_description' => 'Profilaktyczne podkolanówki przeciwżylakowe o stopniowanym ucisku.',
        'price_gross_amount' => 53.00,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'Dostępny',
        'shipping_time' => '48 godzin',
        'sku' => 'Art. 750M',
        'is_medical_device' => true,
        'failed_urls' => [],
    ])
        ->and($result['description_html'])->toContain('Miękkie i komfortowe profilaktyczne przeciwżylakowe podkolanówki')
        ->and($result['images'])->toHaveCount(5)
        ->and($result['images'][0])->toBe([
            'url' => 'https://relaxsansklep.pl/environment/cache/images/productGfx_3198_500_500/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-ciemny-bez.webp',
            'alt' => 'Podkolanówki przeciwżylakowe profilaktyczne 70 DEN z mikrofibry ucisk 12-17 mmHg RelaxSan Microfiber',
        ])
        ->and($result['attributes'])->toContain([
            'code' => 'kod-produktu',
            'label' => 'Kod produktu',
            'value' => 'Art. 750M',
            'slug' => 'art-750m',
        ])
        ->and($result['variant_candidates'])->toHaveCount(4)
        ->and($result['variant_candidates'][0])->toMatchArray([
            'external_variant_id' => '222-227',
            'label' => 'Rozmiar: S / Kolor: Beż cielisty - kolor 36',
            'price_gross_amount' => 53.00,
            'currency' => 'PLN',
        ])
        ->and($result['variant_candidates'][0]['attributes'])->toBe([
            ['label' => 'Rozmiar', 'value' => 'S'],
            ['label' => 'Kolor', 'value' => 'Beż cielisty - kolor 36'],
        ])
        ->and($result['warnings'])->toBe([]);
});

it('normalizes RelaxSan made-to-order availability as preorder', function (): void {
    $html = relaxSanProductPageDataFixture(
        canonicalUrl: 'https://relaxsansklep.pl/pl/p/Ponczochy-uciskowe-samonosne-medyczne-RelaxSan-III-klasa-kompresji-35-46-mmHg-Linia-Classic-szyte-na-miare/270',
        externalProductId: '270',
        name: 'Pończochy uciskowe samonośne medyczne RelaxSan III klasa kompresji 35-46 mmHg - Linia Classic - szyte na miarę',
        sku: 'Art. M3470 INDW',
        price: '530,00 zł',
        availability: 'dostępny na zamówienie',
        categoryTrail: ['Przeciwżylakowe', 'Pończochy uciskowe', 'Pończochy uciskowe 3 stopnia'],
        withOptions: false,
    );

    $result = app(RelaxSanProductScraper::class)->extract(
        $html,
        'https://relaxsansklep.pl/pl/p/Ponczochy-uciskowe-samonosne-medyczne-RelaxSan-III-klasa-kompresji-35-46-mmHg-Linia-Classic-szyte-na-miare/270',
    );

    expect($result['availability'])->toBe('preorder')
        ->and($result['availability_label'])->toBe('dostępny na zamówienie')
        ->and($result['variant_candidates'])->toBe([]);
});

it('keeps RelaxSan product-link category context on scraped product payloads', function (): void {
    $html = relaxSanProductPageDataFixture(
        canonicalUrl: 'https://relaxsansklep.pl/pl/p/Pas-ciazowy-ze-srebrem-RelaxSan-RelaxMaternity-rozmiar-XXL/156',
        externalProductId: '156',
        name: 'Pas ciążowy ze srebrem RelaxSan - RelaxMaternity- rozmiar XXL',
        sku: 'Art. 5400',
        price: '159,00 zł',
        availability: 'Dostępny',
        categoryTrail: ['W ciąży', 'Bielizna ciążowa'],
    );

    $result = app(RelaxSanProductScraper::class)->extract(
        $html,
        'https://relaxsansklep.pl/pl/p/Pas-ciazowy-ze-srebrem-RelaxSan-RelaxMaternity-rozmiar-XXL/156',
        [
            'url' => 'https://relaxsansklep.pl/pl/p/Pas-ciazowy-ze-srebrem-RelaxSan-RelaxMaternity-rozmiar-XXL/156',
            'name' => 'Pas ciążowy ze srebrem RelaxSan - RelaxMaternity- rozmiar XXL',
            'category_name' => 'Bielizna ciążowa',
            'category_url' => 'https://relaxsansklep.pl/bielizna-ciazowa',
            'top_category_name' => 'W ciąży',
            'top_category_url' => 'https://relaxsansklep.pl/w-ciazy',
            'category_path' => ['W ciąży', 'Bielizna ciążowa'],
        ],
    );

    expect($result)->toMatchArray([
        'category' => 'Bielizna ciążowa',
        'source_category_name' => 'Bielizna ciążowa',
        'source_category_url' => 'https://relaxsansklep.pl/bielizna-ciazowa',
        'source_top_category_name' => 'W ciąży',
        'source_top_category_url' => 'https://relaxsansklep.pl/w-ciazy',
        'source_category_path' => ['W ciąży', 'Bielizna ciążowa'],
        'source_product_list_name' => 'Pas ciążowy ze srebrem RelaxSan - RelaxMaternity- rozmiar XXL',
    ]);
});

it('returns a failed RelaxSan product payload when the product request fails', function (): void {
    Http::fake([
        'https://relaxsansklep.pl/pl/p/Missing/999' => Http::response('', 404),
        '*' => Http::response('', 500),
    ]);

    $result = app(RelaxSanProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://relaxsansklep.pl/pl/p/Missing/999');

    expect($result)->toMatchArray([
        'source' => 'relaxsan',
        'source_url' => 'https://relaxsansklep.pl/pl/p/Missing/999',
        'external_product_id' => '999',
        'name' => '',
        'failed_urls' => [
            'https://relaxsansklep.pl/pl/p/Missing/999' => 'HTTP 404',
        ],
    ])
        ->and($result['warnings'])->toContain('Unable to fetch RelaxSan product page.');
});

function relaxSanProductPageDataFixture(
    string $canonicalUrl,
    string $externalProductId,
    string $name,
    string $sku,
    string $price,
    string $availability,
    array $categoryTrail,
    bool $withOptions = true,
): string {
    $breadcrumbs = '<a href="/">Strona główna</a>';

    foreach ($categoryTrail as $index => $label) {
        $breadcrumbs .= '<a href="/category-'.($index + 1).'">'.$label.'</a>';
    }

    $breadcrumbs .= '<span>'.$name.'</span>';
    $options = $withOptions ? <<<HTML
        <div class="stocks">
            <label for="option_7" class="label">* Rozmiar:</label>
            <div class="stock-options"><div class="option_select option_truestock option_required">
                <select id="option_7" name="option_7">
                    <option value="222">S</option>
                    <option value="223">M</option>
                </select>
            </div></div>
            <label for="option_8" class="label">* Kolor:</label>
            <div class="stock-options"><div class="option_select option_truestock option_required">
                <select id="option_8" name="option_8">
                    <option value="227">Beż cielisty - kolor 36</option>
                    <option value="228">Czarny</option>
                </select>
            </div></div>
        </div>
        HTML : '';

    return <<<HTML
        <!doctype html>
        <html lang="pl">
            <head>
                <title>{$name} | Sklep RelaxSan</title>
                <meta name="description" content="Profilaktyczne podkolanówki przeciwżylakowe o stopniowanym ucisku.">
                <meta itemprop="priceCurrency" content="PLN">
                <meta property="og:image" content="https://relaxsansklep.pl/environment/cache/images/productGfx_3198_500_500/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-ciemny-bez.webp">
                <link rel="canonical" href="{$canonicalUrl}">
            </head>
            <body id="shop_product{$externalProductId}" class="shop_product shop_product_from_cat_248">
                <ul class="path">{$breadcrumbs}</ul>
                <div id="box_productfull">
                    <div class="productimg">
                        <div class="mainimg"><img src="/environment/cache/images/productGfx_3198_500_500/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-ciemny-bez.webp?overlay=1" alt="{$name}"></div>
                        <div class="gallery">
                            <a href="/userdata/public/gfx/3198/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-ciemny-bez.jpg"><img src="/environment/cache/images/productGfx_3198_120_120/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-ciemny-bez.webp" alt="{$name}"></a>
                            <a href="/userdata/public/gfx/3199/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-czarny.jpg"><img src="/environment/cache/images/productGfx_3199_120_120/750m-podkolanowki-kompresyjne-elastyczne-Relaxsan-Microfiber-70den-czarny.webp" alt="{$name} czarny"></a>
                        </div>
                    </div>
                    <h1 itemprop="name">{$name}</h1>
                    <form class="form-basket" method="post" action="/pl/basket/add/post">
                        {$options}
                        <div class="price"><span class="price-name">Cena:</span><em class="main-price">{$price}</em><meta itemprop="priceCurrency" content="PLN"></div>
                        <input type="hidden" value="1086" name="stock_id">
                    </form>
                    <div class="productdetails-more-details clearfix">
                        <div class="productdetails-more row">
                            <div class="availability"><span class="first">Dostępność:</span><span class="second">{$availability}</span></div>
                            <div class="delivery"><span class="first">Wysyłka w:</span><span class="second">48 godzin</span></div>
                            <a href="/pl/producer/RelaxSan/40">RelaxSan</a>
                            <div class="code"><em>Kod produktu:</em><span>{$sku}</span></div>
                        </div>
                    </div>
                </div>
                <div class="box tab" id="box_description">
                    <div class="resetcss" itemprop="description">
                        <p><strong>Miękkie i komfortowe profilaktyczne przeciwżylakowe podkolanówki</strong> o stopniowanym ucisku.</p>
                    </div>
                </div>
            </body>
        </html>
        HTML;
}
