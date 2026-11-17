<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Products\ClothingProductNames;
use App\Services\Products\ProductBrandAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  list<array{0: string, 1: string}>  $products  [name, brand]
 * @return array<string, string> old name => new name or SKIP reason
 */
function clothingNames(array $products): array
{
    $plan = ClothingProductNames::plan(array_map(
        fn (array $product, int $index): array => ['id' => $index + 1, 'name' => $product[0], 'brand' => $product[1]],
        $products,
        array_keys($products),
    ));

    $result = [];

    foreach ($plan['renames'] as $rename) {
        $result[$rename['old']] = $rename['new'];
    }

    foreach ($plan['skipped'] as $skip) {
        $result[$skip['name']] = 'SKIP '.$skip['reason'];
    }

    return $result;
}

it('names clothing as producer, model and garment', function (): void {
    expect(clothingNames([
        ['elegancka sukienka medyczna długi rękaw LENA', 'Eldan'],
        ['Sukienka M8735 Wojdak', 'Wojdak'],
        ['SpodnieS4034 Wojdak', 'Wojdak'],
        ['Sukienka medyczna biała SU 33, rękaw 3/4, Premium', 'Apolonia'],
        ['Bluza medyczna damska biała BL 52.2, krótki rękaw, Premium', 'Apolonia'],
        ['Białe spodnie rehabilitacyjne męskie SE 91.2 J, Premium Stretch', 'Apolonia'],
        ['Koszulka polo męska biała Malfini PRIME (GOTS) 234', 'Malfini'],
        ['Bluza medyczna polarowa męska zapinana na zamek biała Speedway', 'Printer Essentials'],
        ['męska koszulka medyczna z krótkim rękawem T-SHIRT', 'Eldan'],
        ['spodnie MOM FIT z DZIANINĄ', 'Eldan'],
        ['bluza EMA, krótki ręk. - WYPRZEDAŻ', 'Eldan'],
        ['bezrękawnik polarowy damski zapinany na zamek EMI', 'Eldan'],
    ]))->toBe([
        'elegancka sukienka medyczna długi rękaw LENA' => 'Eldan Lena Sukienka',
        'Sukienka M8735 Wojdak' => 'Wojdak M8735 Sukienka',
        'SpodnieS4034 Wojdak' => 'Wojdak S4034 Spodnie',
        'Sukienka medyczna biała SU 33, rękaw 3/4, Premium' => 'Apolonia SU 33 Sukienka',
        'Bluza medyczna damska biała BL 52.2, krótki rękaw, Premium' => 'Apolonia BL 52.2 Bluza',
        'Białe spodnie rehabilitacyjne męskie SE 91.2 J, Premium Stretch' => 'Apolonia SE 91.2 J Spodnie',
        'Koszulka polo męska biała Malfini PRIME (GOTS) 234' => 'Malfini Prime GOTS 234 Koszulka Polo',
        'Bluza medyczna polarowa męska zapinana na zamek biała Speedway' => 'Printer Essentials Speedway Bluza Polarowa',
        'męska koszulka medyczna z krótkim rękawem T-SHIRT' => 'Eldan T-Shirt Koszulka',
        'spodnie MOM FIT z DZIANINĄ' => 'Eldan Mom Fit z Dzianiną Spodnie',
        'bluza EMA, krótki ręk. - WYPRZEDAŻ' => 'Eldan Ema Bluza',
        'bezrękawnik polarowy damski zapinany na zamek EMI' => 'Eldan Emi Bezrękawnik Polarowy',
    ]);
});

it('adds the fewest details that tell products of one model apart', function (): void {
    expect(clothingNames([
        ['wygodna sukienka medyczna krótki rękaw LENA FLEX', 'Eldan'],
        ['wygodna sukienka medyczna długi rękaw LENA FLEX', 'Eldan'],
        ['Sukienka medyczna popielata ze stójką SU 16, krótki rękaw, Premium', 'Apolonia'],
        ['Sukienka medyczna biała ze stójką SU 16, krótki rękaw, Premium', 'Apolonia'],
        ['Żakiet medyczny damski biały z dekoltem V ZA 10.2, krótki rękaw, Premium Stretch Cotton', 'Apolonia'],
        ['Żakiet medyczny damski biały z dekoltem V ZA 10.2, krótki rękaw, Premium', 'Apolonia'],
        ['Żakiet medyczny damski z dekoltem V ZA 10.2, beż piaskowy, krótki rękaw, Premium Stretch Cotton', 'Apolonia'],
        ['Spodnie medyczne damskie joggery petite szare SE 92, Comfort Stretch', 'Apolonia'],
        ['Spodnie medyczne damskie szare SE 92, Comfort Stretch', 'Apolonia'],
        ['męskie spodnie medyczne na gumce KOMFORT FLEX', 'Eldan'],
        ['spodnie medyczne damskie na gumce KOMFORT FLEX', 'Eldan'],
        ['Bluza medyczna damska we wzorki kotki BL 55, krótki rękaw', 'Apolonia'],
        ['Bluza medyczna damska chirurgiczna w kolorze fuksja BL 55, krótki rękaw, Premium', 'Apolonia'],
    ]))->toBe([
        'wygodna sukienka medyczna krótki rękaw LENA FLEX' => 'Eldan Lena Flex Sukienka Krótki Rękaw',
        'wygodna sukienka medyczna długi rękaw LENA FLEX' => 'Eldan Lena Flex Sukienka Długi Rękaw',
        'Sukienka medyczna popielata ze stójką SU 16, krótki rękaw, Premium' => 'Apolonia SU 16 Sukienka Popielata',
        'Sukienka medyczna biała ze stójką SU 16, krótki rękaw, Premium' => 'Apolonia SU 16 Sukienka Biała',
        'Żakiet medyczny damski biały z dekoltem V ZA 10.2, krótki rękaw, Premium Stretch Cotton' => 'Apolonia ZA 10.2 Żakiet Biały Premium Stretch Cotton',
        'Żakiet medyczny damski biały z dekoltem V ZA 10.2, krótki rękaw, Premium' => 'Apolonia ZA 10.2 Żakiet Biały Premium',
        'Żakiet medyczny damski z dekoltem V ZA 10.2, beż piaskowy, krótki rękaw, Premium Stretch Cotton' => 'Apolonia ZA 10.2 Żakiet Beż Piaskowy',
        'Spodnie medyczne damskie joggery petite szare SE 92, Comfort Stretch' => 'Apolonia SE 92 Spodnie Joggery Petite',
        'Spodnie medyczne damskie szare SE 92, Comfort Stretch' => 'Apolonia SE 92 Spodnie',
        'męskie spodnie medyczne na gumce KOMFORT FLEX' => 'Eldan Komfort Flex Spodnie Męskie',
        'spodnie medyczne damskie na gumce KOMFORT FLEX' => 'Eldan Komfort Flex Spodnie Damskie',
        'Bluza medyczna damska we wzorki kotki BL 55, krótki rękaw' => 'Apolonia BL 55 Bluza We Wzorki Kotki',
        'Bluza medyczna damska chirurgiczna w kolorze fuksja BL 55, krótki rękaw, Premium' => 'Apolonia BL 55 Bluza Fuksja',
    ]);
});

it('reads names with decomposed accents and prefers the colour standing between commas', function (): void {
    $skirt = "Spo\u{0301}dnica medyczna damska granatowa SP 69, Premium";
    $jacket = "Z\u{0307}akiet medyczny damski ze sto\u{0301}jka\u{0328} ZA 19, bez\u{0307} nude, kro\u{0301}tki re\u{0328}kaw, Elegant Stretch";

    expect(clothingNames([
        [$skirt, 'Apolonia'],
        [$jacket, 'Apolonia'],
        ['Żakiet medyczny damski biały ze stójką ZA 19, krótki rękaw, Premium', 'Apolonia'],
        ['Żakiet medyczny damski biały ZA 16, mokka, krótki rękaw, Elegant Stretch', 'Apolonia'],
        ['Żakiet medyczny damski biały ZA 16, krótki rękaw, Premium', 'Apolonia'],
    ]))->toBe([
        $skirt => 'Apolonia SP 69 Spódnica',
        $jacket => 'Apolonia ZA 19 Żakiet Beż Nude',
        'Żakiet medyczny damski biały ze stójką ZA 19, krótki rękaw, Premium' => 'Apolonia ZA 19 Żakiet Biały',
        'Żakiet medyczny damski biały ZA 16, mokka, krótki rękaw, Elegant Stretch' => 'Apolonia ZA 16 Żakiet Mokka',
        'Żakiet medyczny damski biały ZA 16, krótki rękaw, Premium' => 'Apolonia ZA 16 Żakiet Biały',
    ]);
});

it('leaves products it cannot name or tell apart', function (): void {
    expect(clothingNames([
        ['Fartuch laboratoryjny męski biały na guziki (dla studentów)', 'Worker'],
        ['wygodne męskie spodnie medyczne Z DZIANINĄ', 'Eldan'],
        ['Gumka do włosów scrunchie biała', 'Apolonia'],
        ['Eldan Lena Sukienka', 'Eldan'],
        ['Fartuch medyczny damski biały FA 44, krótki rękaw, Premium', 'Apolonia'],
        ['Fartuch medyczny damski biały FA 44 krótki rękaw Premium', 'Apolonia'],
    ]))->toBe([
        'Eldan Lena Sukienka' => 'SKIP ALREADY_RENAMED',
        'Gumka do włosów scrunchie biała' => 'SKIP NO_GARMENT',
        'Fartuch laboratoryjny męski biały na guziki (dla studentów)' => 'SKIP NO_MODEL',
        'wygodne męskie spodnie medyczne Z DZIANINĄ' => 'SKIP NO_MODEL',
        'Fartuch medyczny damski biały FA 44, krótki rękaw, Premium' => 'SKIP SAME_NAME_AS_ANOTHER_PRODUCT',
        'Fartuch medyczny damski biały FA 44 krótki rękaw Premium' => 'SKIP SAME_NAME_AS_ANOTHER_PRODUCT',
    ]);
});

it('does not repeat the name of a product already renamed or outside the clothing tree', function (): void {
    $plan = ClothingProductNames::plan([
        ['id' => 1, 'name' => 'Eldan Lena Sukienka', 'brand' => 'Eldan'],
        ['id' => 2, 'name' => 'elegancka sukienka medyczna długi rękaw LENA', 'brand' => 'Eldan'],
        ['id' => 3, 'name' => 'Sukienka M8735 Wojdak', 'brand' => 'Wojdak'],
    ], ['Wojdak M8735 Sukienka']);

    expect($plan['renames'])->toBe([])
        ->and(array_column($plan['skipped'], 'reason', 'id'))->toBe([
            1 => ClothingProductNames::SKIP_ALREADY_RENAMED,
            2 => ClothingProductNames::SKIP_SAME_NAME,
            3 => ClothingProductNames::SKIP_SAME_NAME,
        ]);
});

it('renames clothing in the category tree, keeps the old name as SEO title and rolls back', function (): void {
    $clothing = clothingCategory('Odzież medyczna i zawodowa', 'odziez-medyczna');
    $dresses = clothingCategory('Sukienki medyczne', 'sukienki-medyczne', $clothing);
    $disposable = clothingCategory('Fartuchy i odzież jednorazowa', 'fartuchy-jednorazowe', $clothing);
    $other = clothingCategory('Mydła', 'mydla');

    $short = clothingProduct('wygodna sukienka medyczna krótki rękaw LENA FLEX', 'Eldan', $dresses);
    $long = clothingProduct('wygodna sukienka medyczna długi rękaw LENA FLEX', 'Eldan', $dresses, 'Sukienka Lena Flex z długim rękawem');
    $gown = clothingProduct('Fartuch chirurgiczny Matodress COMFORT HP Jałowy', 'Matopat', $disposable);
    $soap = clothingProduct('Mydło w płynie Morska Bryza Pollena Ewa 5 l', 'Pollena', $other);
    $noBrand = clothingProduct('sukienka INEZ, krótki rękaw', null, $dresses);

    $this->artisan('catalogue:clothing-names')
        ->expectsOutputToContain('PRODUCTS=3 RENAMES=2 SKIPPED=1')
        ->expectsOutputToContain('RENAME product='.$short->id.' "wygodna sukienka medyczna krótki rękaw LENA FLEX" -> "Eldan Lena Flex Sukienka Krótki Rękaw"')
        ->expectsOutputToContain('SKIP NO_PRODUCER product='.$noBrand->id)
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($short->fresh()->name)->toBe('wygodna sukienka medyczna krótki rękaw LENA FLEX');

    $this->artisan('catalogue:clothing-names', ['--write' => true])
        ->expectsOutputToContain('RENAMED=2')
        ->assertSuccessful();

    expect($short->fresh()->name)->toBe('Eldan Lena Flex Sukienka Krótki Rękaw')
        ->and($short->fresh()->seo_title)->toBe('wygodna sukienka medyczna krótki rękaw LENA FLEX')
        ->and($long->fresh()->name)->toBe('Eldan Lena Flex Sukienka Długi Rękaw')
        ->and($long->fresh()->seo_title)->toBe('Sukienka Lena Flex z długim rękawem')
        ->and($short->fresh()->slug)->toBe($short->slug)
        ->and($gown->fresh()->name)->toBe('Fartuch chirurgiczny Matodress COMFORT HP Jałowy')
        ->and($soap->fresh()->name)->toBe('Mydło w płynie Morska Bryza Pollena Ewa 5 l');

    $this->artisan('catalogue:clothing-names')
        ->expectsOutputToContain('RENAMES=0')
        ->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();
    $this->artisan('catalogue:clothing-names', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED=2 OF 2')
        ->assertSuccessful();

    expect($short->fresh()->name)->toBe('wygodna sukienka medyczna krótki rękaw LENA FLEX')
        ->and($short->fresh()->seo_title)->toBeNull()
        ->and($long->fresh()->seo_title)->toBe('Sukienka Lena Flex z długim rękawem');
});

function clothingCategory(string $name, string $slug, ?Category $parent = null): Category
{
    return Category::query()->create([
        'parent_id' => $parent?->id,
        'name' => $name,
        'slug' => $slug,
        'status' => CategoryStatus::ACTIVE,
    ]);
}

function clothingProduct(string $name, ?string $brand, Category $category, ?string $seoTitle = null): Product
{
    $product = Product::query()->create([
        'external_source' => 'test',
        'external_id' => (string) str()->uuid(),
        'name' => $name,
        'slug' => str($name)->slug()->append('-'.str()->random(6))->lower()->toString(),
        'seo_title' => $seoTitle,
        'status' => ProductStatus::ACTIVE,
    ]);

    $product->categories()->attach($category->id, ['is_primary' => true]);

    if ($brand !== null) {
        app(ProductBrandAttribute::class)->attach($product, $brand);
    }

    return $product;
}
