<?php

use App\Console\Commands\AssignMissingBrandsCommand;
use App\Enums\ProductStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Services\Storefront\StorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

function brandOf(string $name, string $attributeSlug = 'producent'): AttributeValue
{
    $attribute = Attribute::query()->firstOrCreate(['slug' => $attributeSlug], ['name' => ucfirst($attributeSlug)]);

    return AttributeValue::query()->firstOrCreate(
        ['attribute_id' => $attribute->id, 'slug' => str($name)->slug()->toString()],
        ['value' => $name],
    );
}

function sourcedProduct(string $name, ?string $source, ?AttributeValue $brand = null): Product
{
    $product = Product::query()->create([
        'name' => $name,
        'slug' => str($name)->slug()->append('-'.Str::random(4))->lower()->toString(),
        'status' => ProductStatus::ACTIVE,
        'external_source' => $source,
        'external_id' => $source !== null ? Str::random(8) : null,
    ]);

    if ($brand !== null) {
        $product->attributeValues()->attach($brand);
    }

    return $product;
}

/**
 * @return list<string>
 */
function brandsOf(Product $product): array
{
    return $product->attributeValues()
        ->whereHas('attribute', fn ($query) => $query->whereIn('slug', ['producent', 'marka']))
        ->pluck('value')
        ->all();
}

it('finds the one brand a product name mentions as a whole word', function (string $name, array $expected): void {
    $brands = [1 => 'Ziaja', 2 => 'Medi', 3 => 'Medi Trade', 4 => 'Bella', 5 => 'Happy', 6 => 'DARCO'];

    expect(array_values(AssignMissingBrandsCommand::brandsInName($name, $brands)))->toBe($expected);
})->with([
    ['Płyn do higieny intymnej Ziaja Intima 500 ml', ['Ziaja']],
    ['But odciążający DARCO - OrthoWedge', ['DARCO']],
    ['Buty terapeutyczne darco Ubiq', ['DARCO']],
    ['Opaska Medi Trade elastyczna', ['Medi Trade']],
    ['Opaska medical uciskowa', []],
    ['Bella Baby Happy Oliwka', ['Bella', 'Happy']],
    ['Ziajka krem', []],
]);

it('previews source and name assignments without writing', function (): void {
    brandOf('Ziaja');
    sourcedProduct('Inny produkt Ziaja', 'seni24', brandOf('Ziaja'));
    $byName = sourcedProduct('Płyn Ziaja Intima 500 ml', 'seni24');
    $bySource = sourcedProduct('Spodnie T4005', 'wojdak');
    $unknown = sourcedProduct('Peruka imprezowa MODEL 377', 'peruka');

    $this->artisan('catalogue:assign-missing-brands')
        ->expectsOutput('ASSIGNABLE=2 (BY_SOURCE=1, BY_NAME=1)')
        ->expectsOutput('STILL_WITHOUT_BRAND=1 {"peruka":1}')
        ->expectsOutput('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(brandsOf($byName))->toBe([])
        ->and(brandsOf($bySource))->toBe([])
        ->and(brandsOf($unknown))->toBe([]);
});

it('attaches brands, refreshes cached pages and rolls back exactly those links', function (): void {
    config(['storefront.cache.enabled' => true, 'storefront.cache.store' => 'array']);
    $cache = app(StorefrontCache::class);
    $version = fn (): string => $cache->versionedKey(StorefrontCache::NAMESPACE_CATALOGUE, 'x');

    $darco = brandOf('DARCO', 'marka');
    sourcedProduct('Inny but DARCO', 'iconic', $darco);
    $byName = sourcedProduct('But odciążający DARCO OrthoWedge', 'iconic');
    $bySource = sourcedProduct('Kula łokciowa Evolution', 'herdegen');
    $ambiguous = sourcedProduct('Zestaw DARCO i Ziaja', 'iconic');
    sourcedProduct('Krem', 'seni24', brandOf('Ziaja'));
    $branded = sourcedProduct('Spodnie T4040', 'wojdak', brandOf('Inna marka'));
    $before = $version();

    $this->artisan('catalogue:assign-missing-brands', ['--write' => true])
        ->expectsOutput('ATTACHED=2')
        ->assertSuccessful();

    expect(brandsOf($byName))->toBe(['DARCO'])
        ->and(brandsOf($bySource))->toBe(['Herdegen'])
        ->and(brandsOf($ambiguous))->toBe([])
        ->and(brandsOf($branded))->toBe(['Inna marka'])
        ->and($version())->not->toBe($before);

    $this->artisan('catalogue:assign-missing-brands', ['--write' => true])
        ->expectsOutput('NOTHING_TO_WRITE')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:assign-missing-brands', ['--rollback' => $backup])
        ->expectsOutput('REMOVED=2 OF 2')
        ->assertSuccessful();

    expect(brandsOf($byName))->toBe([])
        ->and(brandsOf($bySource))->toBe([])
        ->and(brandsOf(Product::query()->where('name', 'Inny but DARCO')->sole()))->toBe(['DARCO']);
});

it('never matches brands that are also common product names', function (): void {
    sourcedProduct('Chusteczki', 'seni24', brandOf('Bella'));
    sourcedProduct('Oliwka', 'seni24', brandOf('Happy'));
    $wig = sourcedProduct('Syntetyczna dopinka blond BELLA VITA 23A/26 siatka', 'peruka');
    $top = sourcedProduct('Happy Feet skarpetki', 'iconic');

    $this->artisan('catalogue:assign-missing-brands', ['--write' => true])
        ->expectsOutput('NOTHING_TO_WRITE')
        ->assertSuccessful();

    expect(brandsOf($wig))->toBe([])
        ->and(brandsOf($top))->toBe([]);
});

it('gives all Eldan products, clothing and shoes, the Eldan brand', function (): void {
    $top = sourcedProduct('bluza medyczna rozpinana krótki rękaw IGA FLEX', 'eldan');
    $shoes = sourcedProduct('wygodne skórzane buty medyczne KD MED 57', 'eldan');

    $this->artisan('catalogue:assign-missing-brands', ['--write' => true])
        ->expectsOutput('ATTACHED=2')
        ->assertSuccessful();

    expect(brandsOf($top))->toBe(['Eldan'])
        ->and(brandsOf($shoes))->toBe(['Eldan']);
});

it('recognises Iconic product lines', function (string $name, array $expected): void {
    expect(AssignMissingBrandsCommand::productLineBrands('iconic', $name))->toBe($expected);
})->with([
    ['But Odciążający Przodostopie i Palce DARCO - OrthoWedge', ['DARCO']],
    ['Osłona na palce do buta TwinShoe', ['DARCO']],
    ['Movilo® Fly - granatowe', ['DARCO']],
    ['Opaska gipsowa Prime Cast Soft - sprzedawana na sztuki', ['PRIME']],
    ['Wodoodporny rękaw pod gips - PrimeNetDry', ['PRIME']],
    ['Optima Diab - Orteza odciążająca na stopę cukrzycową', ['YDA']],
    ['YDA - Modern Line - dla kobiet czarny', ['YDA']],
    ['Szyna na paluch koślawy i palce młotkowate - TAS', ['TAS']],
    ['Zestaw Po Operacji Haluksa - Relief Dual + Puzzle + Szyna TAS', ['DARCO', 'TAS']],
    ['Night Splint - Bodytronix', ['Bodytronix']],
    ['Cążki do paznokci - proste 10 cm', []],
]);

it('gives Iconic products the brand of their product line, creating it when missing', function (): void {
    $walker = sourcedProduct('Optima CLHeel - Walker odciążający piętę', 'iconic');
    $set = sourcedProduct('Zestaw Po Operacji Haluksa - Relief Dual + Puzzle + Szyna TAS', 'iconic');
    $clippers = sourcedProduct('Cążki do paznokci - proste 10 cm', 'iconic');
    $elsewhere = sourcedProduct('Buty YDA damskie', 'peruka');

    $this->artisan('catalogue:assign-missing-brands', ['--write' => true])
        ->expectsOutput('BY_PRODUCT_LINE=1')
        ->expectsOutput('ATTACHED=1')
        ->assertSuccessful();

    expect(brandsOf($walker))->toBe(['YDA'])
        ->and(brandsOf($set))->toBe([])
        ->and(brandsOf($clippers))->toBe([])
        ->and(brandsOf($elsewhere))->toBe([]);
});
