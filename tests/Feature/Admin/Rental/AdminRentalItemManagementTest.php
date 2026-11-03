<?php

use App\Models\RentalItem;
use App\Models\User;
use Database\Seeders\RentalItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function rentalAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

/**
 * @return array<string, mixed>
 */
function rentalItemPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Wózek toaletowy',
        'group' => 'wheelchairs',
        'details' => 'Z odpinanym wiadrem.',
        'deposit_min' => '150',
        'deposit_max' => '',
        'monthly_min' => '45',
        'monthly_max' => '60',
        'sort_order' => '',
        'is_active' => '1',
        'nfz_purchase' => '0',
    ], $overrides);
}

it('lists the rental equipment by group for admins', function (): void {
    $this->seed(RentalItemSeeder::class);
    RentalItem::query()->where('slug', 'rollator')->update(['is_active' => false]);

    $this->actingAs(rentalAdmin())
        ->get(route('admin.rental.index'))
        ->assertOk()
        ->assertSeeInOrder(array_values(RentalItem::GROUPS))
        ->assertSee('Rollator składany')
        ->assertSee('Ukryty')
        ->assertSee(route('admin.rental.edit', RentalItem::query()->where('slug', 'rollator')->sole()), false);
});

it('keeps rental management away from customers and guests', function (): void {
    $this->seed(RentalItemSeeder::class);
    $item = RentalItem::query()->firstOrFail();

    $this->get(route('admin.rental.index'))->assertRedirect(route('login'));

    $customer = User::factory()->create(['is_admin' => false]);

    $this->actingAs($customer)->get(route('admin.rental.index'))->assertForbidden();
    $this->actingAs($customer)->post(route('admin.rental.store'), rentalItemPayload())->assertForbidden();
    $this->actingAs($customer)->patch(route('admin.rental.update', $item), rentalItemPayload())->assertForbidden();
    $this->actingAs($customer)->delete(route('admin.rental.destroy', $item))->assertForbidden();

    expect(RentalItem::query()->where('name', 'Wózek toaletowy')->exists())->toBeFalse()
        ->and($item->fresh())->not->toBeNull();
});

it('adds equipment with an uploaded image and shows it on the rental page', function (): void {
    Storage::fake('public');
    RentalItem::query()->create(['slug' => 'istniejacy', 'group' => 'beds', 'name' => 'Łóżko', 'monthly_min' => 80, 'sort_order' => 30]);

    $this->actingAs(rentalAdmin())
        ->post(route('admin.rental.store'), rentalItemPayload([
            'image' => UploadedFile::fake()->image('wozek.png', 800, 600),
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $item = RentalItem::query()->where('name', 'Wózek toaletowy')->sole();

    expect($item->slug)->toBe('wozek-toaletowy')
        ->and($item->deposit_min)->toBe(150)
        ->and($item->deposit_max)->toBeNull()
        ->and($item->monthly_max)->toBe(60)
        ->and($item->sort_order)->toBe(40)
        ->and($item->is_active)->toBeTrue()
        ->and($item->image_path)->toStartWith('rental/');

    Storage::disk('public')->assertExists($item->image_path);

    $this->get(route('rental.index'))
        ->assertOk()
        ->assertSee('Wózek toaletowy')
        ->assertSee('45–60 zł')
        ->assertSee($item->imageUrl(), false);
});

it('gives a new item a unique slug', function (): void {
    RentalItem::query()->create(['slug' => 'wozek-toaletowy', 'group' => 'wheelchairs', 'name' => 'Wózek toaletowy', 'monthly_min' => 40]);

    $this->actingAs(rentalAdmin())->post(route('admin.rental.store'), rentalItemPayload())->assertSessionHasNoErrors();

    expect(RentalItem::query()->pluck('slug')->all())->toContain('wozek-toaletowy-2');
});

it('updates prices, visibility and the NFZ badge without changing the slug', function (): void {
    $this->seed(RentalItemSeeder::class);
    $item = RentalItem::query()->where('slug', 'rollator')->sole();

    $this->actingAs(rentalAdmin())
        ->from(route('admin.rental.edit', $item))
        ->patch(route('admin.rental.update', $item), rentalItemPayload([
            'name' => 'Rollator z siedziskiem',
            'group' => 'mobility',
            'deposit_min' => '',
            'monthly_min' => '40',
            'monthly_max' => '',
            'sort_order' => '5',
            'nfz_purchase' => '0',
        ]))
        ->assertRedirect(route('admin.rental.edit', $item))
        ->assertSessionHasNoErrors();

    $item->refresh();

    expect($item->slug)->toBe('rollator')
        ->and($item->name)->toBe('Rollator z siedziskiem')
        ->and($item->deposit_min)->toBeNull()
        ->and($item->depositLabel())->toBe('Bez kaucji')
        ->and($item->monthlyLabel())->toBe('40 zł')
        ->and($item->sort_order)->toBe(5)
        ->and($item->nfz_purchase)->toBeFalse()
        ->and($item->image_path)->toBe('images/rental/rollator.webp');

    $this->actingAs(rentalAdmin())
        ->patch(route('admin.rental.update', $item), rentalItemPayload(['is_active' => '0']))
        ->assertSessionHasNoErrors();

    $this->get(route('rental.index'))->assertOk()->assertDontSee('Wózek toaletowy');
});

it('replaces and removes uploaded images but never deletes seeded ones', function (): void {
    Storage::fake('public');
    $this->seed(RentalItemSeeder::class);
    $item = RentalItem::query()->where('slug', 'rollator')->sole();
    $admin = rentalAdmin();

    $this->actingAs($admin)
        ->patch(route('admin.rental.update', $item), rentalItemPayload(['image' => UploadedFile::fake()->image('a.jpg')]))
        ->assertSessionHasNoErrors();

    $firstUpload = $item->refresh()->image_path;
    Storage::disk('public')->assertExists($firstUpload);
    expect(public_path('images/rental/rollator.webp'))->toBeFile();

    $this->actingAs($admin)
        ->patch(route('admin.rental.update', $item), rentalItemPayload(['image' => UploadedFile::fake()->image('b.jpg')]))
        ->assertSessionHasNoErrors();

    Storage::disk('public')->assertMissing($firstUpload);
    $secondUpload = $item->refresh()->image_path;

    $this->actingAs($admin)
        ->patch(route('admin.rental.update', $item), rentalItemPayload(['remove_image' => '1']))
        ->assertSessionHasNoErrors();

    Storage::disk('public')->assertMissing($secondUpload);
    expect($item->refresh()->image_path)->toBeNull();
});

it('deletes equipment and its uploaded image', function (): void {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('x.png')->store('rental', 'public');
    $item = RentalItem::query()->create(['slug' => 'do-usuniecia', 'group' => 'beds', 'name' => 'Do usunięcia', 'monthly_min' => 10, 'image_path' => $path]);

    $this->actingAs(rentalAdmin())
        ->delete(route('admin.rental.destroy', $item))
        ->assertRedirect(route('admin.rental.index'));

    expect($item->fresh())->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('validates prices and the group', function (array $overrides, string $errorField): void {
    $this->actingAs(rentalAdmin())
        ->post(route('admin.rental.store'), rentalItemPayload($overrides))
        ->assertSessionHasErrors($errorField);

    expect(RentalItem::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'unknown group' => [['group' => 'boats'], 'group'],
    'missing monthly fee' => [['monthly_min' => ''], 'monthly_min'],
    'monthly max below min' => [['monthly_min' => '50', 'monthly_max' => '40'], 'monthly_max'],
    'deposit max without min' => [['deposit_min' => '', 'deposit_max' => '200'], 'deposit_max'],
    'negative deposit' => [['deposit_min' => '-5'], 'deposit_min'],
    'not an image' => [['image' => UploadedFile::fake()->create('cennik.pdf', 10, 'application/pdf')], 'image'],
]);
