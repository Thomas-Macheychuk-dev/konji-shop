<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function labelledAttribute(string $name, string $slug, array $values): array
{
    $attribute = Attribute::query()->create(['name' => $name, 'slug' => $slug]);

    return array_map(fn (string $value): AttributeValue => AttributeValue::query()->create([
        'attribute_id' => $attribute->id,
        'value' => $value,
        'slug' => str($value)->slug()->value(),
    ]), $values);
}

it('shows shopper-facing option group and value labels on the product page', function (): void {
    $product = Product::query()->create([
        'name' => 'Bandaż kompresyjny',
        'slug' => 'bandaz-kompresyjny',
        'status' => ProductStatus::ACTIVE,
    ]);
    $groups = [
        labelledAttribute('mag - uni / spec', 'mag-uni-spec', ['specjalny', 'uniwersalny']),
        labelledAttribute('* Która dłoń?', 'ktora-dlon', ['lewa', 'prawa']),
        labelledAttribute('Kolor i tkanina', 'kolor-i-tkanina', ['Biały (Comfort Stretch | K022)', 'Czarny']),
        labelledAttribute('Herdegen 26 / 12345678', 'herdegen-12345678-26', ['czarny', 'niebieski']),
        labelledAttribute('* Rodzaj zapięcia', 'rodzaj-zapiecia', ['With latex', 'rzep']),
    ];

    foreach ([0, 1] as $i) {
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => 'LABEL-'.$i,
            'status' => ProductVariantStatus::ACTIVE,
            'price_net_amount' => 1000,
            'currency' => Currency::PLN,
            'vat_rate' => VatRate::VAT_23,
            'stock_status' => StockStatus::IN_STOCK,
            'is_default' => $i === 0,
        ]);
        $variant->attributeValues()->attach(array_map(fn (array $values): int => $values[$i]->id, $groups));
    }

    $optionGroups = collect($this->get(route('products.show', $product->slug))
        ->assertOk()
        ->viewData('productPayload')['option_groups'])
        ->mapWithKeys(fn (array $group): array => [$group['label'] => array_column($group['values'], 'label')])
        ->all();

    expect($optionGroups)->toEqual([
        'Kolor i tkanina' => ['Biały (Comfort Stretch | K022)', 'Czarny'],
        'Rozmiar' => ['Specjalny', 'Uniwersalny'],
        'Strona' => ['lewa', 'prawa'],
        'Kolor' => ['czarny', 'niebieski'],
        'Rodzaj zapięcia' => ['Z lateksem', 'rzep'],
    ]);
});

it('keeps the supplier name in the database and only relabels it for shoppers', function (): void {
    [$value] = labelledAttribute('Rzomiar', 'rzomiar', ['M']);
    $attribute = $value->attribute;

    expect($attribute->name)->toBe('Rzomiar')
        ->and($attribute->storefrontName())->toBe('Rozmiar')
        ->and(Attribute::query()->where('name', 'Rzomiar')->exists())->toBeTrue();
});

it('reports configured slugs missing from the database', function (): void {
    config()->set('storefront_labels.attributes', [
        'mag-uni-spec' => 'Rozmiar',
        'no-such-attribute' => 'Kolor',
    ]);
    labelledAttribute('mag - uni / spec', 'mag-uni-spec', ['specjalny']);

    $this->artisan('storefront:check-option-labels')
        ->expectsOutputToContain('no-such-attribute')
        ->assertFailed();
});
