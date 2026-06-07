<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows public legal pages', function (string $routeName, string $expectedText): void {
    $this
        ->get(route($routeName))
        ->assertOk()
        ->assertSee($expectedText);
})->with([
    ['legal.terms', 'Regulamin'],
    ['legal.privacy', 'Polityka prywatności'],
    ['legal.returns', 'Zwroty i odstąpienie od umowy'],
    ['legal.complaints', 'Reklamacje i gwarancja'],
    ['legal.delivery-payments', 'Dostawa i płatności'],
    ['legal.contact', 'Kontakt'],
    ['legal.cookie-policy', 'Polityka plików cookie'],
]);

it('shows legal footer links on the storefront', function (): void {
    $this
        ->get(route('home'))
        ->assertOk()
        ->assertSee(route('legal.terms'), false)
        ->assertSee(route('legal.privacy'), false)
        ->assertSee(route('legal.returns'), false)
        ->assertSee(route('legal.complaints'), false)
        ->assertSee(route('legal.delivery-payments'), false)
        ->assertSee(route('legal.contact'), false)
        ->assertSee(route('legal.cookie-policy'), false)
        ->assertSee('Regulamin', false)
        ->assertSee('Polityka prywatności')
        ->assertSee('Zwroty i odstąpienie od umowy', false)
        ->assertSee('Reklamacje i gwarancja', false)
        ->assertSee('Dostawa i płatności', false)
        ->assertSee('Polityka plików cookie');
});
