<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

function regroupCategory(string $slug, string $name, ?Category $parent = null): Category
{
    return Category::query()->create(['slug' => $slug, 'name' => $name, 'parent_id' => $parent?->id, 'status' => CategoryStatus::ACTIVE]);
}

/** @param array<int, bool> $links category id => is primary */
function regroupProduct(string $name, array $links): Product
{
    $product = Product::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'status' => ProductStatus::ACTIVE, 'published_at' => now()->subDay()]);

    foreach ($links as $categoryId => $isPrimary) {
        $product->categories()->attach($categoryId, ['is_primary' => $isPrimary]);
    }

    return $product;
}

/** @return list<array{string, bool}> slug, is primary */
function regroupLinks(Product $product): array
{
    return $product->categories()->orderBy('slug')->get()->map(fn (Category $category): array => [$category->slug, (bool) $category->pivot->is_primary])->all();
}

/** @return array<string, mixed> */
function regroupFixture(): array
{
    $orthoses = regroupCategory('ortezy-i-stabilizatory', 'Ortezy i stabilizatory');
    $shoulder = regroupCategory('bark-i-ramie', 'Bark i ramię', $orthoses);
    regroupCategory('kostka-i-stopa', 'Kostka i stopa', $orthoses);
    regroupCategory('lokiec', 'Łokieć', $orthoses);
    $sport = regroupCategory('sport', 'Sport');

    return [
        'bunion' => regroupProduct('OrthoSleeve BR4', [$orthoses->id => false, $shoulder->id => true]),
        'elbow' => regroupProduct('OrthoSleeve ES3', [$sport->id => true, $orthoses->id => false, $shoulder->id => false]),
        // The name places it nowhere, so the group it got from supplier categories stays.
        'unnamed' => regroupProduct('ATTACK PRO', [$orthoses->id => false, $shoulder->id => true]),
        'sling' => regroupProduct('Temblak DR-E027', [$orthoses->id => false, $shoulder->id => true]),
    ];
}

it('previews the products its name places in another group', function (): void {
    $f = regroupFixture();

    $this->artisan('catalogue:regroup-orthoses')
        ->expectsOutputToContain('TO_MOVE=2')
        ->expectsOutputToContain('OrthoSleeve BR4: bark-i-ramie -> kostka-i-stopa')
        ->expectsOutputToContain('OrthoSleeve ES3: bark-i-ramie -> lokiec')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect(regroupLinks($f['bunion']))->toBe([['bark-i-ramie', true], ['ortezy-i-stabilizatory', false]]);
});

it('moves them, keeps other primaries and rolls back', function (): void {
    $f = regroupFixture();
    $linksBefore = DB::table('category_product')->orderBy('product_id')->orderBy('category_id')->get(['product_id', 'category_id', 'is_primary'])->map(fn ($row) => (array) $row)->all();

    $this->artisan('catalogue:regroup-orthoses', ['--write' => true])
        ->expectsOutputToContain('MOVED=2')
        ->expectsOutputToContain('CATALOGUE_REGROUP_WRITE=PASS')
        ->assertSuccessful();

    expect(regroupLinks($f['bunion']))->toBe([['kostka-i-stopa', true], ['ortezy-i-stabilizatory', false]])
        ->and(regroupLinks($f['elbow']))->toBe([['lokiec', false], ['ortezy-i-stabilizatory', false], ['sport', true]])
        ->and(regroupLinks($f['unnamed']))->toBe([['bark-i-ramie', true], ['ortezy-i-stabilizatory', false]])
        ->and(regroupLinks($f['sling']))->toBe([['bark-i-ramie', true], ['ortezy-i-stabilizatory', false]]);

    $this->get('/categories/kostka-i-stopa')->assertOk()->assertSee('OrthoSleeve BR4');
    $this->get('/categories/bark-i-ramie')->assertOk()->assertDontSee('OrthoSleeve BR4')->assertSee('Temblak DR-E027');

    $this->artisan('catalogue:regroup-orthoses')->expectsOutputToContain('TO_MOVE=0')->assertSuccessful();

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->sole();

    $this->artisan('catalogue:regroup-orthoses', ['--rollback' => $backup])
        ->expectsOutputToContain('CATALOGUE_REGROUP_ROLLBACK=PASS')
        ->assertSuccessful();

    expect(DB::table('category_product')->orderBy('product_id')->orderBy('category_id')->get(['product_id', 'category_id', 'is_primary'])->map(fn ($row) => (array) $row)->all())
        ->toEqual($linksBefore);
});
