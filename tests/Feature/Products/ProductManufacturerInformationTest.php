<?php

use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Products\ProductManufacturerInformation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gpsrProduct(string $slug = 'opaska-na-lokiec'): Product
{
    $product = Product::query()->create([
        'name' => 'Opaska na łokieć',
        'slug' => $slug,
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDay(),
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'sku' => 'GPSR-'.$slug,
        'status' => ProductVariantStatus::ACTIVE,
        'price_net_amount' => 4878,
        'price_gross_amount' => 6000,
        'currency' => Currency::PLN,
        'vat_rate' => VatRate::VAT_23,
        'stock_status' => StockStatus::IN_STOCK,
        'is_default' => true,
    ]);

    return $product;
}

function gpsrBrandValue(string $attributeSlug, string $brand): AttributeValue
{
    $attribute = Attribute::query()->firstOrCreate(
        ['slug' => $attributeSlug],
        ['name' => ucfirst($attributeSlug)],
    );

    return AttributeValue::query()->create([
        'attribute_id' => $attribute->id,
        'value' => $brand,
        'slug' => str($brand)->slug()->toString(),
    ]);
}

it('shows the manufacturer and EU responsible person of the product brand on the product page', function (): void {
    $product = gpsrProduct();
    $brand = gpsrBrandValue('producent', 'OrthoSleeve');
    $product->attributeValues()->attach($brand);

    Manufacturer::query()->create([
        'attribute_value_id' => $brand->id,
        'name' => 'OS1st Inc.',
        'address' => "100 Main Street\nBoston, MA 02110\nUSA",
        'email' => 'info@example.com',
        'website' => 'https://www.example.com/',
        'eu_representative_name' => 'Footwave Sp. z o.o.',
        'eu_representative_address' => "ul. Testowa 5\n00-001 Warszawa",
        'eu_representative_email' => 'eu@example.pl',
        'safety_information' => 'Nie stosować na uszkodzoną skórę.',
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('Informacje o bezpieczeństwie produktu')
        ->assertSeeInOrder(['Producent', 'OS1st Inc.', '100 Main Street', 'info@example.com', 'example.com'])
        ->assertSeeInOrder(['Osoba odpowiedzialna w UE', 'Footwave Sp. z o.o.', 'ul. Testowa 5', 'eu@example.pl'])
        ->assertSee('Nie stosować na uszkodzoną skórę.')
        ->assertSee('href="mailto:info@example.com"', false);
});

it('leaves out the safety block when the brand has no manufacturer details', function (): void {
    $product = gpsrProduct();
    $product->attributeValues()->attach(gpsrBrandValue('producent', 'Bez danych'));

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertDontSee('Informacje o bezpieczeństwie produktu');
});

it('prefers the Producent value over the Marka value', function (): void {
    $product = gpsrProduct();
    $producent = gpsrBrandValue('producent', 'TZMO');
    $marka = gpsrBrandValue('marka', 'Seni');
    $product->attributeValues()->attach([$marka->id, $producent->id]);

    Manufacturer::query()->create(['attribute_value_id' => $marka->id, 'name' => 'Marka Seni', 'address' => 'Adres A', 'email' => 'a@example.com']);
    Manufacturer::query()->create(['attribute_value_id' => $producent->id, 'name' => 'TZMO SA', 'address' => 'Adres B', 'email' => 'b@example.com']);

    expect(ProductManufacturerInformation::forProduct($product->fresh()))
        ->toMatchArray(['name' => 'TZMO SA', 'eu_representative' => null]);
});

it('falls back to the Marka value when the Producent value has no details', function (): void {
    $product = gpsrProduct();
    $producent = gpsrBrandValue('producent', 'TZMO');
    $marka = gpsrBrandValue('marka', 'Seni');
    $product->attributeValues()->attach([$marka->id, $producent->id]);

    Manufacturer::query()->create(['attribute_value_id' => $marka->id, 'name' => 'TZMO SA', 'address' => 'Adres', 'website' => 'https://seni.example']);

    expect(ProductManufacturerInformation::forProduct($product->fresh())['name'])->toBe('TZMO SA');
});

it('ignores manufacturer rows on attributes that are not brands', function (): void {
    $product = gpsrProduct();
    $colour = gpsrBrandValue('kolor', 'Czarny');
    $product->attributeValues()->attach($colour);

    Manufacturer::query()->create(['attribute_value_id' => $colour->id, 'name' => 'Nie producent', 'address' => 'Adres', 'email' => 'x@example.com']);

    expect(ProductManufacturerInformation::forProduct($product->fresh()))->toBeNull();
});
