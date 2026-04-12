<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->skipUnlessFortifyFeature(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'test@outlook.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'street' => 'Main Street',
        'house_number' => '10',
        'apartment_number' => '5',
        'city' => 'Toruń',
        'postcode' => '87-100',
        'country' => 'PL',
        'phone_number' => '123456789',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertAuthenticated();
});
