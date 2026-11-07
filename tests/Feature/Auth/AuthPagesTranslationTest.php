<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('links between sign-in, sign-up and password reset in Polish', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Nie masz konta?')
        ->assertSee('Zarejestruj się')
        ->assertDontSee("Don't have an account?")
        ->assertDontSee('Sign up');

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('Masz już konto?')
        ->assertDontSee('Already have an account?');

    $this->get(route('password.request'))
        ->assertOk()
        ->assertSee('Albo wróć do')
        ->assertDontSee('Or, return to');
});
