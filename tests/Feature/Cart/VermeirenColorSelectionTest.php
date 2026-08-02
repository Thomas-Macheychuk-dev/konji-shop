<?php

use App\Enums\AttributeDisplayType;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createSelectableVermeirenProduct(): array
{
    $product = Product::query()->create([
        'name' => 'V500 30',
        'slug' => 'v500-30',
        'status' => ProductStatus::ACTIVE,
        'external_source' => 'vermeiren',
        'external_id' => hash('sha256', 'v500-30'),
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'VERMEIREN-V500-30-DEFAULT',
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 9259,
        'price_gross_amount' => 10000,
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
        'external_option_id' => 'vermeiren-color-upholstery-black-nylon',
        'value' => 'Nylon czarny',
        'slug' => 'nylon-czarny',
        'sort_order' => 0,
    ]);

    $frameBlue = AttributeValue::query()->create([
        'attribute_id' => $frameAttribute->id,
        'external_option_id' => 'vermeiren-color-frame-c80-blue',
        'value' => 'C80 niebieski',
        'slug' => 'c80-niebieski',
        'sort_order' => 0,
    ]);

    $frameRed = AttributeValue::query()->create([
        'attribute_id' => $frameAttribute->id,
        'external_option_id' => 'vermeiren-color-frame-c81-red',
        'value' => 'C81 czerwony',
        'slug' => 'c81-czerwony',
        'sort_order' => 1,
    ]);

    $product->attributeValues()->sync([
        $upholstery->id,
        $frameBlue->id,
        $frameRed->id,
    ]);

    return compact(
        'product',
        'variant',
        'upholstery',
        'frameBlue',
        'frameRed',
    );
}

it('requires every Vermeiren colour group before adding the product to the cart', function (): void {
    $state = createSelectableVermeirenProduct();
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('products.show', $state['product']->slug))
        ->post(route('cart.items.store'), [
            'product_variant_id' => $state['variant']->id,
            'quantity' => 1,
        ])
        ->assertRedirect(route('products.show', $state['product']->slug))
        ->assertSessionHasErrors([
            'informational_colors.upholstery',
            'informational_colors.frame',
        ]);

    $this->assertDatabaseCount('cart_items', 0);
});

it('stores Vermeiren colour selections and keeps different configurations as separate cart lines', function (): void {
    $state = createSelectableVermeirenProduct();
    $user = User::factory()->create();

    $basePayload = [
        'product_variant_id' => $state['variant']->id,
        'quantity' => 1,
        'informational_colors' => [
            'upholstery' => $state['upholstery']->id,
            'frame' => $state['frameBlue']->id,
        ],
    ];

    $this->actingAs($user)
        ->post(route('cart.items.store'), $basePayload)
        ->assertRedirect(route('cart.show'));

    $blueItem = CartItem::query()->firstOrFail();

    expect($blueItem->configuration_hash)->not->toBe('')
        ->and($blueItem->selectedOptionsLabel())
        ->toBe('Kolor tapicerki: Nylon czarny, Kolor ramy: C80 niebieski');

    $redPayload = $basePayload;
    $redPayload['informational_colors']['frame'] = $state['frameRed']->id;

    $this->actingAs($user)
        ->post(route('cart.items.store'), $redPayload)
        ->assertRedirect(route('cart.show'));

    $this->assertDatabaseCount('cart_items', 2);

    $this->actingAs($user)
        ->post(route('cart.items.store'), [
            ...$redPayload,
            'quantity' => 2,
        ])
        ->assertRedirect(route('cart.show'));

    $this->assertDatabaseCount('cart_items', 2);

    $redItem = CartItem::query()
        ->where('configuration_hash', '!=', $blueItem->configuration_hash)
        ->firstOrFail();

    expect($redItem->quantity)->toBe(3)
        ->and($redItem->selectedOptionsLabel())
        ->toBe('Kolor tapicerki: Nylon czarny, Kolor ramy: C81 czerwony');

    $this->actingAs($user)
        ->get(route('cart.show'))
        ->assertOk()
        ->assertSee('Kolor tapicerki: Nylon czarny, Kolor ramy: C80 niebieski')
        ->assertSee('Kolor tapicerki: Nylon czarny, Kolor ramy: C81 czerwony');
});

it('rejects a Vermeiren colour value that does not belong to the product group', function (): void {
    $state = createSelectableVermeirenProduct();
    $user = User::factory()->create();

    $otherAttribute = Attribute::query()->create([
        'external_attribute_id' => 'vermeiren-color-frame-other',
        'name' => 'Inny kolor',
        'slug' => 'inny-kolor',
        'display_type' => AttributeDisplayType::COLOR_SWATCH,
    ]);

    $foreignValue = AttributeValue::query()->create([
        'attribute_id' => $otherAttribute->id,
        'external_option_id' => 'foreign-frame-color',
        'value' => 'Obcy kolor',
        'slug' => 'obcy-kolor',
    ]);

    $this->actingAs($user)
        ->post(route('cart.items.store'), [
            'product_variant_id' => $state['variant']->id,
            'quantity' => 1,
            'informational_colors' => [
                'upholstery' => $state['upholstery']->id,
                'frame' => $foreignValue->id,
            ],
        ])
        ->assertSessionHasErrors('informational_colors.frame');

    $this->assertDatabaseCount('cart_items', 0);
});
