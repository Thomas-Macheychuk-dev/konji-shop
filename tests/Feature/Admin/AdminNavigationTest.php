<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the admin navigation link for admins', function (): void {
    $admin = User::factory()->create([
        'is_admin' => true,
    ]);

    $this->actingAs($admin)
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Administracja')
        ->assertSee(route('admin.orders.index'));
});

it('does not show the admin navigation link for normal users', function (): void {
    $user = User::factory()->create([
        'is_admin' => false,
    ]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertDontSee('Administracja')
        ->assertDontSee(route('admin.orders.index'));
});
