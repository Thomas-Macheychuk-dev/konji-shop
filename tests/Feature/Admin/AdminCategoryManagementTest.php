<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createCategoryForAdminCategoryManagementTest(array $overrides = []): Category
{
    return Category::query()->create(array_merge([
        'name' => 'Odzież medyczna',
        'slug' => 'odziez-medyczna',
        'status' => CategoryStatus::ACTIVE,
        'description' => 'Opis kategorii.',
        'seo_title' => 'SEO kategorii',
        'seo_description' => 'Opis SEO kategorii.',
    ], $overrides));
}

it('shows categories menu option to admins', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this
        ->actingAs($admin)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Kategorie')
        ->assertSee(route('admin.categories.index'), false);
});

it('shows the admin categories index', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    createCategoryForAdminCategoryManagementTest();

    $this
        ->actingAs($admin)
        ->get(route('admin.categories.index'))
        ->assertOk()
        ->assertSee('Kategorie')
        ->assertSee('Odzież medyczna')
        ->assertSee('odziez-medyczna')
        ->assertSee('Stwórz kategorię');
});

it('allows an admin to create a category', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $parent = createCategoryForAdminCategoryManagementTest([
        'name' => 'Stabilizatory',
        'slug' => 'stabilizatory',
    ]);

    $this
        ->actingAs($admin)
        ->post(route('admin.categories.store'), [
            'parent_id' => $parent->id,
            'name' => 'Stabilizatory kolana',
            'slug' => '',
            'description' => 'Opis stabilizatorów kolana.',
            'status' => CategoryStatus::ACTIVE->value,
            'seo_title' => 'Stabilizatory kolana SEO',
            'seo_description' => 'Opis SEO stabilizatorów kolana.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('categories', [
        'parent_id' => $parent->id,
        'name' => 'Stabilizatory kolana',
        'slug' => 'stabilizatory-kolana',
        'status' => CategoryStatus::ACTIVE->value,
        'seo_title' => 'Stabilizatory kolana SEO',
    ]);
});

it('allows an admin to update a category', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $category = createCategoryForAdminCategoryManagementTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.categories.update', $category), [
            'parent_id' => '',
            'name' => 'Odzież medyczna damska',
            'slug' => 'odziez-medyczna-damska',
            'description' => 'Nowy opis.',
            'status' => CategoryStatus::ARCHIVED->value,
            'seo_title' => 'Nowy SEO title',
            'seo_description' => 'Nowy SEO description.',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'name' => 'Odzież medyczna damska',
        'slug' => 'odziez-medyczna-damska',
        'status' => CategoryStatus::ARCHIVED->value,
        'description' => 'Nowy opis.',
    ]);
});

it('allows an admin to archive a category', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $category = createCategoryForAdminCategoryManagementTest();

    $this
        ->actingAs($admin)
        ->patch(route('admin.categories.archive', $category))
        ->assertRedirect();

    expect($category->fresh()->status)->toBe(CategoryStatus::ARCHIVED);
});

it('allows an admin to delete an unused category', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $category = createCategoryForAdminCategoryManagementTest();

    $this
        ->actingAs($admin)
        ->delete(route('admin.categories.destroy', $category))
        ->assertRedirect(route('admin.categories.index'));

    $this->assertSoftDeleted('categories', [
        'id' => $category->id,
    ]);
});

it('does not delete a category that has products', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $category = createCategoryForAdminCategoryManagementTest();

    $product = Product::query()->create([
        'name' => 'Produkt testowy',
        'slug' => 'produkt-testowy',
        'status' => ProductStatus::ACTIVE,
    ]);

    $product->categories()->attach($category, [
        'is_primary' => true,
    ]);

    $this
        ->actingAs($admin)
        ->from(route('admin.categories.edit', $category))
        ->delete(route('admin.categories.destroy', $category))
        ->assertRedirect(route('admin.categories.edit', $category));

    $this->assertDatabaseHas('categories', [
        'id' => $category->id,
        'deleted_at' => null,
    ]);
});

it('prevents non-admin users from managing categories', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this
        ->actingAs($user)
        ->get(route('admin.categories.index'))
        ->assertForbidden();
});
