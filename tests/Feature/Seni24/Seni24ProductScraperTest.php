<?php

declare(strict_types=1);

use App\Services\Seni24\Seni24ProductScraper;
use Illuminate\Support\Facades\Http;

function seni24ProductHtml(): string
{
    return <<<'HTML'
        <html>
        <head>
            <title>Seni Super test | Seni24</title>
            <meta name="description" content="Testowy opis Seni Super.">
            <link rel="canonical" href="https://www.seni24.pl/seni-super-test_24170-26340">
            <script type="application/ld+json">
            {
                "@context":"https://schema.org",
                "@type":"Product",
                "name":"Seni Super test",
                "sku":"SE-094-XS10-G01",
                "gtin13":"5900516803704",
                "brand":{"@type":"Brand","name":"Seni"},
                "offers":{"@type":"Offer","price":"19.25","priceCurrency":"PLN"}
            }
            </script>
        </head>
        <body>
            <nav class="breadcrumb">
                <a href="/">Strona główna</a>
                <a href="/nietrzymanie-moczu/">Nietrzymanie moczu</a>
                <a href="/nietrzymanie-moczu/pieluchomajtki/">Pieluchomajtki</a>
            </nav>

            <main>
                <h1 itemprop="name">Seni Super test</h1>

                <div class="product-prices">
                    <span>Cena z VAT 5%</span>
                    <strong>19,25 zł</strong>
                </div>

                <div class="product-variants">
                    <div class="product-variants-item">
                        <span class="control-label">Rozmiar</span>
                        <select data-product-attribute="1" name="group[1]">
                            <option value="10" selected>XS</option>
                            <option value="11">S</option>
                        </select>
                    </div>

                    <div class="product-variants-item">
                        <span class="control-label">Ilość sztuk</span>
                        <select data-product-attribute="2" name="group[2]">
                            <option value="20" selected>10 szt.</option>
                        </select>
                    </div>
                </div>

                <div class="product-description">
                    <p>Opis produktu Seni Super przeznaczonego do opieki.</p>
                </div>

                <div class="product-features">
                    <dl class="data-sheet">
                        <dt>Indeks</dt><dd>SE-094-XS10-G01</dd>
                        <dt>Marka</dt><dd>Seni</dd>
                        <dt>Wyrób medyczny</dt><dd>Tak</dd>
                        <dt>Refundowany</dt><dd>Tak</dd>
                        <dt>ean13</dt><dd>5900516803704</dd>
                    </dl>
                </div>

                <div class="product-cover">
                    <img
                        data-image-large-src="https://aws-test-seni24.seni24.pl/100-large_default/seni-super-test.jpg"
                        src="https://aws-test-seni24.seni24.pl/100-home_default/seni-super-test.jpg"
                        alt="Seni Super test"
                    >
                </div>

                <div>Produkt dostępny. Dodaj do koszyka.</div>
            </main>
        </body>
        </html>
    HTML;
}

function seni24VariantFragment(
    string $sizeId,
    string $sizeLabel,
    string $sku,
    string $ean,
): string {
    $selectedXs = $sizeId === '10' ? ' selected' : '';
    $selectedS = $sizeId === '11' ? ' selected' : '';

    return <<<HTML
        <div class="product-variants">
            <div class="product-variants-item">
                <span class="control-label">Rozmiar</span>
                <select data-product-attribute="1" name="group[1]">
                    <option value="10"{$selectedXs}>XS</option>
                    <option value="11"{$selectedS}>S</option>
                </select>
            </div>
            <div class="product-variants-item">
                <span class="control-label">Ilość sztuk</span>
                <select data-product-attribute="2" name="group[2]">
                    <option value="20" selected>10 szt.</option>
                </select>
            </div>
        </div>
    HTML;
}

function seni24DetailsFragment(string $sku, string $ean): string
{
    return <<<HTML
        <div class="product-features">
            <dl class="data-sheet">
                <dt>Indeks</dt><dd>{$sku}</dd>
                <dt>ean13</dt><dd>{$ean}</dd>
            </dl>
        </div>
    HTML;
}

it('extracts stable Seni24 product identity, VAT, metadata and default variant', function (): void {
    $result = app(Seni24ProductScraper::class)->extract(
        seni24ProductHtml(),
        'https://www.seni24.pl/seni-super-test_24170-26340',
        [
            'listing_roots' => [
                'https://www.seni24.pl/nietrzymanie-moczu/',
            ],
        ],
    );

    expect($result['external_product_id'])->toBe('24170')
        ->and($result['canonical_url'])->toBe(
            'https://www.seni24.pl/seni-super-test_24170-26340',
        )
        ->and($result['name'])->toBe('Seni Super test')
        ->and($result['price_gross_amount'])->toBe(19.25)
        ->and($result['vat_rate'])->toBe(5)
        ->and($result['availability'])->toBe('in_stock')
        ->and($result['brand'])->toBe('Seni')
        ->and($result['is_medical_device'])->toBeTrue()
        ->and($result['is_refundable'])->toBeTrue()
        ->and($result['source_category_path'])->toBe([
            'Nietrzymanie moczu',
            'Pieluchomajtki',
        ])
        ->and($result['images'])->toBe([
            [
                'url' => 'https://aws-test-seni24.seni24.pl/100-large_default/seni-super-test.jpg',
                'alt' => 'Seni Super test',
            ],
        ])
        ->and($result['variant_candidates'])->toHaveCount(1)
        ->and($result['variant_candidates'][0]['external_variant_id'])->toBe('26340')
        ->and($result['variant_candidates'][0]['vat_rate'])->toBe(5)
        ->and($result['variant_resolution_complete'])->toBeFalse();
});

it('resolves the complete Seni24 PrestaShop variant matrix with per-variant price and VAT', function (): void {
    Http::fakeSequence()
        ->push(seni24ProductHtml(), 200, ['Content-Type' => 'text/html'])
        ->push([
            'product_url' => 'https://www.seni24.pl/seni-super-test_24170-26340',
            'id_product_attribute' => '26340',
            'product_prices' => '<div class="product-prices">Cena z VAT 5% <strong>19,25 zł</strong></div>',
            'product_variants' => seni24VariantFragment(
                '10',
                'XS',
                'SE-094-XS10-G01',
                '5900516803704',
            ),
            'product_details' => seni24DetailsFragment(
                'SE-094-XS10-G01',
                '5900516803704',
            ),
            'product_add_to_cart' => '<div>Produkt dostępny. Dodaj do koszyka.</div>',
        ], 200, ['Content-Type' => 'application/json'])
        ->push([
            'product_url' => 'https://www.seni24.pl/seni-super-test_24170-26341',
            'id_product_attribute' => '26341',
            'product_prices' => '<div class="product-prices">Cena z VAT 5% <strong>20,75 zł</strong></div>',
            'product_variants' => seni24VariantFragment(
                '11',
                'S',
                'SE-094-S10-G01',
                '5900516803711',
            ),
            'product_details' => seni24DetailsFragment(
                'SE-094-S10-G01',
                '5900516803711',
            ),
            'product_add_to_cart' => '<div>Produkt dostępny. Dodaj do koszyka.</div>',
        ], 200, ['Content-Type' => 'application/json']);

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape(
            'https://www.seni24.pl/seni-super-test_24170-26340',
            [
                'listing_roots' => [
                    'https://www.seni24.pl/nietrzymanie-moczu/',
                ],
            ],
        );

    expect($result['variant_resolution_complete'])->toBeTrue()
        ->and($result['warnings'])->toBe([])
        ->and($result['variant_candidates'])->toHaveCount(2)
        ->and(array_column($result['variant_candidates'], 'external_variant_id'))->toBe([
            '26340',
            '26341',
        ])
        ->and(array_column($result['variant_candidates'], 'price_gross_amount'))->toBe([
            19.25,
            20.75,
        ])
        ->and(array_column($result['variant_candidates'], 'vat_rate'))->toBe([
            5,
            5,
        ])
        ->and($result['variant_candidates'][0]['supplier_sku'])->toBe(
            'SE-094-XS10-G01',
        )
        ->and($result['variant_candidates'][1]['supplier_sku'])->toBe(
            'SE-094-S10-G01',
        );

    Http::assertSentCount(3);
});

it('marks Seni24 variant resolution incomplete when a combination cannot be resolved', function (): void {
    Http::fakeSequence()
        ->push(seni24ProductHtml(), 200)
        ->push([
            'product_url' => 'https://www.seni24.pl/seni-super-test_24170-26340',
            'id_product_attribute' => '26340',
            'product_prices' => '<div>z VAT 5% 19,25 zł</div>',
            'product_variants' => seni24VariantFragment(
                '10',
                'XS',
                'SE-094-XS10-G01',
                '5900516803704',
            ),
            'product_details' => seni24DetailsFragment(
                'SE-094-XS10-G01',
                '5900516803704',
            ),
            'product_add_to_cart' => '<div>Produkt dostępny</div>',
        ], 200)
        ->push('', 500);

    $result = app(Seni24ProductScraper::class)
        ->withRequestDelayMilliseconds(0)
        ->scrape('https://www.seni24.pl/seni-super-test_24170-26340');

    expect($result['variant_resolution_complete'])->toBeFalse()
        ->and($result['warnings'])->not->toBe([])
        ->and(collect($result['warnings'])->contains(
            fn (string $warning): bool => str_contains(
                $warning,
                'Variant refresh request failed',
            ),
        ))->toBeTrue();
});

it('rejects non-Seni24 product URLs before making HTTP requests', function (): void {
    Http::fake();

    $result = app(Seni24ProductScraper::class)->scrape(
        'https://example.com/seni-super-test_24170-26340',
    );

    expect($result['name'])->toBe('')
        ->and($result['failed_urls'])->toBe([
            'https://example.com/seni-super-test_24170-26340' => 'invalid_seni24_product_url',
        ]);

    Http::assertNothingSent();
});
