<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows public legal pages', function (string $routeName, string $expectedText): void {
    $this
        ->get(route($routeName))
        ->assertOk()
        ->assertSee($expectedText);
})->with([
    ['legal.terms', 'Terms and Conditions'],
    ['legal.privacy', 'Privacy Policy'],
    ['legal.returns', 'Returns and Withdrawal'],
    ['legal.complaints', 'Complaints and Warranty'],
    ['legal.delivery-payments', 'Delivery and Payments'],
    ['legal.contact', 'Contact'],
    ['legal.cookie-policy', 'Cookie'],
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
        ->assertSee('Terms & conditions', false)
        ->assertSee('Privacy policy')
        ->assertSee('Returns & withdrawal', false)
        ->assertSee('Complaints & warranty', false)
        ->assertSee('Delivery & payments', false)
        ->assertSee('Cookie policy');
});
