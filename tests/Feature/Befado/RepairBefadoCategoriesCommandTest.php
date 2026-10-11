<?php

declare(strict_types=1);

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function repairBefadoCategory(
    string $name,
    string $slug,
    ?Category $parent = null,
): Category {
    return Category::query()->create([
        'name' => $name,
        'slug' => $slug,
        'parent_id' => $parent?->id,
        'status' => CategoryStatus::ACTIVE,
    ]);
}

function repairBefadoProduct(string $source, int $number): Product
{
    return Product::query()->create([
        'name' => $source.' product '.$number,
        'slug' => $source.'-repair-test-'.$number,
        'external_source' => $source,
        'external_id' => (string) $number,
        'status' => ProductStatus::DRAFT,
    ]);
}

function repairBefadoAssign(
    Product $product,
    Category $category,
    bool $primary = false,
): void {
    $product->categories()->syncWithoutDetaching([
        $category->id => ['is_primary' => $primary],
    ]);
}

/**
 * @return array<string, mixed>
 */
function repairBefadoCatalogue(): array
{
    $root = repairBefadoCategory('Obuwie', 'obuwie');
    $supplier = repairBefadoCategory('Befado', 'befado');
    $site = repairBefadoCategory('Befado.pl', 'befadopl', $supplier);

    // Reproduce the circular hierarchy from the local database.
    $supplier->update(['parent_id' => $site->id]);

    $boys = repairBefadoCategory('Buty chłopięce', 'buty-chlopiece', $supplier);
    $girls = repairBefadoCategory('Buty dziewczęce', 'buty-dziewczece', $supplier);
    $women = repairBefadoCategory('Buty damskie', 'buty-damskie', $supplier);
    $men = repairBefadoCategory('Buty męskie', 'buty-meskie', $supplier);

    $shared = repairBefadoCategory('Sportowe', 'sportowe', $girls);
    $boysSports = repairBefadoCategory(
        'Sportowe',
        'befado-befadopl-befado-buty-chlopiece-sportowe',
        $boys
    );

    $groups = [
        [$boys, 122],
        [$girls, 130],
        [$women, 10],
        [$men, 8],
    ];

    $number = 0;
    $girlsSportsIds = [];

    foreach ($groups as [$group, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $number++;
            $product = repairBefadoProduct('befado', $number);

            repairBefadoAssign($product, $supplier);
            repairBefadoAssign($product, $site);
            repairBefadoAssign($product, $group);

            if ($group->id === $girls->id && $i < 31) {
                repairBefadoAssign($product, $shared, true);
                $girlsSportsIds[] = $product->id;
            } else {
                repairBefadoAssign($product, $group, true);
            }
        }
    }

    $footwaveIds = [];

    for ($i = 1; $i <= 3; $i++) {
        $product = repairBefadoProduct('footwave', $i);
        repairBefadoAssign($product, $shared, true);
        $footwaveIds[] = $product->id;
    }

    return compact(
        'root',
        'supplier',
        'site',
        'boys',
        'girls',
        'women',
        'men',
        'shared',
        'boysSports',
        'girlsSportsIds',
        'footwaveIds'
    );
}

it('repairs Befado categories while preserving FootWave and supports repeated writes', function (): void {
    $data = repairBefadoCatalogue();

    $this->artisan('befado:repair-categories')
        ->expectsOutputToContain('DATABASE_CHANGES=0')
        ->assertSuccessful();

    expect($data['supplier']->fresh()->parent_id)->toBe($data['site']->id)
        ->and(Category::query()->where('slug', 'obuwie')->count())->toBe(1);

    $this->artisan('befado:repair-categories', ['--write' => true])
        ->expectsOutputToContain('BEFADO_CATEGORY_REPAIR=PASS')
        ->assertSuccessful();

    foreach (['boys', 'girls', 'women', 'men'] as $key) {
        expect($data[$key]->fresh()->parent_id)->toBe($data['root']->id);
    }

    expect($data['supplier']->fresh()->parent_id)->toBeNull()
        ->and($data['site']->fresh()->parent_id)->toBeNull()
        ->and($data['shared']->fresh()->parent_id)->toBe($data['root']->id)
        ->and($data['boysSports']->fresh()->parent_id)->toBe($data['boys']->id);

    $girlsSports = Category::query()
        ->where('parent_id', $data['girls']->id)
        ->where('name', 'Sportowe')
        ->firstOrFail();

    expect($girlsSports->id)->not->toBe($data['shared']->id);

    $girlsPrimary = DB::table('category_product')
        ->where('category_id', $girlsSports->id)
        ->where('is_primary', true)
        ->whereIn('product_id', $data['girlsSportsIds'])
        ->count();

    $footwavePrimary = DB::table('category_product')
        ->where('category_id', $data['shared']->id)
        ->where('is_primary', true)
        ->whereIn('product_id', $data['footwaveIds'])
        ->count();

    expect($girlsPrimary)->toBe(31)
        ->and($footwavePrimary)->toBe(3);

    $supplierLinks = DB::table('category_product')
        ->whereIn('category_id', [
            $data['supplier']->id,
            $data['site']->id,
        ])
        ->count();

    expect($supplierLinks)->toBe(0);

    $firstState = DB::table('category_product')
        ->orderBy('product_id')
        ->orderBy('category_id')
        ->get(['product_id', 'category_id', 'is_primary'])
        ->toArray();

    $firstCategoryCount = Category::query()->count();

    // A second run must neither fail nor change category associations.
    $this->artisan('befado:repair-categories', ['--write' => true])
        ->expectsOutputToContain('BEFADO_CATEGORY_REPAIR=PASS')
        ->assertSuccessful();

    $secondState = DB::table('category_product')
        ->orderBy('product_id')
        ->orderBy('category_id')
        ->get(['product_id', 'category_id', 'is_primary'])
        ->toArray();

    expect($secondState)->toEqual($firstState)
        ->and(Category::query()->count())->toBe($firstCategoryCount)
        ->and(Product::query()->where('external_source', 'befado')->count())->toBe(270)
        ->and(Product::query()->where('external_source', 'footwave')->count())->toBe(3)
        ->and(Product::query()->where('status', ProductStatus::DRAFT)->count())->toBe(273);
});

it('rejects foreign category ownership without changing the database', function (): void {
    $data = repairBefadoCatalogue();

    // Deliberately contaminate a Befado-only group.
    $unrelated = repairBefadoProduct('footwave', 99);
    repairBefadoAssign($unrelated, $data['boys'], true);

    $beforeCategories = DB::table('categories')
        ->orderBy('id')
        ->get(['id', 'parent_id', 'slug'])
        ->toArray();

    $beforeAssignments = DB::table('category_product')
        ->orderBy('product_id')
        ->orderBy('category_id')
        ->get(['product_id', 'category_id', 'is_primary'])
        ->toArray();

    $this->artisan('befado:repair-categories', ['--write' => true])
        ->assertFailed();

    $afterCategories = DB::table('categories')
        ->orderBy('id')
        ->get(['id', 'parent_id', 'slug'])
        ->toArray();

    $afterAssignments = DB::table('category_product')
        ->orderBy('product_id')
        ->orderBy('category_id')
        ->get(['product_id', 'category_id', 'is_primary'])
        ->toArray();

    expect($afterCategories)->toEqual($beforeCategories)
        ->and($afterAssignments)->toEqual($beforeAssignments)
        ->and($data['supplier']->fresh()->parent_id)->toBe($data['site']->id)
        ->and($data['boys']->fresh()->parent_id)->toBe($data['supplier']->id);
});
