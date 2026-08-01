<?php

use App\Enums\AttributeDisplayType;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValueImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('shows Vermeiren product colours as informational image swatches without duplicating the description section', function (): void {
    Storage::fake('public');

    $product = Product::query()->create([
        'name' => 'eclipsx4 90° Komfort',
        'slug' => 'eclipsx4-90-komfort',
        'description' => implode('', [
            '<p>Opis produktu Vermeiren.</p>',
            '<section class="vermeiren-colors">',
            '<h2>Dostępne kolory</h2>',
            '<h3>Kolor tapicerki</h3>',
            '<ul><li>Dartex szary</li></ul>',
            '<h3>Kolor ramy</h3>',
            '<ul><li>C29 szary</li></ul>',
            '</section>',
            '<section class="vermeiren-options">',
            '<h2>Opcje dodatkowe</h2>',
            '<ul><li>B03 podłokietnik ścięty</li></ul>',
            '</section>',
        ]),
        'status' => ProductStatus::ACTIVE,
        'external_source' => 'vermeiren',
        'external_id' => hash('sha256', 'eclipsx4-90-komfort'),
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'VERMEIREN-ECLIPSX4-DEFAULT',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => null,
        'price_gross_amount' => null,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_8,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    $upholsteryAttribute = Attribute::query()->create([
        'external_attribute_id' => 'vermeiren-color-upholstery',
        'name' => 'Kolor tapicerki',
        'slug' => 'kolor-tapicerki',
        'display_type' => AttributeDisplayType::COLOR_SWATCH,
    ]);

    $frameAttribute = Attribute::query()->create([
        'external_attribute_id' => 'vermeiren-color-frame',
        'name' => 'Kolor ramy',
        'slug' => 'kolor-ramy',
        'display_type' => AttributeDisplayType::COLOR_SWATCH,
    ]);

    $upholstery = AttributeValue::query()->create([
        'attribute_id' => $upholsteryAttribute->id,
        'external_option_id' => 'vermeiren-color-upholstery-dartex-grey',
        'value' => 'Dartex szary',
        'slug' => 'dartex-szary',
        'sort_order' => 0,
    ]);

    $frame = AttributeValue::query()->create([
        'attribute_id' => $frameAttribute->id,
        'external_option_id' => 'vermeiren-color-frame-c29-grey',
        'value' => 'C29 szary',
        'slug' => 'c29-szary',
        'sort_order' => 1,
    ]);

    $product->attributeValues()->sync([
        $upholstery->id,
        $frame->id,
    ]);

    Storage::disk('public')->put('products/vermeiren/eclipsx4/colors/upholstery/dartex-grey.jpg', 'dartex');
    Storage::disk('public')->put('products/vermeiren/eclipsx4/colors/frame/c29-grey.jpg', 'c29');

    ProductAttributeValueImage::query()->create([
        'product_id' => $product->id,
        'attribute_value_id' => $upholstery->id,
        'disk' => 'public',
        'path' => 'products/vermeiren/eclipsx4/colors/upholstery/dartex-grey.jpg',
        'source_url' => 'https://www.vermeiren.pl/dartex-grey.jpg',
        'alt_text' => 'Dartex szary',
        'title' => 'Dartex szary',
        'sort_order' => 0,
    ]);

    ProductAttributeValueImage::query()->create([
        'product_id' => $product->id,
        'attribute_value_id' => $frame->id,
        'disk' => 'public',
        'path' => 'products/vermeiren/eclipsx4/colors/frame/c29-grey.jpg',
        'source_url' => 'https://www.vermeiren.pl/c29-grey.jpg',
        'alt_text' => 'C29 szary',
        'title' => 'C29 szary',
        'sort_order' => 1,
    ]);

    $response = $this
        ->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Dostępne kolory')
        ->assertSee('Kolor tapicerki')
        ->assertSee('Dartex szary')
        ->assertSee('Kolor ramy')
        ->assertSee('C29 szary')
        ->assertSee(Storage::disk('public')->url('products/vermeiren/eclipsx4/colors/upholstery/dartex-grey.jpg'), false)
        ->assertSee(Storage::disk('public')->url('products/vermeiren/eclipsx4/colors/frame/c29-grey.jpg'), false)
        ->assertSee('Opcje dodatkowe')
        ->assertSee('B03 podłokietnik ścięty')
        ->assertSee('"informational_color_groups"', false)
        ->assertDontSee('class="vermeiren-colors"', false);

    $response->assertViewHas('informationalColorGroups', function (array $groups): bool {
        return count($groups) === 2
            && $groups[0]['code'] === 'upholstery'
            && $groups[0]['values'][0]['label'] === 'Dartex szary'
            && $groups[1]['code'] === 'frame'
            && $groups[1]['values'][0]['label'] === 'C29 szary';
    });
});
