<?php

use App\Console\Commands\CleanCatalogueBrandsCommand;
use App\Enums\AttributeDisplayType;
use App\Enums\ProductStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

function brandValue(string $name, ?string $slug = null): AttributeValue
{
    $attribute = Attribute::query()->firstOrCreate(['slug' => 'producent'], ['name' => 'Producent', 'display_type' => AttributeDisplayType::SELECT]);

    return AttributeValue::query()->create([
        'attribute_id' => $attribute->id,
        'value' => $name,
        'slug' => $slug ?? str($name)->slug()->limit(190, '')->toString(),
        'sort_order' => 0,
    ]);
}

function brandedProduct(string $name, ?AttributeValue $brand = null, array $attributes = []): Product
{
    $product = Product::query()->create([
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'status' => ProductStatus::ACTIVE,
        ...$attributes,
    ]);

    if ($brand !== null) {
        $product->attributeValues()->attach($brand->id);
    }

    return $product;
}

function brandNamesOf(Product $product): array
{
    return $product->attributeValues()->pluck('value')->all();
}

it('cleans legal forms and imprints from brand names', function (string $raw, string $clean): void {
    expect(CleanCatalogueBrandsCommand::cleanName($raw))->toBe($clean);
})->with([
    ['Armedical Sp. z o.o.', 'Armedical'],
    ['Antar Medical sp. z o.o.', 'Antar Medical'],
    ['Oppo Medical Inc.', 'Oppo Medical'],
    ['Sigvaris S.A.', 'Sigvaris'],
    ['Reha Fund Sp. z o.o. ul. Staniewicka 14 03-310 Warszawa Polska www.rehafund.pl e-mail: info@rehafund.pl', 'Reha Fund'],
    ['Medico', 'Medico'],
    ['Tynor', 'Tynor'],
    ['ORTOPEDIA ŁÓDŹ', 'ORTOPEDIA ŁÓDŹ'],
]);

it('previews renames, merges and missing Reh4Mat brands without writing', function (): void {
    $short = brandValue('Armedical');
    $long = brandValue('Armedical Sp. z o.o.');
    brandedProduct('A', $short);
    brandedProduct('B', $long);
    brandedProduct('C', $long);
    brandedProduct('Attack Pro', null, ['external_source' => 'reh4mat', 'external_id' => '1', 'seo_title' => 'ATTACK PRO | Reh4Mat']);

    $this->artisan('catalogue:clean-brands')
        ->expectsOutputToContain('RENAMES=1')
        ->expectsOutputToContain('MERGES=1 (PRODUCT_LINKS_MOVED=1)')
        ->expectsOutputToContain('REH4MAT_WITHOUT_BRAND=1 {"Reh4Mat":1}')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($long->fresh()->value)->toBe('Armedical Sp. z o.o.');
});

it('merges and renames brands, attaches Reh4Mat brands, and rolls back', function (): void {
    $short = brandValue('Armedical');
    $long = brandValue('Armedical Sp. z o.o.');
    $junk = brandValue('Reha Fund Sp. z o.o. ul. Staniewicka 14 03-310 Warszawa', 'reha-fund-sp-z-o-o-ul-staniewicka');
    $a = brandedProduct('A', $short);
    $b = brandedProduct('B', $long);
    $c = brandedProduct('C', $long);
    $d = brandedProduct('D', $junk);
    $attack = brandedProduct('Attack Pro', null, ['external_source' => 'reh4mat', 'external_id' => '1', 'seo_title' => 'ATTACK PRO | Reh4Mat']);
    $bodymap = brandedProduct('BodyMap A', null, ['external_source' => 'reh4mat', 'external_id' => '2', 'seo_title' => 'BodyMap® A – Poduszka | BodyMap®']);

    $this->artisan('catalogue:clean-brands', ['--write' => true])
        ->expectsOutputToContain('CATALOGUE_BRANDS_WRITE=PASS')
        ->assertSuccessful();

    expect(brandNamesOf($a))->toBe(['Armedical'])
        ->and(brandNamesOf($b))->toBe(['Armedical'])
        ->and(brandNamesOf($c))->toBe(['Armedical'])
        ->and(brandNamesOf($d))->toBe(['Reha Fund'])
        ->and($junk->fresh()->slug)->toBe('reha-fund')
        ->and(brandNamesOf($attack))->toBe(['Reh4Mat'])
        ->and(brandNamesOf($bodymap))->toBe(['BodyMap®']);

    $this->artisan('catalogue:clean-brands')
        ->expectsOutputToContain('RENAMES=0')
        ->expectsOutputToContain('MERGES=0')
        ->expectsOutputToContain('REH4MAT_WITHOUT_BRAND=0')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:clean-brands', ['--rollback' => $backup])
        ->expectsOutputToContain('CATALOGUE_BRANDS_ROLLBACK=PASS')
        ->assertSuccessful();

    expect(brandNamesOf($b))->toBe(['Armedical Sp. z o.o.'])
        ->and($long->fresh()->slug)->toBe('armedical-sp-z-oo')
        ->and(brandNamesOf($d))->toBe(['Reha Fund Sp. z o.o. ul. Staniewicka 14 03-310 Warszawa'])
        ->and(brandNamesOf($attack))->toBe([])
        ->and(brandNamesOf($a))->toBe(['Armedical']);
});
