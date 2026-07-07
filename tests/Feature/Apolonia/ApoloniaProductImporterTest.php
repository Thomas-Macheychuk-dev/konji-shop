<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Apolonia\ApoloniaProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps Apolonia variant SKUs stable when the same product is imported repeatedly', function (): void {
    $importer = app(ApoloniaProductImporter::class);
    $payload = apoloniaImporterProductPayload();

    $first = $importer->import($payload, ProductStatus::DRAFT, false);
    $firstProduct = $first['product'];
    $firstSkus = $firstProduct->variants()
        ->orderBy('external_variant_id')
        ->pluck('sku')
        ->all();

    expect($firstSkus)->toBe(['BL60-2970-XS', 'BL60-2970-S', 'BL60-2970-M']);

    $second = $importer->import($payload, ProductStatus::DRAFT, false);
    $secondProduct = $second['product'];
    $secondSkus = $secondProduct->variants()
        ->orderBy('external_variant_id')
        ->pluck('sku')
        ->all();

    expect($secondProduct->id)->toBe($firstProduct->id)
        ->and($secondSkus)->toBe($firstSkus)
        ->and(Product::query()->where('external_source', 'apolonia')->where('external_id', '2970')->count())->toBe(1);
});


it('qualifies Apolonia variant SKUs with the external product ID across colour products', function (): void {
    $importer = app(ApoloniaProductImporter::class);

    $first = $importer->import(apoloniaImporterProductPayload(), ProductStatus::DRAFT, false)['product'];
    $second = $importer->import(apoloniaImporterProductPayload([
        'source_url' => 'https://www.apolonia.com.pl/product-pol-2977-Bluza-medyczna-damska-jagodowa-BL-60-krotki-rekaw-Comfort-Stretch.html',
        'canonical_url' => 'https://www.apolonia.com.pl/product-pol-2977-Bluza-medyczna-damska-jagodowa-BL-60-krotki-rekaw-Comfort-Stretch.html',
        'external_product_id' => '2977',
        'slug' => 'bluza-medyczna-damska-jagodowa-bl-60-krotki-rekaw-comfort-stretch',
        'name' => 'Bluza medyczna damska jagodowa BL 60, krótki rękaw, Comfort Stretch',
        'variant_candidates' => [
            [
                'external_variant_id' => '2',
                'label' => 'XS',
                'sku' => 'BL60-XS',
                'price_gross_amount' => 19700,
                'availability' => 'Produkt na zamówienie',
                'attributes' => [
                    [
                        'label' => 'Kolor i tkanina',
                        'value' => 'Jagodowy (Comfort Stretch | K47)',
                    ],
                    [
                        'label' => 'Rozmiar',
                        'value' => 'XS',
                    ],
                ],
            ],
            [
                'external_variant_id' => '3',
                'label' => 'S',
                'sku' => 'BL60-S',
                'price_gross_amount' => 19700,
                'availability' => 'Produkt dostępny w bardzo małej ilości',
                'attributes' => [
                    [
                        'label' => 'Kolor i tkanina',
                        'value' => 'Jagodowy (Comfort Stretch | K47)',
                    ],
                    [
                        'label' => 'Rozmiar',
                        'value' => 'S',
                    ],
                ],
            ],
            [
                'external_variant_id' => '4',
                'label' => 'M',
                'sku' => 'BL60-M',
                'price_gross_amount' => 19700,
                'availability' => 'Produkt na zamówienie',
                'attributes' => [
                    [
                        'label' => 'Kolor i tkanina',
                        'value' => 'Jagodowy (Comfort Stretch | K47)',
                    ],
                    [
                        'label' => 'Rozmiar',
                        'value' => 'M',
                    ],
                ],
            ],
        ],
    ]), ProductStatus::DRAFT, false)['product'];

    expect($first->variants()->orderBy('external_variant_id')->pluck('sku')->all())
        ->toBe(['BL60-2970-XS', 'BL60-2970-S', 'BL60-2970-M'])
        ->and($second->variants()->orderBy('external_variant_id')->pluck('sku')->all())
        ->toBe(['BL60-2977-XS', 'BL60-2977-S', 'BL60-2977-M']);
});

function apoloniaImporterProductPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'apolonia',
        'source_url' => 'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
        'canonical_url' => 'https://www.apolonia.com.pl/product-pol-2970-Bluza-medyczna-damska-bordo-BL-60-krotki-rekaw-Comfort-Stretch.html',
        'external_product_id' => '2970',
        'slug' => 'bluza-medyczna-damska-bordo-bl-60-krotki-rekaw-comfort-stretch',
        'name' => 'Bluza medyczna damska bordo BL 60, krótki rękaw, Comfort Stretch',
        'sku' => 'BL60',
        'price_gross_amount' => 19700,
        'currency' => 'PLN',
        'availability' => 'Produkt dostępny w bardzo małej ilości',
        'category' => 'Bluzy medyczne damskie',
        'categories' => ['Odzież medyczna', 'Bluzy medyczne', 'Bluzy medyczne damskie'],
        'short_description' => 'Bluza medyczna damska bordo.',
        'description_html' => '<p>Opis produktu Apolonia.</p>',
        'attributes' => [
            [
                'label' => 'Symbol',
                'value' => 'BL60',
                'code' => 'symbol',
                'slug' => 'bl60',
            ],
            [
                'label' => 'Tkanina',
                'value' => 'Comfort Stretch',
                'code' => 'tkanina',
                'slug' => 'comfort-stretch',
            ],
        ],
        'variant_candidates' => [
            [
                'external_variant_id' => '2',
                'label' => 'XS',
                'sku' => 'BL60-XS',
                'price_gross_amount' => 19700,
                'availability' => 'Produkt dostępny w bardzo małej ilości',
                'attributes' => [
                    [
                        'label' => 'Kolor i tkanina',
                        'value' => 'Bordo (Comfort Stretch | K44)',
                    ],
                    [
                        'label' => 'Rozmiar',
                        'value' => 'XS',
                    ],
                ],
            ],
            [
                'external_variant_id' => '3',
                'label' => 'S',
                'sku' => 'BL60-S',
                'price_gross_amount' => 19700,
                'availability' => 'Produkt na zamówienie',
                'attributes' => [
                    [
                        'label' => 'Kolor i tkanina',
                        'value' => 'Bordo (Comfort Stretch | K44)',
                    ],
                    [
                        'label' => 'Rozmiar',
                        'value' => 'S',
                    ],
                ],
            ],
            [
                'external_variant_id' => '4',
                'label' => 'M',
                'sku' => 'BL60-M',
                'price_gross_amount' => 19700,
                'availability' => 'Produkt dostępny w bardzo małej ilości',
                'attributes' => [
                    [
                        'label' => 'Kolor i tkanina',
                        'value' => 'Bordo (Comfort Stretch | K44)',
                    ],
                    [
                        'label' => 'Rozmiar',
                        'value' => 'M',
                    ],
                ],
            ],
        ],
        'images' => [],
        'failed_urls' => [],
        'warnings' => [],
    ], $overrides);
}
