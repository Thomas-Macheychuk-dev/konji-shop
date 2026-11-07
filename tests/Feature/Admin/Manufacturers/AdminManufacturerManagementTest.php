<?php

use App\Enums\ProductStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function manufacturerAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/**
 * A brand value of the given attribute with this many active products and one archived product.
 */
function brandWithProducts(string $brand, int $activeProducts, string $attributeSlug = 'producent'): AttributeValue
{
    $attribute = Attribute::query()->firstOrCreate(['slug' => $attributeSlug], ['name' => ucfirst($attributeSlug)]);
    $value = AttributeValue::query()->create([
        'attribute_id' => $attribute->id,
        'value' => $brand,
        'slug' => str($brand)->slug()->toString(),
    ]);

    foreach (range(0, $activeProducts) as $index) {
        $product = Product::query()->create([
            'name' => $brand.' produkt '.$index,
            'slug' => str($brand)->slug().'-produkt-'.$index,
            'status' => $index === 0 ? ProductStatus::ARCHIVED : ProductStatus::ACTIVE,
        ]);
        $product->attributeValues()->attach($value);
    }

    return $value;
}

/**
 * @return array<string, string>
 */
function manufacturerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Zamst Co., Ltd.',
        'address' => "1-2-3 Osaka\nJaponia",
        'email' => 'info@zamst.example',
        'phone' => '',
        'website' => '',
        'eu_representative_name' => 'Zamst Europe B.V.',
        'eu_representative_address' => "Keizersgracht 1\nAmsterdam",
        'eu_representative_email' => '',
        'safety_information' => '',
    ], $overrides);
}

it('lists brands with active products, most products first, with their coverage', function (): void {
    $zamst = brandWithProducts('Zamst', 3);
    brandWithProducts('Medi', 5, 'marka');
    $archivedOnly = brandWithProducts('Stara marka', 0);
    Manufacturer::query()->create(['attribute_value_id' => $zamst->id, 'name' => 'Zamst Co., Ltd.', 'address' => 'Osaka', 'email' => 'a@example.com']);
    Product::query()->create(['name' => 'Bez marki', 'slug' => 'bez-marki', 'status' => ProductStatus::ACTIVE]);

    $this->actingAs(manufacturerAdmin())
        ->get(route('admin.manufacturers.index'))
        ->assertOk()
        ->assertSeeInOrder(['Medi', 'Brak', 'Zamst', 'Uzupełnione', 'Zamst Co., Ltd.'])
        ->assertDontSee($archivedOnly->value)
        ->assertSee('3 / 9')
        ->assertSee(route('admin.manufacturers.edit', $zamst), false);

    $this->actingAs(manufacturerAdmin())
        ->get(route('admin.manufacturers.index', ['missing' => 1]))
        ->assertOk()
        ->assertSee('Medi')
        ->assertDontSee(route('admin.manufacturers.edit', $zamst), false);
});

it('keeps manufacturer management away from customers and guests', function (): void {
    $brand = brandWithProducts('Zamst', 1);

    $this->get(route('admin.manufacturers.index'))->assertRedirect(route('login'));

    $customer = User::factory()->create(['is_admin' => false]);

    $this->actingAs($customer)->get(route('admin.manufacturers.index'))->assertForbidden();
    $this->actingAs($customer)->get(route('admin.manufacturers.edit', $brand))->assertForbidden();
    $this->actingAs($customer)->patch(route('admin.manufacturers.update', $brand), manufacturerPayload())->assertForbidden();

    expect(Manufacturer::query()->count())->toBe(0);
});

it('saves and updates the manufacturer details of a brand', function (): void {
    $brand = brandWithProducts('Zamst', 2);

    $this->actingAs(manufacturerAdmin())
        ->get(route('admin.manufacturers.edit', $brand))
        ->assertOk()
        ->assertSee('value="Zamst"', false);

    $this->actingAs(manufacturerAdmin())
        ->patch(route('admin.manufacturers.update', $brand), manufacturerPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $manufacturer = $brand->manufacturer()->sole();

    expect($manufacturer->name)->toBe('Zamst Co., Ltd.')
        ->and($manufacturer->address)->toBe("1-2-3 Osaka\nJaponia")
        ->and($manufacturer->phone)->toBeNull()
        ->and($manufacturer->hasEuRepresentative())->toBeTrue();

    $this->actingAs(manufacturerAdmin())
        ->patch(route('admin.manufacturers.update', $brand), manufacturerPayload([
            'name' => 'Nippon Sigmax Co., Ltd.',
            'eu_representative_name' => '',
            'eu_representative_address' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect(Manufacturer::query()->count())->toBe(1)
        ->and($brand->manufacturer()->sole())
        ->name->toBe('Nippon Sigmax Co., Ltd.')
        ->eu_representative_name->toBeNull();
});

it('shows saved details on a cached product page straight away', function (): void {
    config(['cache.default' => 'array']);
    $brand = brandWithProducts('Zamst', 1);
    $product = $brand->products()->where('status', ProductStatus::ACTIVE->value)->sole();

    $this->get(route('products.show', $product))->assertOk()->assertDontSee('Zamst Co., Ltd.');

    $this->actingAs(manufacturerAdmin())
        ->patch(route('admin.manufacturers.update', $brand), manufacturerPayload());

    auth()->logout();

    $this->get(route('products.show', $product))->assertOk()->assertSee('Zamst Co., Ltd.');
});

it('requires a postal address and an e-mail or website', function (): void {
    $brand = brandWithProducts('Zamst', 1);

    $this->actingAs(manufacturerAdmin())
        ->patch(route('admin.manufacturers.update', $brand), manufacturerPayload([
            'address' => '',
            'email' => '',
            'website' => '',
            'eu_representative_address' => '',
        ]))
        ->assertSessionHasErrors(['address', 'email', 'website', 'eu_representative_address']);

    expect(Manufacturer::query()->count())->toBe(0);
});

it('does not attach manufacturer details to attributes that are not brands', function (): void {
    $attribute = Attribute::query()->create(['slug' => 'kolor', 'name' => 'Kolor']);
    $colour = AttributeValue::query()->create(['attribute_id' => $attribute->id, 'value' => 'Czarny', 'slug' => 'czarny']);

    $this->actingAs(manufacturerAdmin())->get(route('admin.manufacturers.edit', $colour))->assertNotFound();
    $this->actingAs(manufacturerAdmin())->patch(route('admin.manufacturers.update', $colour), manufacturerPayload())->assertNotFound();

    expect(Manufacturer::query()->count())->toBe(0);
});
