<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Support\Catalogue\OrthosisBodyPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('places staging product names in the right body part', function (string $name, ?string $group, array $categories = []): void {
    expect(OrthosisBodyPart::classify($name, $categories))->toBe($group);
})->with([
    ['ATTACK PRO', 'kolano', ['Ortezy kolana']],
    ['Elastyczny stabilizator stawu kolanowego z zawiasami Genumax Plus', 'kolano'],
    ['Air Pump Walker - Achill', 'kostka-i-stopa'],
    ['Orteza palca ręki APARAT STACKA', 'nadgarstek-i-dlon'],
    ['Jednoszynowa orteza łokcia OKG-16', 'lokiec'],
    ['Temblak DR-E027', 'bark-i-ramie'],
    ['Korektor postawy DR-B011', 'kregoslup-i-tulow'],
    ['Pas lędźwiowo-krzyżowy z pelotą silikonową "Lumbomax Skin"', 'kregoslup-i-tulow'],
    ['Orteza odcinka szyjnego AM-KM', 'szyja'],
    ['Orteza biodra AM-SB-01', 'biodro-udo-i-podudzie'],
    ['BodyMap® A – Poduszka do siedzenia', 'pozycjonowanie-i-stabilizacja'],
    ['FP-05 Dynamiczny 2-punktowy pas tułowia', 'pozycjonowanie-i-stabilizacja'],
    ['K4 – Kamizelka 4-punktowa', 'pozycjonowanie-i-stabilizacja'],
    ['Kamizelka na ramię i bark. IMMOBILO SHD', 'bark-i-ramie'],
    ['FP-EM-6 Wkręt do drewna', 'akcesoria-do-ortez'],
    ['Pokrowiec do AM-ADS-R', 'akcesoria-do-ortez'],
    ['P3D – Pokrowiec 3D MESH™', 'akcesoria-do-ortez'],
    ['PMN – Podkładka masujaco-napowietrzająca', 'akcesoria-do-ortez'],
    ['OS1st AS6 rękaw na ramię', 'bark-i-ramie'],
    ['OS1st BR4 Halux', 'kostka-i-stopa'],
    ['OS1st HV3 Bunion Bracing Sleeve', 'kostka-i-stopa'],
    ['OS1st ES6 ramię', 'lokiec'],
    ['OrthoSleeve BR4', 'kostka-i-stopa'],
    ['OrthoSleeve CS6', 'biodro-udo-i-podudzie'],
    ['OrthoSleeve ES3', 'lokiec'],
    ['OrthoSleeve FS6+', 'kostka-i-stopa'],
    ['OS1st WS6 nadgarstek', 'nadgarstek-i-dlon'],
    ['Biustonosz kompresyjny AT04007', null],
]);

// The archived supplier categories that legacy ortezka.pl redirects point at.
it('sends archived supplier orthosis categories to their body part', function (string $name, ?string $group): void {
    expect(OrthosisBodyPart::forCategoryName($name))->toBe($group);
})->with([
    ['Ortezy kolana', 'kolano'],
    ['KRĘGOSŁUP', 'kregoslup-i-tulow'],
    ['Ortezy stawu skokowego i stopy', 'kostka-i-stopa'],
    ['TEMBLAKI NA RĘKĘ', 'bark-i-ramie'],
    ['Ortezy nadgarstka i przedramienia', 'nadgarstek-i-dlon'],
    ['Ortezy łokcia', 'lokiec'],
    ['Ortezy dłoni', 'nadgarstek-i-dlon'],
    ['Ortezy kręgosłupa szyjnego (CO)', 'szyja'],
    ['Ortezy podudzia', 'biodro-udo-i-podudzie'],
    ['Ortezy barku i obojczyka', 'bark-i-ramie'],
    ['Walkery', 'kostka-i-stopa'],
    ['OPASKI I PASY MAGNETYCZNE', null],
    ['PASY PRZEPUKLINOWE', 'kregoslup-i-tulow'],
    ['KOŃCZYNA DOLNA', null],
    ['Tułów', 'kregoslup-i-tulow'],
    ['Ortezy biodra', 'biodro-udo-i-podudzie'],
    ['Ortezy uda', 'biodro-udo-i-podudzie'],
    ['KOREKTORY WAD POSTAWY', 'kregoslup-i-tulow'],
    ['KOŃCZYNA GÓRNA', null],
    ['Pasy ciążowe', 'kregoslup-i-tulow'],
    ['Stabilizatory palców', 'nadgarstek-i-dlon'],
    ['Ortezy kręgosłupa lędźwiowo-krzyżowego (LSO)', 'kregoslup-i-tulow'],
    ['Ortezy i stabilizatory pleców', 'kregoslup-i-tulow'],
    ['NightSplint', 'kostka-i-stopa'],
    ['Stabilizatory stawu skokowego', 'kostka-i-stopa'],
    ['Magnetyczne pasy na kręgosłup', 'kregoslup-i-tulow'],
    ['Magnetyczne opaski na rękę', 'nadgarstek-i-dlon'],
    ['Koszulki korygujące postawę', 'kregoslup-i-tulow'],
]);

it('recognises party and carnival wigs', function (): void {
    expect(OrthosisBodyPart::isPartyWig('Peruka imprezowa karnawałowa ciemny blond MODEL 8133 14'))->toBeTrue()
        ->and(OrthosisBodyPart::isPartyWig('Peruka syntetyczna, kolor brąz - OPAL'))->toBeFalse();
});

function reorganiseCategory(string $slug, string $name, ?Category $parent = null): Category
{
    return Category::query()->create(['slug' => $slug, 'name' => $name, 'parent_id' => $parent?->id, 'status' => CategoryStatus::ACTIVE]);
}

function reorganiseProduct(string $name, array $categories, ?Category $primary = null): Product
{
    $product = Product::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'status' => ProductStatus::ACTIVE, 'published_at' => now()->subDay()]);

    foreach ($categories as $category) {
        $product->categories()->attach($category->id, ['is_primary' => $primary?->is($category) ?? false]);
    }

    return $product;
}

function primaryCategorySlug(Product $product): ?string
{
    return $product->categories()->wherePivot('is_primary', true)->value('slug');
}

/** @return array<string, mixed> */
function reorganiseFixture(): array
{
    $orthoses = reorganiseCategory('ortezy-i-stabilizatory', 'Ortezy i stabilizatory');
    $lowerLimb = reorganiseCategory('konczyna-dolna', 'KOŃCZYNA DOLNA', $orthoses);
    $kneeSupplier = reorganiseCategory('ortezy-kolana', 'Ortezy kolana', $lowerLimb);
    $kneeExisting = reorganiseCategory('kolano', 'Kolano', $orthoses);
    $elements = reorganiseCategory('elementy-montazowe', 'Elementy montażowe', $orthoses);
    $sport = reorganiseCategory('sport', 'Sport');
    $wigs = reorganiseCategory('peruki', 'Peruki i akcesoria');
    $occasional = reorganiseCategory('peruki-okazjonalne', 'Peruki okazjonalne', $wigs);

    return [
        'orthoses' => $orthoses,
        'kneeSupplier' => $kneeSupplier,
        'kneeExisting' => $kneeExisting,
        'lowerLimb' => $lowerLimb,
        'attack' => reorganiseProduct('ATTACK PRO', [$kneeSupplier], $kneeSupplier),
        'screw' => reorganiseProduct('FP-EM-6 Wkręt do drewna', [$elements], $elements),
        'bra' => reorganiseProduct('Biustonosz kompresyjny AT04007', [$lowerLimb], $lowerLimb),
        'sportKnee' => reorganiseProduct('Opaska na kolano dla biegaczy', [$sport, $kneeSupplier], $sport),
        'partyWig' => reorganiseProduct('Peruka imprezowa karnawałowa ciemny blond MODEL 8133 14', [$occasional], $occasional),
        'medicalWig' => reorganiseProduct('Peruka syntetyczna, kolor brąz - OPAL', [$occasional], $occasional),
    ];
}

it('previews the new body-part categories without writing', function (): void {
    reorganiseFixture();

    $this->artisan('catalogue:reorganise')
        ->expectsOutputToContain('ORTHOSIS_PRODUCTS=4 UNPLACED=1')
        ->expectsOutputToContain('SUPPLIER_SUBCATEGORIES_TO_ARCHIVE=3')
        ->expectsOutputToContain('PARTY_WIGS_TO_MOVE=1')
        ->expectsOutputToContain('UNPLACED product=')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(Category::query()->where('slug', 'kostka-i-stopa')->exists())->toBeFalse();
});

it('regroups orthoses by body part, moves party wigs and rolls back', function (): void {
    $f = reorganiseFixture();
    $linksBefore = DB::table('category_product')->orderBy('product_id')->orderBy('category_id')->get(['product_id', 'category_id', 'is_primary'])->map(fn ($row) => (array) $row)->all();

    $this->artisan('catalogue:reorganise', ['--write' => true])
        ->expectsOutputToContain('CATALOGUE_REORGANISE_WRITE=PASS')
        ->assertSuccessful();

    expect(primaryCategorySlug($f['attack']))->toBe('kolano')
        ->and($f['kneeExisting']->fresh()->id)->toBe(Category::query()->where('slug', 'kolano')->value('id'))
        ->and(primaryCategorySlug($f['screw']))->toBe('akcesoria-do-ortez')
        ->and(primaryCategorySlug($f['bra']))->toBe('ortezy-i-stabilizatory')
        ->and(primaryCategorySlug($f['sportKnee']))->toBe('sport')
        ->and($f['sportKnee']->categories()->pluck('slug')->all())->toContain('kolano')
        ->and(primaryCategorySlug($f['partyWig']))->toBe('peruki-imprezowe-i-karnawalowe')
        ->and($f['partyWig']->categories()->pluck('slug')->all())->toBe(['peruki-imprezowe-i-karnawalowe'])
        ->and(primaryCategorySlug($f['medicalWig']))->toBe('peruki-okazjonalne')
        ->and($f['kneeSupplier']->fresh()->status)->toBe(CategoryStatus::ARCHIVED)
        ->and($f['kneeExisting']->fresh()->status)->toBe(CategoryStatus::ACTIVE);

    $this->get('/categories/kolano')->assertOk()->assertSee('ATTACK PRO')->assertSee('Opaska na kolano dla biegaczy');
    $this->get('/categories/ortezy-i-stabilizatory')->assertOk()->assertSee('Kostka i stopa')->assertSee('Biustonosz kompresyjny AT04007');
    // A supplier category named after a body part goes to that body part; a mixed one goes to the nearest active ancestor.
    $this->get('/categories/ortezy-kolana')->assertRedirect('/categories/kolano');
    $this->get('/categories/konczyna-dolna')->assertRedirect('/categories/ortezy-i-stabilizatory');
    $this->get('/categories/peruki-imprezowe-i-karnawalowe')->assertOk()->assertSee('Peruka imprezowa karnawałowa');

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:reorganise', ['--rollback' => $backup])
        ->expectsOutputToContain('CATALOGUE_REORGANISE_ROLLBACK=PASS')
        ->assertSuccessful();

    expect(DB::table('category_product')->orderBy('product_id')->orderBy('category_id')->get(['product_id', 'category_id', 'is_primary'])->map(fn ($row) => (array) $row)->all())
        ->toEqual($linksBefore)
        ->and($f['kneeSupplier']->fresh()->status)->toBe(CategoryStatus::ACTIVE)
        ->and($f['kneeExisting']->fresh()->name)->toBe('Kolano')
        ->and(Category::query()->where('slug', 'kostka-i-stopa')->exists())->toBeFalse()
        ->and(Category::query()->where('slug', 'peruki-imprezowe-i-karnawalowe')->exists())->toBeFalse();
});
