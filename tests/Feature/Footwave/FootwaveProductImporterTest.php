<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Product;
use App\Services\Footwave\FootwaveProductImporter;
use App\Services\Footwave\FootwaveProductScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('imports a simple FootWave product as draft with explicit VAT', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products/21697' =>
            Http::response(footwaveImporterHardBallFixture()),
    ]);

    $scraped = app(FootwaveProductScraper::class)
        ->scrapeById(21697);

    $product = app(FootwaveProductImporter::class)
        ->import(
            $scraped,
            VatRate::VAT_23,
            false,
        );

    expect($product->external_source)->toBe('footwave')
        ->and($product->external_id)->toBe('21697')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_parent_sku)->toBe('HARD_BALL')
        ->and($product->variants)->toHaveCount(1);

    $variant = $product->variants->firstOrFail();

    expect($variant->external_variant_id)
        ->toBe('footwave-21697-21697')
        ->and($variant->sku)
        ->toBe('HARD_BALL')
        ->and($variant->status)
        ->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->stock_status)
        ->toBe(StockStatus::IN_STOCK)
        ->and($variant->vat_rate)
        ->toBe(VatRate::VAT_23)
        ->and($variant->price_gross_amount)
        ->toBe(1749)
        ->and($variant->price_net_amount)
        ->toBe(VatRate::VAT_23->netFromGross(1749));
});

it('imports authoritative FootWave size variants without duplicate size dimensions', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products/372' =>
            Http::response(footwaveImporterKidsParentFixture()),

        'https://footwave.pl/wp-json/wc/store/v1/products/17063' =>
            Http::response(
                footwaveImporterKidsVariationFixture(
                    17063,
                    '3xsk-24-25',
                    'FW_07020248_3XSK',
                )
            ),

        'https://footwave.pl/wp-json/wc/store/v1/products/17062' =>
            Http::response(
                footwaveImporterKidsVariationFixture(
                    17062,
                    '2xsk-26-27',
                    'FW_07020248_2XSK',
                )
            ),

        '*' => Http::response([], 404),
    ]);

    $scraped = app(FootwaveProductScraper::class)
        ->scrapeById(372);

    $product = app(FootwaveProductImporter::class)
        ->import(
            $scraped,
            VatRate::VAT_8,
            false,
        );

    expect($product->variants)->toHaveCount(2);

    $first = $product->variants
        ->firstWhere(
            'external_variant_id',
            'footwave-372-17063'
        );

    expect($first)->not->toBeNull();

    $attributes = $first->attributeValues
        ->map(
            fn ($value): string =>
                $value->attribute->name.
                '='.$value->value
        )
        ->values()
        ->all();

    expect($attributes)
        ->toBe([
            'Rozmiar=3XSK (24-25)',
        ]);
});

it('is idempotent and preserves existing product and variant lifecycle statuses', function (): void {
    Http::fake([
        'https://footwave.pl/wp-json/wc/store/v1/products/21697' =>
            Http::response(footwaveImporterHardBallFixture()),
    ]);

    $scraped = app(FootwaveProductScraper::class)
        ->scrapeById(21697);

    $importer = app(FootwaveProductImporter::class);

    $product = $importer->import(
        $scraped,
        VatRate::VAT_23,
        false,
    );

    $product->update([
        'status' => ProductStatus::ACTIVE,
    ]);

    $variant = $product->variants->firstOrFail();

    $variant->update([
        'status' => ProductVariantStatus::ACTIVE,
    ]);

    $again = $importer->import(
        $scraped,
        VatRate::VAT_23,
        false,
    );

    expect(
        Product::query()
            ->where('external_source', 'footwave')
            ->count()
    )->toBe(1)
        ->and($again->status)
        ->toBe(ProductStatus::ACTIVE)
        ->and($again->variants)
        ->toHaveCount(1)
        ->and($again->variants->first()->status)
        ->toBe(ProductVariantStatus::ACTIVE);
});

it('refuses to import an ineligible FootWave source payload', function (): void {
    $scraped = [
        'source' => 'footwave',
        'external_product_id' => '33565',
        'eligible' => false,
        'variants_unresolved' => false,
        'variants' => [],
    ];

    expect(
        fn () =>
            app(FootwaveProductImporter::class)
                ->import(
                    $scraped,
                    VatRate::VAT_23,
                    false,
                )
    )->toThrow(RuntimeException::class);
});

function footwaveImporterHardBallFixture(): array
{
    return [
        'id' => 21697,
        'name' =>
            'FOOTWAVE™ HARD BALL Twarda piłka do masażu stóp',
        'slug' =>
            'footwave-hard-ball-twarda-pilka-do-masazu-stop',
        'type' => 'simple',
        'permalink' =>
            'https://footwave.pl/inne-produkty/'.
            'akcesoria-do-cwiczen/'.
            'footwave-hard-ball-twarda-pilka-do-masazu-stop/',
        'sku' => 'HARD_BALL',
        'short_description' => '<p>Krótki opis.</p>',
        'description' => '<p>Opis.</p>',
        'prices' => [
            'price' => '1749',
            'regular_price' => '1749',
            'currency_code' => 'PLN',
        ],
        'images' => [],
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

function footwaveImporterKidsParentFixture(): array
{
    return [
        'id' => 372,
        'name' => 'FootWave™ KIDS',
        'slug' => 'footwave-kids',
        'type' => 'variable',
        'permalink' =>
            'https://footwave.pl/wkladki-ortopedyczne/footwave-kids/',
        'sku' => 'FW_07020248',
        'short_description' => '<p>KIDS</p>',
        'description' => '<p>Opis KIDS</p>',
        'prices' => [
            'price' => '9900',
            'regular_price' => '9900',
            'currency_code' => 'PLN',
        ],
        'images' => [],
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
        'is_purchasable' => true,
        'is_in_stock' => true,
    ];
}

function footwaveImporterKidsVariationFixture(
    int $id,
    string $size,
    string $sku,
): array {
    return [
        'id' => $id,
        'name' => 'FootWave™ KIDS',
        'slug' => 'variation-'.$id,
        'parent' => 372,
        'type' => 'variation',
        'permalink' =>
            'https://footwave.pl/wkladki-ortopedyczne/'.
            'footwave-kids/?attribute_pa_rozmiar='.$size,
        'sku' => $sku,
        'prices' => [
            'price' => '9900',
            'regular_price' => '9900',
            'currency_code' => 'PLN',
        ],
        'is_purchasable' => true,
        'is_in_stock' => true,
        'add_to_cart' => [
            'maximum' => 100,
        ],
    ];
}
