<?php

use App\Services\Footwave\FootwaveProductScraper;
use App\Services\Footwave\FootwaveStoreApiClient;
use Illuminate\Support\Facades\Http;

it('discovers only top-level FootWave products from the Store API catalogue', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products?*' =>
            Http::response([
                [
                    'id' => 372,
                    'type' => 'variable',
                    'name' => 'FootWave™ KIDS',
                ],
                [
                    'id' => 21697,
                    'type' => 'simple',
                    'name' => 'FOOTWAVE™ HARD BALL',
                ],
                [
                    'id' => 17063,
                    'type' => 'variation',
                    'parent' => 372,
                    'name' => 'FootWave™ KIDS',
                ],
            ], 200, [
                'X-WP-Total' => '3',
                'X-WP-TotalPages' => '1',
            ]),
    ]);

    $products = app(FootwaveStoreApiClient::class)->catalogue();

    expect($products)
        ->toHaveCount(2)
        ->and(array_column($products, 'id'))
        ->toBe([372, 21697]);
});

it('hydrates authoritative FootWave variable product variants from the Store API', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products/372' =>
            Http::response(footwaveKidsParentFixture()),

        'https://footwave.pl/wp-json/wc/store/v1/products/17063' =>
            Http::response(footwaveKidsVariationFixture(
                id: 17063,
                size: '3XSK (24-25)',
                slug: '3xsk-24-25',
                sku: 'FW_07020248_3XSK',
                inStock: true,
                maxQty: 580,
            )),

        'https://footwave.pl/wp-json/wc/store/v1/products/17062' =>
            Http::response(footwaveKidsVariationFixture(
                id: 17062,
                size: '2XSK (26-27)',
                slug: '2xsk-26-27',
                sku: 'FW_07020248_2XSK',
                inStock: false,
                maxQty: 1,
            )),

        '*' => Http::response([], 404),
    ]);

    $result = app(FootwaveProductScraper::class)
        ->scrapeById(372);

    expect($result['external_product_id'])->toBe('372')
        ->and($result['external_parent_sku'])->toBe('FW_07020248')
        ->and($result['source_type'])->toBe('variable')
        ->and($result['eligible'])->toBeTrue()
        ->and($result['variants_unresolved'])->toBeFalse()
        ->and($result['exclusion_reason'])->toBeNull()
        ->and($result['variants'])->toHaveCount(2);

    expect($result['variants'][0])
        ->toMatchArray([
            'external_variant_id' => '17063',
            'sku' => 'FW_07020248_3XSK',
            'price_gross_amount' => 9900,
            'regular_price_gross_amount' => 9900,
            'currency' => 'PLN',
            'stock_status' => 'in_stock',
            'is_purchasable' => true,
            'source_max_qty' => 580,
        ]);

    expect($result['variants'][0]['attributes'])
        ->toBe([
            [
                'code' => 'rozmiar',
                'name' => 'Rozmiar',
                'value' => '3XSK (24-25)',
                'external_attribute_id' => 'footwave-pa_rozmiar',
                'external_option_id' => 'footwave-term-173',
                'sort_order' => 0,
                'source_value' => '3xsk-24-25',
            ],
        ]);

    expect($result['variants'][1]['attributes'][0]['value'])
        ->toBe('2XSK (26-27)')
        ->and($result['variants'][1]['stock_status'])
        ->toBe('out_of_stock');
});

it('normalizes a purchasable simple FootWave product into one authoritative variant', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products/21697' =>
            Http::response(footwaveHardBallFixture()),
    ]);

    $result = app(FootwaveProductScraper::class)
        ->scrapeById(21697);

    expect($result['eligible'])->toBeTrue()
        ->and($result['external_product_id'])->toBe('21697')
        ->and($result['name'])
        ->toBe('FOOTWAVE™ HARD BALL Twarda piłka do masażu stóp')
        ->and($result['variants'])->toHaveCount(1);

    expect($result['variants'][0])
        ->toMatchArray([
            'external_variant_id' => '21697',
            'sku' => 'HARD_BALL',
            'attributes' => [],
            'price_gross_amount' => 1749,
            'regular_price_gross_amount' => 1749,
            'currency' => 'PLN',
            'stock_status' => 'in_stock',
            'is_purchasable' => true,
            'source_max_qty' => 371,
        ]);
});

it('excludes non-purchasable FootWave catalogue products before hydrating their variations', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products/33565' =>
            Http::response([
                'id' => 33565,
                'name' => 'FootWave™ EXPERT_53',
                'slug' => 'footwave-expert_53',
                'type' => 'variable',
                'sku' => '',
                'permalink' =>
                    'https://footwave.pl/wkladki-indywidualne/footwave-expert_53/',
                'is_purchasable' => false,
                'is_in_stock' => false,
                'variations' => [
                    ['id' => 99999],
                ],
                'images' => [],
                'categories' => [],
            ]),

        '*' => Http::response([], 500),
    ]);

    $result = app(FootwaveProductScraper::class)
        ->scrapeById(33565);

    expect($result['eligible'])->toBeFalse()
        ->and($result['exclusion_reason'])->toBe('not_purchasable')
        ->and($result['variants'])->toBe([])
        ->and($result['variants_unresolved'])->toBeFalse();

    Http::assertNotSent(
        fn ($request): bool =>
            str_contains($request->url(), '/products/99999')
    );
});

function footwaveKidsParentFixture(): array
{
    return [
        'id' => 372,
        'name' => 'FootWave™ KIDS',
        'slug' => 'footwave-kids',
        'parent' => 0,
        'type' => 'variable',
        'permalink' =>
            'https://footwave.pl/wkladki-ortopedyczne/footwave-kids/',
        'sku' => 'FW_07020248',
        'short_description' =>
            '<p>POZIOM 1: KOŚLAWOŚĆ STÓP I KOLAN</p>',
        'description' => '<p>Opis FootWave KIDS</p>',
        'prices' => [
            'price' => '9900',
            'regular_price' => '9900',
            'sale_price' => '9900',
            'currency_code' => 'PLN',
            'currency_minor_unit' => 2,
        ],
        'images' => [
            [
                'id' => 19919,
                'src' =>
                    'https://footwave.pl/wp-content/uploads/2021/08/kids.png',
            ],
        ],
        'categories' => [
            [
                'id' => 150,
                'name' => 'Dla rodziny',
                'slug' => 'dla-rodziny',
            ],
        ],
        'attributes' => [
            [
                'id' => 3,
                'name' => 'Rozmiar',
                'taxonomy' => 'pa_rozmiar',
                'has_variations' => true,
                'terms' => [
                    [
                        'id' => 173,
                        'name' => '3XSK (24-25)',
                        'slug' => '3xsk-24-25',
                    ],
                    [
                        'id' => 174,
                        'name' => '2XSK (26-27)',
                        'slug' => '2xsk-26-27',
                    ],
                ],
            ],
        ],
        'variations' => [
            [
                'id' => 17063,
                'attributes' => [
                    [
                        'name' => 'Rozmiar',
                        'value' => '3xsk-24-25',
                    ],
                ],
            ],
            [
                'id' => 17062,
                'attributes' => [
                    [
                        'name' => 'Rozmiar',
                        'value' => '2xsk-26-27',
                    ],
                ],
            ],
        ],
        'has_options' => true,
        'is_purchasable' => true,
        'is_in_stock' => true,
    ];
}

function footwaveKidsVariationFixture(
    int $id,
    string $size,
    string $slug,
    string $sku,
    bool $inStock,
    int $maxQty,
): array {
    return [
        'id' => $id,
        'name' => 'FootWave™ KIDS',
        'slug' => 'variation-'.$id,
        'parent' => 372,
        'type' => 'variation',
        'variation' => 'Rozmiar: '.$size,
        'permalink' =>
            'https://footwave.pl/wkladki-ortopedyczne/footwave-kids/'.
            '?attribute_pa_rozmiar='.$slug,
        'sku' => $sku,
        'prices' => [
            'price' => '9900',
            'regular_price' => '9900',
            'sale_price' => '9900',
            'currency_code' => 'PLN',
            'currency_minor_unit' => 2,
        ],
        'is_purchasable' => true,
        'is_in_stock' => $inStock,
        'add_to_cart' => [
            'maximum' => $maxQty,
        ],
    ];
}

function footwaveHardBallFixture(): array
{
    return [
        'id' => 21697,
        'name' =>
            'FOOTWAVE™ HARD BALL Twarda piłka do masażu stóp',
        'slug' =>
            'footwave-hard-ball-twarda-pilka-do-masazu-stop',
        'parent' => 0,
        'type' => 'simple',
        'permalink' =>
            'https://footwave.pl/inne-produkty/akcesoria-do-cwiczen/'.
            'footwave-hard-ball-twarda-pilka-do-masazu-stop/',
        'sku' => 'HARD_BALL',
        'short_description' => '<p>Krótki opis.</p>',
        'description' => '<p>Opis.</p>',
        'prices' => [
            'price' => '1749',
            'regular_price' => '1749',
            'sale_price' => '1749',
            'currency_code' => 'PLN',
            'currency_minor_unit' => 2,
        ],
        'images' => [
            [
                'id' => 21698,
                'src' =>
                    'https://footwave.pl/wp-content/uploads/2025/04/'.
                    'footwave-hard-ball.png',
            ],
        ],
        'categories' => [
            [
                'id' => 161,
                'name' => 'Akcesoria do ćwiczeń',
                'slug' => 'akcesoria-do-cwiczen',
            ],
            [
                'id' => 159,
                'name' => 'Inne produkty',
                'slug' => 'inne-produkty',
            ],
        ],
        'attributes' => [],
        'variations' => [],
        'is_purchasable' => true,
        'is_in_stock' => true,
        'add_to_cart' => [
            'maximum' => 371,
        ],
    ];
}
