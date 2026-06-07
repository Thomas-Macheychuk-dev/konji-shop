<?php

use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('guests can visit the home page', function () {
    $this
        ->get(route('home'))
        ->assertOk();
});

test('authenticated users can visit the home page', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('home'))
        ->assertOk();
});
