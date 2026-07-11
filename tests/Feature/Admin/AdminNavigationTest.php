<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the complete admin navigation for admins', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Panel admina')
        ->assertSee('Panel administracyjny')
        ->assertSee(route('admin.orders.index'), false)
        ->assertSee(route('admin.products.index'), false)
        ->assertSee(route('admin.categories.index'), false)
        ->assertSee(route('admin.withdrawals.index'), false)
        ->assertSee(route('admin.shop.readiness'), false);
});

it('does not show admin navigation for normal users', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('Panel admina')
        ->assertDontSee('Panel administracyjny')
        ->assertDontSee(route('admin.orders.index'), false)
        ->assertDontSee(route('admin.products.index'), false)
        ->assertDontSee(route('admin.categories.index'), false)
        ->assertDontSee(route('admin.withdrawals.index'), false)
        ->assertDontSee(route('admin.shop.readiness'), false);
});

it('keeps shipment tracking and withdrawal navigation visible to guests', function (): void {
    $this
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Śledzenie przesyłki')
        ->assertSee(route('guest.orders.track.show'), false)
        ->assertSee('Odstąp od umowy')
        ->assertSee(route('withdrawals.start'), false);
});

it('keeps customer account actions available to authenticated users', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Moje konto')
        ->assertSee('Moje zamówienia')
        ->assertSee('Dane konta')
        ->assertSee('Wyloguj się')
        ->assertSee(route('account.orders.index'), false)
        ->assertSee(route('account.details.show'), false)
        ->assertSee(route('logout'), false);
});
