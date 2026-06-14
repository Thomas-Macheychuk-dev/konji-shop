<?php

use App\Enums\AttributeDisplayType;
use App\Enums\ProductStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Services\Eldan\EldanProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reuses existing global attribute slugs when importing Eldan attributes', function (): void {
    $wojdakColourAttribute = Attribute::query()->create([
        'external_attribute_id' => 'wojdak-kolory',
        'name' => 'Kolor',
        'slug' => 'kolor',
        'display_type' => AttributeDisplayType::COLOR_SWATCH,
    ]);

    $existingBlackValue = AttributeValue::query()->create([
        'attribute_id' => $wojdakColourAttribute->id,
        'external_option_id' => 'wojdak-kolory-czarny',
        'value' => 'czarny',
        'slug' => 'czarny',
        'swatch_type' => 'color',
        'swatch_value' => '#000000',
        'sort_order' => 0,
    ]);

    $product = app(EldanProductImporter::class)->import([
        'external_id' => 50,
        'external_parent_sku' => '1125-configurable',
        'name' => 'elegancka medyczna tunika krótki rękaw BIANCA',
        'slug' => '1125-elegancka-medyczna-tunika-krotki-rekaw-bianca',
        'short_description_html' => '<p>elegancka medyczna tunika krótki rękaw</p>',
        'description_html' => '<p>Bluza medyczna z krótkim rękawem.</p>',
        'images' => [],
        'attributes' => [
            [
                'external_attribute_id' => 23,
                'code' => 'color',
                'name' => 'Kolor',
                'swatch_type' => 'color',
                'options' => [
                    [
                        'external_option_id' => 4,
                        'label' => 'czarny',
                        'swatch_value' => '#000000',
                    ],
                    [
                        'external_option_id' => 5,
                        'label' => 'biały',
                        'swatch_value' => '#ffffff',
                    ],
                ],
            ],
            [
                'external_attribute_id' => 24,
                'code' => 'size',
                'name' => 'Rozmiar',
                'swatch_type' => 'text',
                'options' => [
                    [
                        'external_option_id' => 10,
                        'label' => 'XS',
                        'swatch_value' => null,
                    ],
                ],
            ],
        ],
        'variants' => [
            [
                'external_variant_id' => 56,
                'sku' => null,
                'price' => [
                    'amount_minor' => 22000,
                ],
                'attributes' => [
                    [
                        'external_attribute_id' => 23,
                        'external_option_id' => 4,
                        'code' => 'color',
                        'name' => 'Kolor',
                        'value' => 'czarny',
                        'swatch_type' => 'color',
                        'swatch_value' => '#000000',
                    ],
                    [
                        'external_attribute_id' => 24,
                        'external_option_id' => 10,
                        'code' => 'size',
                        'name' => 'Rozmiar',
                        'value' => 'XS',
                        'swatch_type' => 'text',
                        'swatch_value' => null,
                    ],
                ],
                'images' => [],
            ],
        ],
    ]);

    $product->load('variants.attributeValues.attribute');

    expect($product->status)->toBe(ProductStatus::ACTIVE)
        ->and(Attribute::query()->where('slug', 'kolor')->count())->toBe(1)
        ->and($wojdakColourAttribute->fresh()->external_attribute_id)->toBe('wojdak-kolory')
        ->and(Attribute::query()->where('external_attribute_id', '23')->exists())->toBeFalse()
        ->and($existingBlackValue->fresh()->external_option_id)->toBe('wojdak-kolory-czarny')
        ->and($product->variants)->toHaveCount(1)
        ->and($product->variants->first()->attributeValues->pluck('value')->all())->toBe([
            'czarny',
            'XS',
        ]);
});
